<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Factory as RpcFactory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use WyriHaximus\React\ChildProcess\Pool\Factory\Flexible as FlexiblePoolFactory;
use WyriHaximus\React\ChildProcess\Pool\Options;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
use WyriHaximus\React\ChildProcess\Pool\Worker as PoolWorker;
use function React\Promise\resolve;

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

    /** @var PoolInterface */
    protected $pool;

    /** @var ProgressReporter[] */
    protected $watchingTasks = [];

    /** @var Configuration */
    protected $configuration;

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

        FlexiblePoolFactory::createFromClass(
            Worker::class,
            $loop,
            [
                Options::MIN_SIZE => $configuration->getMinProcesses(),
                Options::MAX_SIZE => $configuration->getMaxProcesses(),
            ]
        )->then([$this, 'initializePool']);
    }

    protected function initializePool(PoolInterface $pool)
    {
        $this->pool = $pool;
        $this->loop->addPeriodicTimer(1, [$this, 'checkQueue']);

        // hack worker on other implementation
        $workerReflection  = new \ReflectionClass(PoolWorker::class);
        $messengerProperty = $workerReflection->getProperty('messenger');
        $pool->on(
            'worker',
            // always expect the concrete definition for this hack, so it bails out in case library implementation changes
            function (PoolWorker $worker) use ($messengerProperty) {
                /** @var Messenger $messenger */
                $messenger = $messengerProperty->getValue($worker);

                // register additional RPCs
                $this->registerRpc($messenger);
            }
        );
    }

    protected function registerRpc(Messenger $messenger)
    {
        $messenger->registerRpc('task-report', [$this, 'handleProgressReport']);
    }

    protected function handleProgressReport(Payload $payload)
    {
        $report = $payload['report'];
        $report = unserialize($report);
        if ($report instanceof ProgressReporter) {
            $task = $report->getTask();
            if (isset($this->watchingTasks[$task->getId()])) {
                $this->watchingTasks[$task->getId()]->merge($report);
            }
        }

        return resolve([]);
    }

    protected function checkQueue()
    {
        while (($task = $this->queue->dequeue()) !== null) {
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


        $this->pool->rpc(RpcFactory::rpc('submit-task', ['task' => serialize($task)]))->otherwise(
            function ($error) use ($task) {
                // TODO : Report internal exception
                $this->queue->refund($task);
                unset($this->watchingTasks[$task->getId()]); // this is just a refund
            }
        );

        $this->watchingTasks[$task->getId()] = $progressReporter;
    }

    protected function clearTask(TaskInterface $task)
    {
        $this->removeAllListeners('task-completed-' . $task->getId());
        $this->removeAllListeners('task-failed-' . $task->getId());
        $this->removeAllListeners('task-progress-' . $task->getId());
        unset($this->watchingTasks[$task->getId()]);
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