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
use SunValley\TaskManager\TaskStorage\RedisTaskStorage;
use SunValley\TaskManager\TaskStorageInterface;
use SunValley\TaskManager\Tests\Fixtures\Task\TestAsyncTask;
use SunValley\TaskManager\Tests\Fixtures\Task\TestFailingTask;
use SunValley\TaskManager\Tests\Fixtures\Task\TestMultiplyTask;
use SunValley\TaskManager\Tests\Fixtures\Task\TestUpdatingSyncTask;
use SunValley\TaskManager\Tests\Fixtures\Task\TestUpdatingTask;
use function Clue\React\Block\await;
use function React\Promise\all;

class TaskManagerTest extends TestCase
{

    public function testTaskManager()
    {
        $configuration = new Configuration();
        $configuration->setMaxJobsPerProcess(2);
        $configuration->setMaxProcesses(3);
        $configuration->setTtl(1);

        $loop    = LoopFactory::create();
        $storage = $this->createMock(TaskStorageInterface::class);
        $storage->expects($this->atLeastOnce())->method('update');
        $this->_testTaskManager($loop, new InMemoryTaskQueue($loop), $storage, $configuration);
    }

    public function testTaskManagerRedisStorage()
    {
        $configuration = new Configuration();
        $configuration->setMaxJobsPerProcess(2);
        $configuration->setMaxProcesses(3);
        $configuration->setTtl(1);

        $loop    = LoopFactory::create();
        $storage = $this->generateRedisStorage($loop);
        $storage->clean();
        $this->_testTaskManager($loop, new InMemoryTaskQueue($loop), $storage, $configuration);
    }

    public function _testTaskManager($loop, $queue, $storage, $configuration)
    {
        $taskManager = new TaskManager($loop, $queue, $configuration, $storage);
        $task1       = $this->buildAsyncTask();
        $task2       = $this->buildAsyncTask();
        $task3       = $this->buildAsyncTask();
        $task4       = $this->buildFailingTask('error message');
        $task5       = new TestUpdatingTask('updating-task');
        $task6       = new TestUpdatingSyncTask('updating-sync-task');

        $caught          = [];
        $taskCompletedFn = function (TaskInterface $task) use (&$caught, $taskManager, $loop) {
            $taskManager->on(
                'task-completed-' . $task->getId(),
                function (ProgressReporter $reporter) use (&$caught, $loop) {
                    $caught[$reporter->getTask()->getId()] = $reporter->getResult();
                }
            );
        };
        $failed          = [];
        $taskFailedFn    = function (TaskInterface $task) use (&$failed, $taskManager, $loop) {
            $taskManager->on(
                'task-failed-' . $task->getId(),
                function (ProgressReporter $reporter) use (&$failed, $loop) {
                    $failed[$reporter->getTask()->getId()] = $reporter->getError();
                }
            );
        };
        $taskCompletedFn($task1);
        $taskCompletedFn($task2);
        $taskCompletedFn($task4);
        $taskCompletedFn($task5);
        $taskCompletedFn($task6);

        // Issue #8 check if task-progress- is getting called
        $task5ProgressCalled = false;
        $taskManager->on(
            'task-progress-' . $task5->getId(),
            function (ProgressReporter $reporter) use (&$task5ProgressCalled) {
                $task5ProgressCalled = true;
            }
        );
        $task6ProgressCalled = false;
        $taskManager->on(
            'task-progress-' . $task6->getId(),
            function (ProgressReporter $reporter) use (&$task5ProgressCalled) {
                $task6ProgressCalled = true;
            }
        );

        $taskFailedFn($task4);

        // push the task from queue
        $queue->enqueue($task1);
        $queue->enqueue($task2);
        $queue->enqueue($task4);
        $queue->enqueue($task5);
        $queue->enqueue($task6);
        $this->assertEquals(5, $queue->info()[Stats::CURRENT_TASKS]);
        $taskManager->submitTask($task3)->then(
            function (ProgressReporter $reporter) use (&$caught, $loop) {
                $caught[$reporter->getTask()->getId()] = $reporter->getResult();
            }
        );

        $this->assertEquals(6, $queue->info()[Stats::CURRENT_TASKS]);

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
        $this->assertEquals($task3->getOptions()['return'], $caught[$task3->getId()]);
        $this->assertEquals('done', $caught[$task5->getId()]);
        $this->assertEquals('done', $caught[$task6->getId()]);
        $this->assertContains($task4->getOptions()['error'], $failed[$task4->getId()]);
        $this->assertTrue(empty($failed[$task1->getId()]));
        $this->assertTrue(empty($failed[$task2->getId()]));
        $this->assertTrue(empty($failed[$task3->getId()]));
        $this->assertTrue(empty($failed[$task5->getId()]));
        $this->assertTrue($task5ProgressCalled);
        $this->assertTrue($task6ProgressCalled);

        $taskManager->terminate();
        $loop->run();

        $stats = $taskManager->stats();
        $this->assertEquals(0, $stats[Stats::_GROUP_QUEUE][Stats::CURRENT_TASKS]);
        $this->assertEquals(5, $stats[Stats::_GROUP_POOL][Stats::COMPLETED_TASKS]);
        $this->assertEquals(1, $stats[Stats::_GROUP_POOL][Stats::FAILED_TASKS]);
    }

    /**
     * @param string $error
     *
     * @return TestFailingTask
     */
    protected function buildFailingTask(string $error = null)
    {
        $options = [];
        if ($error !== null) {
            $options = ['error' => $error];
        }

        return new TestFailingTask(uniqid(), $options);
    }

    protected function buildSyncTask()
    {
        return new TestMultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);
    }

    protected function buildAsyncTask()
    {
        return new TestAsyncTask(uniqid(), ['timer' => 0.5, 'return' => mt_rand()]);
    }


    protected function generateRedisStorage($loop)
    {
        $redisUri = $_SERVER['REDIS_URI'] ?? $_ENV['REDIS_URI'] ?? 'redis://localhost:6379';

        return new class($loop, $redisUri) extends RedisTaskStorage {

            public function getClient()
            {
                return $this->client;
            }

            public function clean()
            {
                await(
                    all(
                        [
                            $this->client->del($this->key),
                            $this->client->del($this->generateGroupKey('finished')),
                            $this->client->del($this->generateGroupKey('unfinished')),
                        ]
                    ),
                    $this->getLoop()
                );

            }
        };
    }
}