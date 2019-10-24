<?php

namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;

/**
 * Interface LoopAwareInterface can be used with TaskInterface to pass event loop to the task.
 *
 * @package SunValley\TaskManager
 */
interface LoopAwareInterface
{

    public function setLoop(LoopInterface $loop): void;
}