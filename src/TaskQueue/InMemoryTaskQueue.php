<?php

namespace SunValley\TaskManager\TaskQueue;

use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use SunValley\TaskManager\Exception\TaskQueueException;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\TaskQueueInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

class InMemoryTaskQueue implements TaskQueueInterface
{

    /** @var TaskInterface[] */
    private $queue = [];

    /** @var TaskInterface[] */
    private $processingQueue = [];

    /** @var TaskInterface[] */
    private $failedTasks = [];

    /** @var LoopInterface */
    private $loop;

    /** @var callable */
    private $onAvailableCallback;

    /**
     * InMemoryTaskQueue constructor.
     *
     * @param LoopInterface $loop
     */
    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /** @inheritDoc */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /** @inheritDoc */
    public function enqueue(TaskInterface $task): ExtendedPromiseInterface
    {
        if (isset($this->queue[$task->getId()])) {
            return reject(new TaskQueueException('Task already exists in this queue'));
        }

        $this->queue[$task->getId()] = $task;
        $this->onAvailableCallback !== null && $this->getLoop()->futureTick($this->onAvailableCallback);

        return resolve();
    }

    /** @inheritDoc */
    public function dequeue(): ?TaskInterface
    {
        $task = array_shift($this->queue);
        $task !== null && $this->processingQueue[$task->getId()] = $task;

        return $task;
    }

    /** @inheritDoc */
    public function cancel(TaskInterface $task): ExtendedPromiseInterface
    {
        if (isset($this->processingQueue[$task->getId()])) {
            return reject(new TaskQueueException('Task is already running.'));
        }

        if (!isset($this->queue[$task->getId()])) {
            return reject(new TaskQueueException('Task does not exist in this queue'));
        }

        unset($this->queue[$task->getId()]);

        return resolve();
    }

    /** @inheritDoc */
    public function complete(TaskInterface $task): ExtendedPromiseInterface
    {
        if (isset($this->processingQueue[$task->getId()])) {
            unset($this->processingQueue[$task->getId()]);
        }

        return resolve();
    }

    /** @inheritDoc */
    public function fail(TaskInterface $task): ExtendedPromiseInterface
    {
        $this->failedTasks[] = $task;

        return $this->complete($task);
    }

    /** @inheritDoc */
    public function refund(TaskInterface $task): ExtendedPromiseInterface
    {
        unset($this->processingQueue[$task->getId()]);
        array_unshift($this->queue, $task);

        return resolve();
    }

    /** @inheritDoc */
    public function onAvailableTask(callable $callback): void
    {
        $this->onAvailableCallback = $callback;
    }
}