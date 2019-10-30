<?php


namespace SunValley\TaskManager\Tests;


use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskQueue\InMemoryTaskQueue;
use SunValley\TaskManager\Tests\Fixtures\AsyncTask;
use SunValley\TaskManager\Tests\Fixtures\MultiplyTask;

class TaskQueueTest extends TestCase
{


    public function testMemoryTestQueue()
    {
        $loop     = Factory::create();
        $queue    = new InMemoryTaskQueue($loop);
        $cbCalled = false;
        $cb       = function () use (&$cbCalled) {
            $cbCalled = true;
        };
        $queue->onAvailableTask($cb);

        // enqueue
        $task1 = $this->buildSyncTask();
        $task2 = $this->buildSyncTask();
        $task3 = $this->buildSyncTask();
        $queue->enqueue($task1);
        $this->assertEquals(1, $queue->count());
        $queue->enqueue($task2);
        $queue->enqueue($task3);
        $task1A = $this->buildAsyncTask();
        $task1B = $this->buildAsyncTask();
        $queue->enqueue($task1A);
        $queue->enqueue($task1B);
        $this->assertEquals(5, $queue->count());
        $loop->run();
        $this->assertTrue($cbCalled);

        // dequeue
        $task = $queue->dequeue();
        $this->assertSame($task1, $task);
        $this->assertEquals(4, $queue->count());
        $queue->complete($task);

        // dequeue async
        $task = $queue->dequeue(true);
        $this->assertSame($task1A, $task);
        $this->assertEquals(3, $queue->count());
        $queue->fail($task);
        $this->assertCount(1, $queue->getFailedTasks());
        $queue->clearFailedTasks();
        $this->assertCount(0, $queue->getFailedTasks());

        // cancel task
        $queue->cancel($task1B);
        $this->assertEquals(2, $queue->count());

        // try to cancel a started task
        $task = $queue->dequeue();
        try {
            $queue->cancel($task);

            $this->assertFalse(true, 'No exception thrown for delivered task');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    protected function buildSyncTask()
    {
        $task = new MultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);

        return $task;
    }

    protected function buildAsyncTask()
    {
        $task = new AsyncTask(uniqid(), ['timer' => 5, 'return' => mt_rand()]);

        return $task;
    }
}