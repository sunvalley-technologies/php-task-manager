<?php


namespace SunValley\TaskManager\Tests\Fixtures;

use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\Task\AbstractTask;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FailingTask extends AbstractTask
{
    /** @inheritDoc */
    function buildOptionsResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefault('error', 'error');
        $resolver->setAllowedTypes('error', 'string');

        return $resolver;
    }

    /** @inheritDoc */
    public function run(ProgressReporter $progressReporter): void
    {
        $progressReporter->setMessage('Throwing error now!');

        $options = $this->getOptions();

        throw new \RuntimeException($options['error']);
    }
}