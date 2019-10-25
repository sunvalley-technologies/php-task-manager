<?php

namespace SunValley\TaskManager;

class Configuration
{

    /** @var int */
    private $minProcesses = 0;

    /** @var int */
    private $maxProcesses = 10;

    /** @var int */
    private $maxRunningTasks = 50;

    /**
     * Get maximum processes that will be spawned in pool. Defaults to 10.
     *
     * @return int
     */
    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }

    /**
     * Set maximum processes that will be spawned in pool. Defaults to 10.
     *
     * @param int $maxProcesses
     */
    public function setMaxProcesses(int $maxProcesses): void
    {
        $this->maxProcesses = $maxProcesses;
    }

    /**
     * Get minimum processes that will be spawned in pool. Defaults to 0.
     *
     * @return int
     */
    public function getMinProcesses(): int
    {
        return $this->minProcesses;
    }

    /**
     * Set minimum processes that will be spawned in pool. Defaults to 0.
     *
     * @param int $minProcesses
     */
    public function setMinProcesses(int $minProcesses): void
    {
        $this->minProcesses = $minProcesses;
    }

    /**
     * Get maximum tasks that are running at one time. Since each pool worker can take more than one task if the task
     * is asyncronous.
     *
     * @return int
     */
    public function getMaxRunningTasks(): int
    {
        return $this->maxRunningTasks;
    }

    /**
     * @param int $maxRunningTasks
     */
    public function setMaxRunningTasks(int $maxRunningTasks): void
    {
        $this->maxRunningTasks = $maxRunningTasks;
    }
}