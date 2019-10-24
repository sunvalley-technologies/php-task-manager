<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;

class ProgressReporter extends EventEmitter
{

    /** @var string */
    private $message = '';

    /** @var float */
    private $completion = 0.0;

    /** @var float */
    private $completionTarget = 100.0;

    /** @var TaskStatus */
    private $status;

    /** @var TaskInterface */
    private $task;

    /**
     * @var mixed|null
     */
    private $result;

    /**
     * ProgressReporter constructor.
     *
     * @param TaskInterface $task
     */
    public function __construct(TaskInterface $task)
    {
        $this->task   = $task;
        $this->status = TaskStatus::PROCESSING();
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;

        $this->emitChangEvent();
    }

    public function setCompletion(float $indicator): void
    {
        $this->completion = $indicator;

        $this->emitChangEvent();
    }

    public function getCompletion(): float
    {
        return $this->completion;
    }

    public function setCompletionTarget(float $target): void
    {
        $this->completionTarget = $target;

        $this->emitChangEvent();
    }

    public function getCompletionTarget(): float
    {
        return $this->completionTarget;
    }

    public function finishTask($result = null)
    {
        $this->status = TaskStatus::COMPLETED();
        $this->result = $result;
        $this->emit('done', [$this]);
    }

    public function failTask()
    {
        $this->status = TaskStatus::FAILED();
        $this->result = null;
        $this->emit('failed', [$this]);
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    public function getTask(): TaskInterface
    {
        return $this->task;
    }

    public function setTask(TaskInterface $task): void
    {
        $this->task = $task;
    }

    protected function emitChangEvent()
    {
        $this->status === TaskStatus::PROCESSING() && $this->emit('change', [$this]);
    }
}