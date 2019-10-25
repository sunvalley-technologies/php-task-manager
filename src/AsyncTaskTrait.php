<?php

namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

trait AsyncTaskTrait
{

    /** @var LoopInterface */
    protected $loop;

    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    public function run(ProgressReporter $progressReporter): void
    {
        $this->_run($this->loop, $progressReporter)->then(
            function ($result = null) use ($progressReporter) {
                if ($progressReporter->getStatus() !== TaskStatus::PROCESSING()) {
                    return;
                }

                $progressReporter->finishTask($result);
            },
            function ($error) use ($progressReporter) {
                if ($progressReporter->getStatus() !== TaskStatus::PROCESSING()) {
                    return;
                }

                if ($error instanceof \Throwable) {
                    $progressReporter->failTask($error->getTraceAsString(), $error->getMessage());

                    return;
                } elseif (!is_scalar($error)) {
                    $error = null;
                }

                $progressReporter->failTask(null, $error);
            }
        );
    }

    /**
     * Implementing classes does not need to deal with finishing or failing a job through progress reporter as returned
     * promise is used to handle progress reporter. Although progress information still needs to be sent through
     * progress reporter and promises progress feature should not be used.
     *
     * @param LoopInterface    $loop
     * @param ProgressReporter $reporter
     *
     * @return PromiseInterface Resolved value will be set to result of the progress reporter while an exception trace
     *                          will be set to error and if rejection is a string that will be set as message as error
     *                          string. This will only be done if the progress reporters finishTask or failTask is not
     *                          called.
     */
    abstract protected function _run(LoopInterface $loop, ProgressReporter $reporter): PromiseInterface;
    
}