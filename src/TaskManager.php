<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use WyriHaximus\FileDescriptors\Factory as FDFactory;
use WyriHaximus\React\ChildProcess\Pool\Launcher\ClassName;
use SunValley\TaskManager\PoolOptions as Options;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
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

    /**
     * TaskManager constructor.
     *
     * @param LoopInterface      $loop
     * @param TaskQueueInterface $queue
     * @param Configuration      $configuration
     */
    public function __construct(LoopInterface $loop, TaskQueueInterface $queue, Configuration $configuration)
    {
        $this->loop          = $loop;
        $this->queue         = $queue;
        $this->configuration = $configuration;
        $this->queue->onAvailableTask([$this, 'checkQueue']);

        // Setup process collection
        $processCollection = new Single(new ClassName(Process::class));

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
        $this->loop->addPeriodicTimer(1, [$this, 'checkQueue']);
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

        $this->queueTimer = $this->loop->addPeriodicTimer($interval, [$this, 'checkQueue']);
    }

    protected function checkQueue()
    {
        if (!$this->pool->canProcessAsyncTask() && $this->queue->count()) {
            $this->setBusyQueueTimer();

            return;
        }

        while (($task = $this->queue->dequeue($this->pool->canProcessSyncTask() === false)) !== null) {
            $this->handleTask($task);
        }
    }

    protected function handleTask(TaskInterface $task)
    {
        if (isset($this->watchingTasks[$task->getId()])) {
            // skip already watched tasks although should not be happening
            return;
        }

        $progressReporter = new ProgressReporter($task);
        $progressReporter->on(
            'change',
            function (ProgressReporter $reporter) {
                $task = $reporter->getTask();

                $reporter = clone $reporter;
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
     * @return ExtendedPromiseInterface Returns a promise that resolves with a ProgressReporter interface.
     */
    public function submitTask(TaskInterface $task): ExtendedPromiseInterface
    {
        $deferred = new Deferred(
            function () use ($task) {
                return $this->queue->cancel($task);
            }
        );
        try {
            $this->queue->enqueue($task)->done(
                function () use ($deferred, $task) {
                    foreach (['task-completed-' . $task->getId(), 'task-failed-' . $task->getId()] as $event) {
                        $this->once(
                            $event,
                            function (ProgressReporter $reporter) use ($deferred) {
                                $deferred->resolve($reporter);
                            }
                        );
                    }
                },
                function (\Throwable $error) use ($deferred) {
                    $deferred->reject($error);
                }
            );
        } catch (Exception\TaskQueueException $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

}