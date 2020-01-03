<?php

namespace SunValley\TaskManager;

use SunValley\TaskManager\Configuration as PoolConfiguration;

/**
 * Class TaskManagerFactory is a singleton factory to provide a TaskManager to the caller and also returns a single
 * task manager..
 *
 * @package SunValley\TaskManager\Symfony\Task
 */
interface TaskManagerFactoryInterface
{
    /**
     * If required creates the task manager and returns it.
     *
     * @param PoolConfiguration|null $configuration Providing a configuration will create a fresh manager. Generated
     *                                              manager this way should not be cached by the implementation.
     *
     * @return TaskManager
     */
    public function generate(?PoolConfiguration $configuration = null): TaskManager;
}