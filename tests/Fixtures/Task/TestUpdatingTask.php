<?php

namespace SunValley\TaskManager\Tests\Fixtures\Task;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\Task\AbstractAsyncTask;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TestUpdatingTask extends AbstractAsyncTask
{

    /** @var int */
    private $progress = 0;
    /** @var ProgressReporter */
    private $reporter;
    /** @var Deferred */
    private $defer;


    function buildOptionsResolver(): OptionsResolver
    {
        return new OptionsResolver();
    }

    protected function _run(LoopInterface $loop, ProgressReporter $reporter): PromiseInterface
    {
        $reporter->setCompletionTarget(10);

        $this->defer    = new Deferred();
        $this->reporter = $reporter;
        $loop->futureTick(\Closure::fromCallable([$this, 'progressTask']));

        return $this->defer->promise()->always(
            function () {
                $this->reporter = null;
                $this->defer    = null;
            }
        );
    }

    protected function progressTask()
    {
        $this->progress++;
        $this->reporter->setCompletion($this->progress);
        if ($this->progress >= 10) {
            $this->defer->resolve('done');
        } else {
            $this->loop->addTimer(1, \Closure::fromCallable([$this, 'progressTask']));
        }
    }
}