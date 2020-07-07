<?php


namespace SunValley\TaskManager;

use React\Promise\PromiseInterface;
use function Clue\React\Block\await;

/**
 * Class Client is the class that should be exposed to remote task submitter. This class provides blocking methods along
 * with asyncronous methods.
 *
 * @package SunValley\TaskManager
 */
class Client
{

    /** @var TaskQueueInterface */
    private $queue;

    /** @var TaskStorageInterface */
    private $storage;

    public function __construct(TaskQueueInterface $queue, ?TaskStorageInterface $storage = null)
    {
        $this->queue   = $queue;
        $this->storage = $storage;
    }

    /**
     * Submit a task to the task manager.
     *
     * @param TaskInterface $task
     *
     * @return PromiseInterface Promise resolves when the task is submitted
     */
    public function submitTask(TaskInterface $task): PromiseInterface
    {
        return $this->queue->enqueueRemote($task);
    }

    /**
     * Submit a task and wait until it is delivered.
     *
     * @param TaskInterface $task
     *
     * @throws \Exception When task submission is failed
     */
    public function submitTaskSync(TaskInterface $task): void
    {
        await($this->submitTask($task), $this->queue->getLoop());
    }

    /**
     * Cancel a task. Tasks that are running cannot be canceled.
     *
     * @param TaskInterface $task
     *
     * @return PromiseInterface
     */
    public function cancelTask(TaskInterface $task): PromiseInterface
    {
        return $this->queue->cancelRemote($task);
    }

    /**
     * Cancel a task and wait until the confirmation is done.
     *
     * @param TaskInterface $task
     *
     * @throws \Exception When cancellation is failed
     */
    public function cancelTaskSync(TaskInterface $task)
    {
        await($this->cancelTask($task), $this->queue->getLoop());
    }

    /**
     * Check a task status that is stored in the storage. Requires a storage or throws an exception
     *
     * @param string $taskId
     *
     * @return PromiseInterface<ProgressReporter> Returns a promise that resolves with ProgressReporter instance that
     *                                            is stored in the task storage
     * @throws \RuntimeException Thrown when no storage is detected
     */
    public function checkTaskStatus(string $taskId): PromiseInterface
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return $this->storage->findById($taskId);
    }

    /**
     * Check a task status that is stored in the storage. Requires a storage or throws an exception.
     *
     * @param string $taskId
     *
     * @return ProgressReporter Returns a promise that resolves with ProgressReporter instance that is stored in the
     *                          task storage
     * @throws \RuntimeException Thrown when no storage is detected
     */
    public function checkTaskStatusSync(string $taskId): ProgressReporter
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return await($this->checkTaskStatus($taskId), $this->storage->getLoop());
    }

    /**
     * Find tasks by status
     *
     * @param bool $finished False for progressing tasks, true for finished tasks
     * @param int  $offset
     * @param int  $limit
     *
     * @return PromiseInterface<ProgressReporter[]>|PromiseInterface
     */
    public function findTasksByStatus(bool $finished, int $offset, int $limit): PromiseInterface
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return $this->storage->findByStatus($finished, $offset, $limit);
    }

    /**
     * Find tasks by status
     *
     * @param bool $finished False for progressing tasks, true for finished tasks
     * @param int  $offset
     * @param int  $limit
     *
     * @return ProgressReporter[]
     */
    public function findTasksByStatusSync(bool $finished, int $offset, int $limit): array
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return await($this->findTasksByStatus($finished, $offset, $limit), $this->storage->getLoop());
    }

    /**
     * Returns the total task count
     *
     * @return PromiseInterface<int>
     */
    public function totalTaskCount(): PromiseInterface
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return $this->storage->count();
    }

    /**
     * Returns the total task count
     *
     * @return PromiseInterface<int>
     */
    public function totalTaskCountSync(): int
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return await($this->storage->count(), $this->storage->getLoop());
    }

    /**
     * Find task counts by status
     *
     * @param bool $finished False for progressing tasks, true for finished tasks
     *
     * @return PromiseInterface<int>
     */
    public function countTasksByStatus(bool $finished): PromiseInterface
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return $this->storage->countByStatus($finished);
    }

    /**
     * Find task counts by status
     *
     * @param bool $finished False for progressing tasks, true for finished tasks
     *
     * @return int
     */
    public function countTasksByStatusSync(bool $finished): int
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }

        return await($this->storage->countByStatus($finished), $this->storage->getLoop());
    }
}