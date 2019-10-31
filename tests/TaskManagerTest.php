<?php

namespace SunValley\TaskManager\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\TimerInterface;
use SunValley\TaskManager\Configuration;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\Stats;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\TaskManager;
use SunValley\TaskManager\TaskQueue\InMemoryTaskQueue;
use SunValley\TaskManager\Tests\Fixtures\AsyncTask;
use SunValley\TaskManager\Tests\Fixtures\MultiplyTask;

class TaskManagerTest extends TestCase
{

    public function testTaskManager()
    {
        $loop          = LoopFactory::create();
        $queue         = new InMemoryTaskQueue($loop);
        $configuration = new Configuration();
        $configuration->setMaxJobsPerProcess(2);
        $configuration->setMaxProcesses(3);
        $configuration->setTtl(1);

        $taskManager = new TaskManager($loop, $queue, $configuration);
        $task1       = $this->buildAsyncTask();
        $task2       = $this->buildAsyncTask();

        $caught          = [];
        $taskCompletedFn = function (TaskInterface $task) use (&$caught, $taskManager, $loop) {
            $taskManager->on(
                'task-completed-' . $task->getId(),
                function (ProgressReporter $reporter) use (&$caught, $loop) {
                    $caught[$reporter->getTask()->getId()] = $reporter->getResult();
                }
            );
        };
        $taskCompletedFn($task1);
        $taskCompletedFn($task2);

        // push the task from queue
        $queue->enqueue($task1);
        $queue->enqueue($task2);
        $this->assertEquals(2, $queue->info()[Stats::CURRENT_TASKS]);

        $loop->addPeriodicTimer(
            .6,
            function (TimerInterface $timer) use ($loop, $taskManager, $queue) {
                if ($taskManager->stats()[Stats::_GROUP_POOL][Stats::CURRENT_TASKS] === 0 && $queue->count() === 0) {
                    $loop->cancelTimer($timer);
                    $loop->stop();
                }
            }
        );
        
        $loop->run();

        $this->assertEquals($task1->getOptions()['return'], $caught[$task1->getId()]);
        $this->assertEquals($task2->getOptions()['return'], $caught[$task2->getId()]);

        $taskManager->terminate();
        $loop->run();

        $stats = $taskManager->stats();
        $this->assertEquals(0, $stats[Stats::_GROUP_QUEUE][Stats::CURRENT_TASKS]);
        $this->assertEquals(2, $stats[Stats::_GROUP_POOL][Stats::COMPLETED_TASKS]);
    }


    protected function buildSyncTask()
    {
        return new MultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);
    }

    protected function buildAsyncTask()
    {
        return new AsyncTask(uniqid(), ['timer' => 0.5, 'return' => mt_rand()]);
    }
}