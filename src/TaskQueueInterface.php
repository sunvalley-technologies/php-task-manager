<?php

namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;

/** Implementations should be async */
interface TaskQueueInterface
{

    /**
     * Get the loop for task queue
     *
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface;

    /**
     * Get the task storage interface. May be NULL if no task storage is defined.
     *
     * @return TaskStorageInterface|null
     */
    public function getTaskStorage(): ?TaskStorageInterface;

    /**
     * Add the given task to this queue. If a task storage is set, also calls to insert the task to storage when task
     * is accepted successfully.
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface Should return a promise for result of the action. Promise should be resolved
     *                                  when task is submitted to queue with no value while if it fails it should be
     *                                  rejected.
     */
    public function enqueue(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Add the given task to this queue using the queues remote interface. If a task storage is set, also calls to
     * insert the task to storage when task is accepted successfully.
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface Should return a promise for result of the action. Promise should be resolved
     *                                  when task is submitted to queue with no value while if it fails it should be
     *                                  rejected.
     */
    public function enqueueRemote(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Gets a task from this queue. This method should not block.
     *
     * @param bool $asyncOnly If given TRUE, this dequeue should only return an async task.
     *
     * @return ExtendedPromiseInterface<?TaskInterface> Resolves when a task is dequeue and receives task as value or
     *                                                  rejection if an error occurred with a task. If there are no
     *                                                  tasks in queue, NULL is passed as value.
     */
    public function dequeue(bool $asyncOnly = false): ExtendedPromiseInterface;

    /**
     * Cancel the given task. This does not stop the execution of the task if it is running.
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface Should return a promise to report if cancel is successful or not
     */
    public function cancel(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Cancel the given task from remote interface. This does not stop the execution of the task if it is running.
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface Should return a promise to report if cancel is successful or not
     */
    public function cancelRemote(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Method that informs queue that a task is completed with success
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface
     */
    public function complete(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Method that informs queue that a task is completed with failure
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface
     */
    public function fail(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Refunds the given task to the queue
     *
     * @param TaskInterface $task
     *
     * @return ExtendedPromiseInterface Should return a promise to report if refund is successful or not
     * @internal Should only be used by library methods
     */
    public function refund(TaskInterface $task): ExtendedPromiseInterface;

    /**
     * Each queue should define an event callback to pass when a task becomes available in this queue.
     *
     * @param callable $callback
     */
    public function onAvailableTask(callable $callback): void;

    /**
     * Returns the count of tasks in queue
     *
     * @return int
     */
    public function count(): int;

    /**
     * Close the queue. This is called when the task manager is getting closed. Can be used to clean-up.
     *
     * @return ExtendedPromiseInterface
     */
    public function close(): ExtendedPromiseInterface;

    /**
     * Returns an array which keys should be one of Stats class constant.
     *
     * @return array
     */
    public function info(): array;

    /**
     * Start the queue operations before dequeue
     */
    public function start(): void;
}