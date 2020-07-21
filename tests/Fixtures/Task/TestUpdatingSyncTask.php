<?php

namespace SunValley\TaskManager\Tests\Fixtures\Task;

use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\Task\AbstractTask;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Note this is a test to show that progress reporters are not working with SyncTasks
class TestUpdatingSyncTask extends AbstractTask
{
    
    function buildOptionsResolver(): OptionsResolver
    {
        return new OptionsResolver();
    }

    public function run(ProgressReporter $progressReporter): void
    {
        $progressReporter->setCompletionTarget(10);
        for ($i=0; $i<10;$i++) {
            $progressReporter->setCompletion($i);
            $progressReporter->setMessage('completed '. $i);
            sleep(1);
        }
        $progressReporter->finishTask('done');
    }
}