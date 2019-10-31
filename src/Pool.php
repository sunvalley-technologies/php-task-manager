<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\Exception\PoolException;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Message;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use SunValley\TaskManager\PoolOptions as Options;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollectionInterface;
use function React\Promise\all;
use function React\Promise\reject;
use function WyriHaximus\React\timedPromise;

/** @internal */
class Pool extends EventEmitter
{

    /** @var array */
    protected $defaultOptions = [
        Options::MIN_SIZE             => 1,
        Options::MAX_SIZE             => 4,
        Options::TTL                  => 60,
        Options::MAX_JOBS_PER_PROCESS => 10,
    ];

    /** @var PoolWorker[] */
    protected $workers = [];

    /** @var ProcessCollectionInterface */
    protected $processCollection;

    /** @var LoopInterface */
    protected $loop;

    /** @var array */
    protected $options;

    /** @var int */
    protected $startingProcesses = 0;

    /** @var array */
    private $stats = [
        Stats::FAILED_TASKS    => 0,
        Stats::COMPLETED_TASKS => 0,
        Stats::TOTAL_TASKS     => 0,
        Stats::MAX_PROCESSES   => 0,
    ];

    /**
     * @param ProcessCollectionInterface $processCollection
     * @param LoopInterface              $loop
     * @param array                      $options
     */
    public function __construct(ProcessCollectionInterface $processCollection, LoopInterface $loop, array $options = [])
    {
        $this->options = array_merge($this->defaultOptions, $options);
        if ($this->options[Options::MIN_SIZE] <= 0) {
            $this->options[Options::MIN_SIZE] = 1;
        }
        $this->processCollection = $processCollection;
        $this->loop              = $loop;
    }

    /**
     * Submit a task and return a promise for the sent task.
     *
     * @param ProgressReporter $reporter
     *
     * @return ExtendedPromiseInterface|PromiseInterface Promise is resolved when the job is submitted. Not when the
     *                                                   job is completed.
     */
    public function submitTask(ProgressReporter $reporter): PromiseInterface
    {
        // bind for statistics
        $reporter->on('done', \Closure::fromCallable([$this, 'handleCompletedTask']));
        $reporter->on('failed', \Closure::fromCallable([$this, 'handleFailedTask']));

        return $this->__submitTask($reporter);
    }

    protected function __submitTask(ProgressReporter $reporter)
    {
        $async = $reporter->getTask() instanceof LoopAwareInterface;
        foreach ($this->workers as $worker) {
            if ($async && !$worker->isBusy()) {
                return $worker->submitTask($reporter);
            } elseif (!$async && !$worker->taskCount()) {
                return $worker->submitTask($reporter);
            }
        }

        $workerPromise = $this->ping();
        if ($workerPromise !== null) {
            return $workerPromise->then(
                function () use ($reporter) {
                    return $this->__submitTask($reporter);
                },
                function ($e) {
                    return reject(
                        new PoolException(sprintf('No free worker available for the task! Message: %s', (string)$e))
                    );
                }
            );
        }

        return reject(new PoolException('No free worker available for the task!'));
    }

    protected function handleCompletedTask(ProgressReporter $reporter)
    {
        $this->stats[Stats::COMPLETED_TASKS]++;
        $this->stats[Stats::TOTAL_TASKS]++;
    }

    protected function handleFailedTask(ProgressReporter $reporter)
    {
        $this->stats[Stats::FAILED_TASKS]++;
        $this->stats[Stats::TOTAL_TASKS]++;
    }

    /** @inheritDoc */
    public function terminate($timeout = 5)
    {
        return timedPromise($this->loop, $timeout)->then(
            function () {
                $promises = [];

                foreach ($this->workers as $worker) {
                    $promises[] = $worker->terminate();
                }

                return all($promises);
            }
        );
    }

    /**
     * @return array
     */
    public function info()
    {
        $stats = [
                Stats::CURRENT_PROCESSES => count($this->workers),
                Stats::CURRENT_TASKS     => array_sum(
                    array_map(
                        function (PoolWorker $worker) {
                            return $worker->taskCount();
                        },
                        $this->workers
                    )
                ),
            ] + $this->stats;

        return $stats;
    }

    /**
     * @return ExtendedPromiseInterface|null Returns spawn promise or null if no process is going to be started
     */
    public function ping(): ?ExtendedPromiseInterface
    {
        if ($this->canProcessSyncTask()) {
            return null;
        }

        if (count($this->workers) + $this->startingProcesses < $this->options[Options::MIN_SIZE]) {
            return $this->spawn();
        }

        if (count($this->workers) + $this->startingProcesses < $this->options[Options::MAX_SIZE]) {
            return $this->spawn();
        }

        return null;
    }
    
    protected function spawn(): ?ExtendedPromiseInterface
    {
        $this->startingProcesses++;
        $current = $this->processCollection->current();
        $promise = $this->spawnAndGetMessenger($current);
        $promise->done(
            function (Messenger $messenger) {
                $this->buildWorker($messenger);

                return $this;
            },
            function () {
                $this->ping();
            }
        );

        $this->processCollection->next();
        if (!$this->processCollection->valid()) {
            $this->processCollection->rewind();
        }

        return $promise;
    }

    protected function buildWorker(Messenger $messenger)
    {
        $worker = new PoolWorker($messenger);
        $worker->setOptions(
            [
                Options::MAX_JOBS_PER_PROCESS => $this->options[Options::MAX_JOBS_PER_PROCESS],
                Options::TTL                  => $this->options[Options::TTL],
            ]
        );
        $worker->on('done', \Closure::fromCallable([$this, 'ttl']));
        $worker->on(
            'terminating',
            function (PoolWorker $worker) {
                foreach ($this->workers as $key => $value) {
                    if ($worker === $value) {
                        unset($this->workers[$key]);
                        break;
                    }
                }
            }
        );
        $this->workers[] = $worker;
        $workerCount     = count($this->workers);
        if ($this->stats[Stats::MAX_PROCESSES] < $workerCount) {
            $this->stats[Stats::MAX_PROCESSES] = $workerCount;
        }

        $this->startingProcesses--;

        return $worker;
    }

    protected function spawnAndGetMessenger(callable $current): ExtendedPromiseInterface
    {
        return $current($this->loop, $this->options)->then(
            function ($timeoutOrMessenger) use ($current) {
                if ($timeoutOrMessenger instanceof Messenger) {
                    return \React\Promise\resolve($timeoutOrMessenger);
                }

                return $this->spawnAndGetMessenger($current);
            }
        );
    }

    protected function ttl(PoolWorker $worker)
    {
        $stop = time() + (int)$this->options[Options::TTL];
        $this->loop->addPeriodicTimer(
            1,
            function ($timer) use ($worker, $stop) {
                if ($worker->taskCount()) {
                    $this->loop->cancelTimer($timer);

                    return;
                }

                if ($stop <= time()) {
                    $this->loop->cancelTimer($timer);
                    $worker->terminate();

                    return;
                }
            }
        );
    }

    public function canProcessSyncTask()
    {
        foreach ($this->workers as $worker) {
            if ($worker->taskCount() === 0) {
                return true;
            }
        }

        return false;
    }

    public function canProcessAsyncTask()
    {
        foreach ($this->workers as $worker) {
            if ($worker->isBusy() === false) {
                return true;
            }
        }

        return false;
    }
    
}