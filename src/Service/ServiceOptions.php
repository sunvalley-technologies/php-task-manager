<?php

namespace SunValley\TaskManager\Service;

use SunValley\TaskManager\ServiceManager;

class ServiceOptions
{

    /** @var int */
    private $terminateTimeout = 5;

    /** @var int */
    private $terminateTimeoutHard = 10;

    /** @var int */
    private $restartPolicy = ServiceManager::RESTART_POLICY_ALWAYS;

    public function getTerminateTimeout(): int
    {
        return $this->terminateTimeout;
    }

    public function setTerminateTimeout(int $terminateTimeout): self
    {
        $this->terminateTimeout = $terminateTimeout;

        return $this;
    }

    public function getTerminateTimeoutHard(): int
    {
        return $this->terminateTimeoutHard;
    }

    public function setTerminateTimeoutHard(int $terminateTimeoutHard): self
    {
        $this->terminateTimeoutHard = $terminateTimeoutHard;

        return $this;
    }

    public function getRestartPolicy(): int
    {
        return $this->restartPolicy;
    }

    public function setRestartPolicy(int $restartPolicy): self
    {
        $policies = [
            ServiceManager::RESTART_POLICY_ALWAYS,
            ServiceManager::RESTART_POLICY_NO,
            ServiceManager::RESTART_POLICY_NO_ERROR,
        ];
        if (!in_array($restartPolicy, $policies, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid restart policy %d is given!', $restartPolicy));
        }

        $this->restartPolicy = $restartPolicy;

        return $this;
    }
}