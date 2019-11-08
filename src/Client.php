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

    public function __construct(TaskQueueInterface $queue)
    {
        $this->queue = $queue;
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

}