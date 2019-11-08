<?php


namespace SunValley\TaskManager\TaskQueue;

use React\EventLoop\LoopInterface;
use SunValley\TaskManager\TaskQueueInterface;
use SunValley\TaskManager\TaskStorageInterface;

abstract class AbstractTaskQueue implements TaskQueueInterface
{

    /** @var LoopInterface */
    protected $loop;

    /** @var TaskStorageInterface|null */
    protected $taskStorage;

    public function __construct(LoopInterface $loop, ?TaskStorageInterface $taskStorage = null)
    {
        $this->loop        = $loop;
        $this->taskStorage = $taskStorage;
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    public function getTaskStorage(): ?TaskStorageInterface
    {
        return $this->taskStorage;
    }
}