<?php

namespace SunValley\TaskManager\Tests;

use PHPUnit\Framework\MockObject\Matcher\AnyInvokedCount;
use PHPUnit\Framework\TestCase;
use React\ChildProcess\Process;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use SunValley\TaskManager\Exception\PoolException;
use SunValley\TaskManager\Pool;
use SunValley\TaskManager\PoolOptions;
use SunValley\TaskManager\ProcessAwareMessenger;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\Stats;
use SunValley\TaskManager\Tests\Fixtures\AsyncTask;
use SunValley\TaskManager\Tests\Fixtures\MultiplyTask;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollectionInterface;
use function React\Promise\resolve;

class PoolTest extends TestCase
{

    public function testGenericPoolFunctionality()
    {
        $loop = LoopFactory::create();
        /** @var Pool $pool */
        /** @var AnyInvokedCount $spy */
        list($pool, $spy) = $this->generatePool($loop, [PoolOptions::MAX_JOBS_PER_PROCESS => 2, PoolOptions::TTL => 1]);
        $pool->ping();
        $loop->run();

        $this->assertEquals(1, $pool->info()[Stats::CURRENT_PROCESSES]);
        $this->assertEquals(1, $pool->info()[Stats::MAX_PROCESSES]);
        $this->assertEquals(0, $pool->info()[Stats::CURRENT_TASKS]);

        // add 2 async tasks
        $task1   = $this->buildAsyncTask();
        $task2   = $this->buildAsyncTask();
        $error   = false;
        $errorFn = function ($e) use (&$error) {
            $error = $e;
        };
        $pool->submitTask($task1)->otherwise($errorFn);
        $pool->submitTask($task2)->otherwise($errorFn);
        $loop->run();

        $this->assertEquals(1, $pool->info()[Stats::CURRENT_PROCESSES]);
        $this->assertEquals(1, $pool->info()[Stats::MAX_PROCESSES]);
        $this->assertEquals(2, $pool->info()[Stats::CURRENT_TASKS]);
        $this->assertFalse($error);

        // any task submitted now should result in new processes created
        $taskS1 = $this->buildSyncTask();
        $taskS2 = $this->buildSyncTask();
        $taskS3 = $this->buildSyncTask();
        $pool->submitTask($taskS1)->otherwise($errorFn);
        $pool->submitTask($taskS2)->otherwise($errorFn);
        $pool->submitTask($taskS3)->otherwise($errorFn);
        $loop->run();
        $this->assertFalse($error);

        $this->assertEquals(4, $pool->info()[Stats::CURRENT_PROCESSES]);
        $this->assertEquals(4, $pool->info()[Stats::MAX_PROCESSES]);
        $this->assertEquals(5, $pool->info()[Stats::CURRENT_TASKS]);

        // all processes full a sync task now should be rejected
        $taskS4 = $this->buildSyncTask();
        $pool->submitTask($taskS4)->otherwise($errorFn);
        $loop->run();
        $this->assertInstanceOf(PoolException::class, $error);
        $error = false;

        // make one task finished to test TTL
        $taskS3->finishTask();
        $loop->run();
        $this->assertEquals(3, $pool->info()[Stats::CURRENT_PROCESSES]);
        $this->assertEquals(4, $pool->info()[Stats::MAX_PROCESSES]);
        $this->assertEquals(4, $pool->info()[Stats::CURRENT_TASKS]);

        // finish a task and submit a new one to see if ttl cancellation works
        $taskS2->failTask();
        $loop->addTimer(
            0.3,
            function () use ($pool, $taskS4) {
                $pool->submitTask($taskS4);
            }
        );
        $previous = $spy->getInvocationCount(); // spy checks how many times the spawn method is called
        $loop->run();

        $this->assertEquals($previous, $spy->getInvocationCount());
        $this->assertEquals(3, $pool->info()[Stats::CURRENT_PROCESSES]);
        $this->assertEquals(4, $pool->info()[Stats::MAX_PROCESSES]);
        $this->assertEquals(4, $pool->info()[Stats::CURRENT_TASKS]);
        $this->assertEquals(1, $pool->info()[Stats::FAILED_TASKS]);
        $this->assertEquals(1, $pool->info()[Stats::COMPLETED_TASKS]);
        $this->assertEquals(2, $pool->info()[Stats::TOTAL_TASKS]);


    }

    protected function generatePool(LoopInterface $loop, array $options = [])
    {
        $poolMock = $this->getMockBuilder(Pool::class)
                         ->setConstructorArgs(
                             [
                                 $this->prophesize(ProcessCollectionInterface::class)->reveal(),
                                 $loop,
                                 $options,
                             ]
                         )
                         ->setMethods(['spawn'])
                         ->getMock();

        $messenger = new ProcessAwareMessenger(
            $this->prophesize(Process::class)->reveal(),
            $this->prophesize(ConnectionInterface::class)->reveal()
        );
        $poolMock->expects($spy = $this->any())->method('spawn')->willReturnCallback(
            \Closure::fromCallable(
                function () use ($messenger) {
                    $this->startingProcesses++;
                    $this->buildWorker($messenger);

                    return resolve();
                }
            )->bindTo($poolMock, $poolMock)
        );

        return [$poolMock, $spy];
    }

    protected function buildSyncTask()
    {
        $task = new MultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);

        return new ProgressReporter($task);
    }

    protected function buildAsyncTask()
    {
        $task = new AsyncTask(uniqid(), ['timer' => 0.5, 'return' => mt_rand()]);

        return new ProgressReporter($task);
    }
}