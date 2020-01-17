<?php

namespace SunValley\TaskManager\Tests\Fixtures;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\Task\AbstractServiceTask;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FailingServiceTask extends AbstractServiceTask
{

    /**
     * @inheritDoc
     */
    function buildOptionsResolver(): OptionsResolver
    {
        return new OptionsResolver();
    }

    /**
     * @inheritDoc
     */
    protected function _run(LoopInterface $loop, ProgressReporter $reporter): PromiseInterface
    {
        throw  new \RuntimeException('Service failed to start');
    }

    /**
     * @inheritDoc
     */
    public function terminateChild(): void
    {

    }

    /**
     * @inheritDoc
     */
    public function terminateMain(): void
    {

    }
}