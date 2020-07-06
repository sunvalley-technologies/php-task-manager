<?php


namespace SunValley\TaskManager;


use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

interface TaskStorageInterface
{

    /**
     * Get the loop for task queue
     *
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface;

    /**
     * Find and return the task from storage by its identifier and returns a promise for the result
     *
     * @param string $taskId
     *
     * @return PromiseInterface<ProgressReporter>
     */
    public function findById(string $taskId): PromiseInterface;


    /**
     * Find tasks by their status
     *
     * @param bool $finished
     * @param int  $offset
     * @param int  $limit
     *
     * @return PromiseInterface|PromiseInterface<ProgressReporter[]>
     */
    public function findByStatus(bool $finished, int $offset, int $limit): PromiseInterface;

    /**
     * Find and return the count of total tasks in this storage
     *
     * @return PromiseInterface<int>
     */
    public function count(): PromiseInterface;

    /**
     * Update the given task to this storage
     *
     * @param ProgressReporter $reporter
     *
     * @return PromiseInterface<void>
     */
    public function update(ProgressReporter $reporter): PromiseInterface;

    /**
     * Insert given task to the storage
     *
     * @param TaskInterface $task
     *
     * @return PromiseInterface<void>
     */
    public function insert(TaskInterface $task): PromiseInterface;

    /**
     * Mark task as cancelled
     *
     * @param TaskInterface $task
     *
     * @return PromiseInterface<void>
     */
    public function cancel(TaskInterface $task): PromiseInterface;

    /**
     * Delete the given task from this storage
     *
     * @param string $taskId
     *
     * @return PromiseInterface<void>
     */
    public function delete(string $taskId): PromiseInterface;
}