<?php

namespace SunValley\TaskManager;

use React\ChildProcess\Process as ReactProcess;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;

/** @internal */
class ServiceStatus implements ServiceStatusInterface
{

    /** @var ProgressReporter */
    private $reporter;

    /** @var ServiceTaskInterface */
    private $task;

    /** @var ServiceOptions */
    private $options;

    /** @var PoolWorker */
    private $worker;

    /** @var bool */
    private $spawning = false;

    /** @var int */
    private $startedTimes = 0;

    /** @var ReactProcess */
    private $process;

    /** @var Deferred */
    private $startDefer;

    /** @var Deferred */
    private $stopDefer;

    /** @var bool */
    private $stopCall = false;

    public function __construct(ServiceTaskInterface $task, ?ServiceOptions $options)
    {
        if ($options === null) {
            $this->options = new ServiceOptions();
        } else {
            $this->options = $options;
        }
        $this->task       = $task;
        $this->reporter   = new ProgressReporter($this->task);
        $this->startDefer = new Deferred();
        $this->stopDefer  = new Deferred();
    }

    public function generateProgressReporter(): ProgressReporter
    {
        $this->stopCall = false;
        $this->startedTimes++;
        $this->spawning = true;

        return $this->reporter = new ProgressReporter($this->task);
    }

    public function getOptions(): ServiceOptions
    {
        return $this->options;
    }

    public function getReporter(): ProgressReporter
    {
        return $this->reporter;
    }

    public function getWorker(): ?PoolWorker
    {
        return $this->worker;
    }

    public function setWorker(PoolWorker $worker): self
    {
        $this->spawning = false;
        $this->startDefer->resolve($this->reporter);
        $this->startDefer = new Deferred();
        $this->worker     = $worker;

        return $this;
    }

    public function getProcess(): ?ReactProcess
    {
        return $this->process;
    }

    public function setProcess(ReactProcess $process): self
    {
        $this->process = $process;

        return $this;
    }

    public function isSpawning(): bool
    {
        return $this->spawning;
    }

    public function getStartedTimes(): int
    {
        return $this->startedTimes;
    }

    public function getStartPromise(): PromiseInterface
    {
        return $this->startDefer->promise();
    }

    public function stopTask(): void
    {
        if ($this->stopCall) {
            return;
        }


        $reason = null;
        if ($this->spawning) {
            if ($this->reporter->isFailed()) {
                $reason = new RuntimeException($this->reporter->getError());
            } else {
                $reason = new RuntimeException('Process terminated');
            }
        }
        $this->stopCall = true;
        $this->process  = null;
        $this->worker   = null;
        $this->spawning = false;
        $this->stopDefer->resolve($this->reporter);
        $this->stopDefer = new Deferred();
        if ($reason) {
            $this->startDefer->reject($reason);
            $this->startDefer = new Deferred();
        }
    }

    /**
     * @inheritDoc
     */
    public function getStopPromise(): PromiseInterface
    {
        return $this->stopDefer->promise();
    }
}