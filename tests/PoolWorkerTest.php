<?php

namespace SunValley\TaskManager\Tests;

use PHPUnit\Framework\TestCase;
use React\ChildProcess\Process;
use React\Socket\ConnectionInterface;
use SunValley\TaskManager\PoolOptions;
use SunValley\TaskManager\PoolWorker;
use SunValley\TaskManager\ProcessAwareMessenger;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskStatus;
use SunValley\TaskManager\Tests\Fixtures\AsyncTask;
use SunValley\TaskManager\Tests\Fixtures\MultiplyTask;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;

class PoolWorkerTest extends TestCase
{

    public function testSyncTask()
    {
        $connection = $this->prophesize(ConnectionInterface::class);
        $process    = $this->prophesize(Process::class);
        $messenger  = new ProcessAwareMessenger($process->reveal(), $connection->reveal());
        $worker     = new PoolWorker($messenger);

        $task             = $this->buildSyncTask();
        $completionCalled = false;
        $task->on(
            'done',
            function () use (&$completionCalled) {
                $completionCalled = true;
            }
        );

        $exceptionFn = function ($e) {
            if ($e instanceof \Throwable) {
                throw $e;
            } else {
                throw new \Exception($e);
            }
        };
        $this->assertFalse($worker->isBusy());
        $worker->submitTask($task)->otherwise($exceptionFn);
        $this->assertTrue($worker->isBusy());

        $changeTask = clone $task;
        $changeTask->removeAllListeners();
        $changeTask->setMessage('Progressing ... ');
        $changeTask->setCompletion(50);
        $messenger->callRpc('task-report', new Payload(['report' => serialize($changeTask)]));

        $this->assertEquals(50, $task->getCompletion());
        $this->assertEquals('Progressing ... ', $task->getMessage());
        $this->assertFalse($completionCalled);
        $this->assertEquals(TaskStatus::PROCESSING(), $task->getStatus());

        $changeTask->setMessage('Done ... ');
        $result = $task->getTask()->getOptions()['number1'] * $task->getTask()->getOptions()['number2'];
        $changeTask->finishTask($result);
        $messenger->callRpc('task-report', new Payload(['report' => serialize($changeTask)]))->otherwise($exceptionFn);

        $this->assertEquals(100, $task->getCompletion());
        $this->assertEquals('Done ... ', $task->getMessage());
        $this->assertTrue($completionCalled);
        $this->assertEquals(TaskStatus::COMPLETED(), $task->getStatus());
        $this->assertEquals($result, $task->getResult());
    }

    public function testSyncTaskFail()
    {
        $connection = $this->prophesize(ConnectionInterface::class);
        $process    = $this->prophesize(Process::class);
        $messenger  = new ProcessAwareMessenger($process->reveal(), $connection->reveal());
        $worker     = new PoolWorker($messenger);

        $task       = $this->buildSyncTask();
        $failCalled = false;
        $task->on(
            'failed',
            function () use (&$failCalled) {
                $failCalled = true;
            }
        );

        $exceptionFn = $this->getExceptionFn();
        $worker->submitTask($task)->otherwise($exceptionFn);
        $changeTask = clone $task;
        $changeTask->removeAllListeners();
        $changeTask->failTask('Internal Error on line 214', 'Internal Error');
        $messenger->callRpc('task-report', new Payload(['report' => serialize($changeTask)]));

        $this->assertEquals(0, $task->getCompletion());
        $this->assertEquals('Internal Error', $task->getMessage());
        $this->assertEquals('Internal Error on line 214', $task->getError());
        $this->assertTrue($failCalled);
        $this->assertEquals(TaskStatus::FAILED(), $task->getStatus());
    }

    protected function buildSyncTask()
    {
        $task = new MultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);

        return new ProgressReporter($task);
    }

    public function testAsyncTask()
    {
        $connection = $this->prophesize(ConnectionInterface::class);
        $process    = $this->prophesize(Process::class);
        $messenger  = new ProcessAwareMessenger($process->reveal(), $connection->reveal());
        $worker     = new PoolWorker($messenger);
        $worker->setOptions([PoolOptions::MAX_JOBS_PER_PROCESS => 2]);

        $task1 = $this->buildAsyncTask();
        $task2 = $this->buildAsyncTask();

        $exceptionFn = $this->getExceptionFn();
        $this->assertFalse($worker->isBusy());
        $worker->submitTask($task1)->otherwise($exceptionFn);
        $this->assertFalse($worker->isBusy());
        $this->assertEquals(1, $worker->taskCount());
        $worker->submitTask($task2)->otherwise($exceptionFn);
        $this->assertTrue($worker->isBusy());
        $this->assertEquals(2, $worker->taskCount());
    }

    protected function buildAsyncTask()
    {
        $task = new AsyncTask(uniqid(), ['timer' => 5, 'return' => mt_rand()]);

        return new ProgressReporter($task);
    }

    protected function getExceptionFn(): \Closure
    {
        return function ($e) {
            if ($e instanceof \Throwable) {
                throw $e;
            } else {
                throw new \Exception($e);
            }
        };
    }
}