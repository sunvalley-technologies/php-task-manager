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
     *
     * @return PromiseInterface<array> Returns a promise resolving into an array of ProgressReporters representing all tasks in the storage
     * @throws \RuntimeException Thrown when no storage is detected
     */
    public function checkAllTasksStatus(): PromiseInterface
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }
        return $this->storage->findAll();
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
        return await($this->checkTaskStatus($taskId), $this->storage->getLoop());
    }

    /**
     *
     * @return ProgressReporter[] Returns an array of ProgressReporters representing all tasks in the storage
     * @throws \RuntimeException Thrown when no storage is detected
     * @throws \Exception
     */
    public function checkAllTasksStatusSync(): PromiseInterface
    {
        if (!$this->storage) {
            throw new \RuntimeException('No storage is defined');
        }
        return await($this->storage->findAll(), $this->storage->getLoop());
    }
}