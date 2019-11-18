<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use SunValley\TaskManager\Exception\TaskQueueRetryException;
use WyriHaximus\FileDescriptors\Factory as FDFactory;
use SunValley\TaskManager\PoolOptions as Options;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollection\Single;

/**
 * Class TaskManager
 *
 * Emits `init` event when this manager is ready for work. Payload is manager itself.
 *
 * Emits following events for tasks. Each of these events receive a ProgressReporter as their payload.
 * task-completed, task-failed, task-progress, task-completed-{taskId}, task-failed-{taskId}, task-progress-{taskId}
 *
 *
 *
 * @package SunValley\TaskManager
 */
class TaskManager extends EventEmitter
{

    /** @var LoopInterface */
    protected $loop;

    /** @var TaskQueueInterface */
    protected $queue;

    /** @var Pool */
    protected $pool;

    /** @var Configuration */
    protected $configuration;

    /** @var TimerInterface */
    protected $queueTimer;

    /** @var bool */
    protected $idleQueueTimer = true;

    /** @var TaskStorageInterface|null */
    private $storage;

    /**
     * TaskManager constructor.
     *
     * @param LoopInterface             $loop
     * @param TaskQueueInterface        $queue
     * @param Configuration             $configuration
     * @param TaskStorageInterface|null $storage
     */
    public function __construct(
        LoopInterface $loop,
        TaskQueueInterface $queue,
        Configuration $configuration,
        ?TaskStorageInterface $storage = null
    ) {
        $this->loop          = $loop;
        $this->queue         = $queue;
        $this->configuration = $configuration;
        $this->storage       = $storage;
        $this->queue->onAvailableTask(\Closure::fromCallable([$this, 'checkQueue']));

        // Setup process collection
        $processCollection = new Single(new ProcessLauncher());

        // Setup options
        /** @noinspection PhpUnhandledExceptionInspection */
        $options = [
            Options::MIN_SIZE             => $configuration->getMinProcesses(),
            Options::MAX_SIZE             => $configuration->getMaxProcesses(),
            Options::TTL                  => $configuration->getTtl(),
            Options::FD_LISTER            => FDFactory::create(),
            Options::MAX_JOBS_PER_PROCESS => $configuration->getMaxJobsPerProcess(),
        ];

        $this->pool = new Pool($processCollection, $loop, $options);
        $this->setIdleQueueTimer();
        $queue->start();
    }

    protected function setIdleQueueTimer()
    {
        if ($this->idleQueueTimer && $this->queueTimer !== null) {
            return;
        }

        $this->setQueueTimer(1);
    }

    protected function setBusyQueueTimer()
    {
        if (!$this->idleQueueTimer && $this->queueTimer !== null) {
            return;
        }

        $this->setQueueTimer(.1);
    }

    protected function setQueueTimer(float $interval)
    {
        if ($this->queueTimer !== null) {
            $this->loop->cancelTimer($this->queueTimer);
        }

        $this->queueTimer = $this->loop->addPeriodicTimer(
            $interval,
            function () {
                $this->checkQueue();
            }
        );
    }

    protected function checkQueue()
    {
        if (!$this->pool->canProcessAsyncTask() && $this->queue->count()) {
            $this->setBusyQueueTimer();
            $this->pool->ping();

            return;
        }

        $this->queue->dequeue($this->pool->canProcessSyncTask() === false)->otherwise(
            function (?\Throwable $exception) {
                if ($exception instanceof TaskQueueRetryException) {
                    $this->checkQueue();
                }

                // TODO: Report problem somewhere ?
            }
        )->done(
            function (?TaskInterface $task) {
                if ($task === null) {
                    $this->setIdleQueueTimer();
                } else {
                    $this->handleTask($task);
                }
            }
        );
    }

    protected function handleTask(TaskInterface $task)
    {
        $progressReporter = new ProgressReporter($task);
        $progressReporter->on(
            'change',
            function (ProgressReporter $reporter) {
                $task = $reporter->getTask();

                $reporter = clone $reporter;
                $this->storage !== null && $this->storage->update($reporter);
                $this->emit('task-progress-' . $task->getId(), [$reporter]);
                $this->emit('task-progress', [$reporter]);
            }
        );
        $progressReporter->on(
            'done',
            function (ProgressReporter $reporter) {
                $task = $reporter->getTask();
                $this->queue->complete($task);

                $reporter = clone $reporter;
                $this->storage !== null && $this->storage->update($reporter);
                $this->emit('task-completed-' . $task->getId(), [$reporter]);
                $this->emit('task-completed', [$reporter]);

                $this->clearTask($task);
            }
        );
        $progressReporter->on(
            'failed',
            function (ProgressReporter $reporter) {
                $task = $reporter->getTask();
                $this->queue->fail($task);

                $reporter = clone $reporter;
                $this->storage !== null && $this->storage->update($reporter);
                $this->emit('task-failed-' . $task->getId(), [$reporter]);
                $this->emit('task-failed', [$reporter]);

                $this->clearTask($task);
            }
        );

        $this->pool->submitTask($progressReporter)
                   ->otherwise(
                       function ($error) use ($task) {
                           // TODO : Report internal exception
                           $this->queue->refund($task);
                           $this->clearTask($task);
                       }
                   );
    }

    protected function clearTask(TaskInterface $task)
    {
        $this->removeAllListeners('task-completed-' . $task->getId());
        $this->removeAllListeners('task-failed-' . $task->getId());
        $this->removeAllListeners('task-progress-' . $task->getId());
    }


    /**
     * Submit task task to this manager. Events or returned promise can be used to track the status of the task.
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface Returns a promise that resolves with a ProgressReporter interface which
     *                                  indicates both failure and success on completion or failure. Promise is
     *                                  rejected when task is failed to be submitted to queue not when a task is failed.
     */
    public function submitTask(TaskInterface $task): ExtendedPromiseInterface
    {
        $deferred = new Deferred(
            function () use ($task) {
                return $this->queue->cancel($task);
            }
        );
        try {
            foreach (['task-completed-' . $task->getId(), 'task-failed-' . $task->getId()] as $event) {
                $this->once(
                    $event,
                    function (ProgressReporter $reporter) use ($deferred) {
                        $deferred->resolve($reporter);
                    }
                );
            }

            $this->queue->enqueue($task)->otherwise(
                function (\Throwable $error) use ($task, $deferred) {
                    $this->clearTask($task);
                    $deferred->reject($error);
                }
            );
        } catch (Exception\TaskQueueException $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    public function terminate()
    {
        $this->queueTimer !== null && $this->loop->cancelTimer($this->queueTimer);
        $this->queue->close();
        $this->pool->terminate();
    }

    public function stats(): array
    {
        return [
            Stats::_GROUP_POOL  => $this->pool->info(),
            Stats::_GROUP_QUEUE => $this->queue->info(),
        ];
    }
}