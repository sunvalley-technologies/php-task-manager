<?php

namespace SunValley\TaskManager\Tests\Fixtures\Task;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\Task\AbstractAsyncTask;
use SunValley\TaskManager\ProgressReporter;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TestAsyncTask extends AbstractAsyncTask
{

    function buildOptionsResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['timer', 'return']);
        $resolver->setAllowedTypes('timer', ['int', 'float']);

        return $resolver;
    }

    /** @inheritDoc */
    protected function _run(LoopInterface $loop, ProgressReporter $reporter): PromiseInterface
    {
        $reporter->setMessage(sprintf('Progress will finish in %d seconds', $this->getOptions()['timer']));
        $reporter->setCompletion(50);

        $deferred = new Deferred();
        $loop->addTimer(
            $this->getOptions()['timer'],
            function () use ($deferred) {
                $deferred->resolve($this->getOptions()['return']);
            }
        );

        return $deferred->promise();
    }
}