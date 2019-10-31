<?php


namespace SunValley\TaskManager\Tests\Fixtures;


use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\AbstractTask;
use SunValley\TaskManager\AsyncTaskTrait;
use SunValley\TaskManager\LoopAwareInterface;
use SunValley\TaskManager\ProgressReporter;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AsyncTask extends AbstractTask implements LoopAwareInterface
{

    use AsyncTaskTrait;

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