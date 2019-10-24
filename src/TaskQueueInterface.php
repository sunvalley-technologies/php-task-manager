<?php

namespace SunValley\TaskManager;

interface TaskQueueInterface
{

    /**
     * Add the given task to this queue
     * 
     * @param TaskInterface $task
     *
     * @throws Exception\TaskQueueException
     */
    public function enqueue(TaskInterface $task): void;

    /**
     * Gets a task from this queue. This method should not block. 
     * 
     * @return TaskInterface|null
     * @throws Exception\TaskQueueException
     */
    public function dequeue(): ?TaskInterface;
}