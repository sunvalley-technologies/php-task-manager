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
    }

    public function generateProgressReporter(): ProgressReporter
    {
        $this->stopCall   = false;
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
        $this->worker = $worker;

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

        $this->stopCall = true;
        $this->process  = null;
        $this->worker   = null;
        $this->spawning = false;

        if ($this->reporter->isFailed()) {
            $this->startDefer->reject(new RuntimeException($this->reporter->getError()));
        } else {
            $this->startDefer->reject(new RuntimeException('Process terminated'));
        }

        $this->startDefer = new Deferred();
    }
}