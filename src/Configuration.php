<?php

namespace SunValley\TaskManager;

class Configuration
{

    /** @var int */
    private $minProcesses = 1;

    /** @var int */
    private $maxProcesses = 10;

    /** @var int */
    private $ttl = 60;

    /** @var int */
    private $maxJobsPerProcess = 10;

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
     * Set minimum processes that will be spawned in pool. Defaults to 1.
     *
     * @param int $minProcesses
     */
    public function setMinProcesses(int $minProcesses): void
    {
        $this->minProcesses = $minProcesses;
    }

    /**
     * Get time to live in seconds for an IDLE process
     *
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Set time to live in seconds for an IDLE process
     *
     * @param int $ttl
     */
    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }

    /**
     * Get maximum jobs a process can handle
     *
     * @return int
     */
    public function getMaxJobsPerProcess(): int
    {
        return $this->maxJobsPerProcess;
    }

    /**
     * Set maximum jobs a process can handle
     *
     * @param int $maxJobsPerProcess
     */
    public function setMaxJobsPerProcess(int $maxJobsPerProcess): void
    {
        $this->maxJobsPerProcess = $maxJobsPerProcess;
    }
}