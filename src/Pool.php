<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use SunValley\TaskManager\Exception\PoolException;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Message;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Rpc;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use SunValley\TaskManager\PoolOptions as Options;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollectionInterface;
use function React\Promise\all;
use function React\Promise\reject;
use function WyriHaximus\React\timedPromise;

/** @internal */
class Pool extends EventEmitter implements PoolInterface
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

    /** @inheritDoc */
    public function rpc(Rpc $message)
    {
        throw new \BadMethodCallException('This method is not supported for this pool. Use submitTask method instead.');
    }

    /**
     * Submit a task and return a promise for the sent task.
     *
     * @param ProgressReporter $reporter
     *
     * @return ExtendedPromiseInterface Promise is resolved when the job is submitted. Not when the job is completed.
     */
    public function submitTask(ProgressReporter $reporter): ExtendedPromiseInterface
    {
        // bind for statistics
        $reporter->on(
            'done',
            function () {
                $this->stats[Stats::COMPLETED_TASKS]++;
                $this->stats[Stats::TOTAL_TASKS]++;
            }
        );
        $reporter->on(
            'failed',
            function () {
                $this->stats[Stats::FAILED_TASKS]++;
                $this->stats[Stats::TOTAL_TASKS]++;
            }
        );

        $async = $reporter->getTask() instanceof LoopAwareInterface;
        foreach ($this->workers as $worker) {
            if ($async && !$worker->isBusy()) {
                return $worker->submitTask($reporter);
            } elseif (!$async && !$worker->taskCount()) {
                return $worker->submitTask($reporter);
            }
        }

        return reject(new PoolException('No free worker available for the task!'));
    }

    /** @inheritDoc */
    public function message(Message $message)
    {
        throw new \BadMethodCallException('This method is not supported for this pool.');
    }

    /** @inheritDoc */
    public function terminate(Message $message, $timeout = 5, $signal = null)
    {
        if ($message !== null) {
            $this->message($message);
        }

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
        return $this->stats;
    }

    public function ping(): bool
    {
        if ($this->canProcessSyncTask()) {
            return true;
        }

        if (count($this->workers) + $this->startingProcesses < $this->options[Options::MIN_SIZE]) {
            $this->spawn();

            return false;
        }

        if (count($this->workers) + $this->startingProcesses < $this->options[Options::MAX_SIZE]) {
            $this->spawn();
        }

        return false;
    }

    protected function spawn()
    {
        $this->startingProcesses++;
        $current = $this->processCollection->current();
        $promise = $this->spawnAndGetMessenger($current);
        $promise->done(
            function (Messenger $messenger) {
                $worker = new PoolWorker($messenger);
                $worker->setOptions(
                    [
                        Options::MAX_JOBS_PER_PROCESS => $this->options[Options::MAX_JOBS_PER_PROCESS],
                        Options::TTL                  => $this->options[Options::TTL],
                    ]
                );
                $worker->on('done', [$this, 'ttl']);
                $this->workers[] = $worker;
                $workerCount     = count($this->workers);
                if ($this->stats[Stats::MAX_PROCESSES] < $workerCount) {
                    $this->stats[Stats::MAX_PROCESSES] = $workerCount;
                }

                $this->startingProcesses--;
            },
            function () {
                $this->ping();
            }
        );

        $this->processCollection->next();
        if (!$this->processCollection->valid()) {
            $this->processCollection->rewind();
        }
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