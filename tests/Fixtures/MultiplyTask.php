<?php

namespace SunValley\TaskManager\Tests\Fixtures;

use SunValley\TaskManager\AbstractTask;
use SunValley\TaskManager\ProgressReporter;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MultiplyTask extends AbstractTask
{

    /** @inheritDoc */
    function getOptionsResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['number1', 'number2']);
        $resolver->setAllowedTypes('number1', ['int', 'float']);
        $resolver->setAllowedTypes('number2', ['int', 'float']);

        return $resolver;
    }

    /** @inheritDoc */
    public function run(ProgressReporter $progressReporter): void
    {
        $progressReporter->setMessage('Calculating result now!');

        $options = $this->getOptions();
        $result  = $options['number1'] * $options['number2'];

        $progressReporter->setMessage(sprintf('%f*%f=%f', $options['number1'], $options['number2'], $result));
        $progressReporter->finishTask($result);
    }
}