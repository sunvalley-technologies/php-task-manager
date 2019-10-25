<?php

namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;

/**
 * Interface LoopAwareInterface can be used with TaskInterface to pass event loop to the task. Tasks implementing this 
 * interface will be considered asyncronous while tasks not implementing considered synchronous.
 *
 * @package SunValley\TaskManager
 */
interface LoopAwareInterface
{

    public function setLoop(LoopInterface $loop): void;
}