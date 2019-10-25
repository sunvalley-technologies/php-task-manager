<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;

class ProgressReporter extends EventEmitter
{

    /** @var string */
    private $message = '';

    /** @var string */
    private $error = '';

    /** @var float */
    private $completion = 0.0;

    /** @var float */
    private $completionTarget = 100.0;

    /** @var TaskStatus */
    private $status;

    /** @var TaskInterface */
    private $task;

    /** @var mixed|null */
    private $result;

    /** @var int */
    private $counter = 0;

    /** @var bool */
    private $preventChangeEvent = false;

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

    /**
     * Get progress message for this task
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Set progress message for this task
     *
     * @param string $message
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;

        $this->emitChangeEvent();
    }

    /**
     * Get error message. This is an internal message like an exception.
     * 
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }
    
    /**
     * Set completion indicator.  This is used with completion target to indicate a percentage of completion.
     *
     * @param float $indicator
     */
    public function setCompletion(float $indicator): void
    {
        $this->completion = $indicator;

        $this->emitChangeEvent();
    }

    /**
     * Get completion indicator. This is used with completion target to indicate a percentage of completion.
     *
     * @return float
     */
    public function getCompletion(): float
    {
        return $this->completion;
    }

    /**
     * Set the completion target. Defaults to 100.
     *
     * @param float $target
     */
    public function setCompletionTarget(float $target): void
    {
        $this->completionTarget = $target;

        $this->emitChangeEvent();
    }

    /**
     * Get the completion target. Defaults to 100.
     *
     * @return float
     */
    public function getCompletionTarget(): float
    {
        return $this->completionTarget;
    }

    /**
     * Indicate that a task is finished with given result. This method should only be called once and if this method is
     * called, failTask should not be called after. Sets completion as completion target to indicate 100% completion.
     *
     * @param null $result
     */
    public function finishTask($result = null)
    {
        $previousStatus   = $this->status;
        $this->status     = TaskStatus::COMPLETED();
        $this->result     = $result;
        $this->completion = $this->completionTarget;

        if ($previousStatus === TaskStatus::PROCESSING()) {
            $this->emit('done', [$this]);
        }
    }

    /**
     * Indicate that a task is finished with error.  This method should only be called once and if this method is
     * called, finishTask should not be called after. Does not change completion.
     *
     * @param string|null $error   If given, error message is set to this
     * @param string|null $message If given, message is set to this
     */
    public function failTask(string $error = null, string $message = null)
    {
        $previousStatus = $this->status;
        $this->status   = TaskStatus::FAILED();
        $this->result   = null;
        $this->error    = $error ?? $this->error;
        $this->message  = $message ?? $this->message;

        if ($previousStatus === TaskStatus::PROCESSING()) {
            $this->emit('failed', [$this]);
        }
    }

    /**
     * Get internal status of the task
     *
     * @return TaskStatus
     */
    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    /**
     * Get related task
     *
     * @return TaskInterface
     */
    public function getTask(): TaskInterface
    {
        return $this->task;
    }

    /**
     * Set related task
     *
     * @param TaskInterface $task
     */
    public function setTask(TaskInterface $task): void
    {
        $this->task = $task;
    }

    /**
     * Get the result from the job. Populated only when job is finished.
     *
     * @return mixed|null
     */
    public function getResult()
    {
        return $this->result;
    }

    protected function emitChangeEvent()
    {
        if ($this->preventChangeEvent) {
            return;
        }

        $this->counter++;
        $this->status === TaskStatus::PROCESSING() && $this->emit('change', [$this]);
    }

    /**
     * Merge given reporter with this one if counter of the given reporter is bigger than this instance. This method
     * does not change the task instance. Throws an \InvalidArgumentException if task identifiers are not same.
     *
     * This emits proper events according to event changes.
     *
     * @param ProgressReporter $reporter
     */
    public function merge(ProgressReporter $reporter)
    {
        if ($reporter->getTask()->getId() != $this->getTask()->getId()) {
            throw new \InvalidArgumentException(
                'Given progress report cannot be merged as task identifiers are not same!'
            );
        }

        if ($reporter->counter > $this->counter) {
            $this->preventChangeEvent = true;
            $this->setCompletion($reporter->getCompletion());
            $this->setCompletionTarget($reporter->getCompletionTarget());
            $this->setMessage($reporter->getMessage());
            $this->preventChangeEvent = false;
            $this->status             = $reporter->status;
            $this->result             = $reporter->getResult();
            if ($this->status === TaskStatus::PROCESSING()) {
                $this->emitChangeEvent();
            } elseif ($this->status === TaskStatus::FAILED()) {
                $this->failTask();
            } elseif ($this->status === TaskStatus::COMPLETED()) {
                $this->finishTask();
            }
        }
    }

    public function __clone()
    {
        $this->task = clone $this->task;
    }
}