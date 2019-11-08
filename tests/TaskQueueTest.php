<?php

namespace SunValley\TaskManager\Tests;

use Clue\React\Redis\Client;
use Evenement\EventEmitterTrait;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\Exception\TaskQueueException;
use SunValley\TaskManager\Exception\TaskQueueRetryException;
use SunValley\TaskManager\LoopAwareInterface;
use SunValley\TaskManager\TaskQueue\InMemoryTaskQueue;
use SunValley\TaskManager\TaskQueue\RedisTaskQueue;
use SunValley\TaskManager\TaskStorageInterface;
use SunValley\TaskManager\Tests\Fixtures\AsyncTask;
use SunValley\TaskManager\Tests\Fixtures\MultiplyTask;
use function Clue\React\Block\await;
use function React\Promise\resolve;

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
        $task = await($queue->dequeue(), $loop);
        $this->assertSame($task1, $task);
        $this->assertEquals(4, $queue->count());
        $queue->complete($task);

        // dequeue async
        $task = await($queue->dequeue(true), $loop);
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
        $task = await($queue->dequeue(), $loop);
        try {
            $queue->cancel($task);

            $this->assertFalse(true, 'No exception thrown for delivered task');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function testRedisTaskQueue()
    {
        $loop           = Factory::create();
        $queue          = $this->generateRedisQueue($loop);
        $callbackCalled = false;
        $queue->onAvailableTask(
            function () use (&$callbackCalled) {
                $callbackCalled = true;
            }
        );

        $client = $this->generateRedisClient();
        $queue->setClient($client);
        $catchError   = false;
        $sentSyncTask = $this->buildSyncTask();
        await($queue->enqueue($sentSyncTask), $loop);
        try {
            await($queue->enqueue($sentSyncTask), $loop);

            $this->fail('Test failed because exception is not thrown');
        } catch (\Exception $exception) {
            $this->assertInstanceOf(TaskQueueException::class, $exception);
        }

        $this->assertFalse($catchError);
        $this->assertEquals(1, $queue->count());
        $this->assertTrue($callbackCalled);
        $task = await($queue->dequeue(true), $loop);
        $this->assertNull($task);
        $task = await($queue->dequeue(), $loop);
        $this->assertEquals($task->getId(), $sentSyncTask->getId());
        await($queue->complete($task), $loop);
        try {
            await($queue->cancel($task), $loop);

            $this->fail('Test failed because exception is not thrown');
        } catch (\Exception $exception) {
            $this->assertInstanceOf(TaskQueueException::class, $exception);
        }

        await($queue->enqueue($sentSyncTask), $loop);
        $this->assertEquals(1, $queue->count());
        await($queue->cancel($task), $loop);
        $this->assertEquals(0, $queue->count());
        try {
            $task = await($queue->dequeue(), $loop);

            $this->fail('Test failed because exception is not thrown');
        } catch (\Exception $exception) {
            $this->assertInstanceOf(TaskQueueRetryException::class, $exception);
        }

        await($queue->enqueue($sentSyncTask), $loop);
        $this->assertEquals(1, $queue->count());
        $task = await($queue->dequeue(), $loop);
        $this->assertEquals(0, $queue->count());
        await($queue->refund($task), $loop);
        $this->assertEquals(1, $queue->count());
        $task = await($queue->dequeue(), $loop);
        await($queue->fail($task), $loop);
        $this->assertEquals(0, $queue->count());

        try {
            await($queue->refund($task), $loop);

            $this->fail('Test failed because exception is not thrown');
        } catch (\Exception $exception) {
            $this->assertInstanceOf(TaskQueueException::class, $exception);
        }

        $sentAsyncTask = $this->buildAsyncTask();
        $sentSyncTask2 = $this->buildSyncTask();
        await($queue->enqueue($sentSyncTask), $loop);
        await($queue->enqueue($sentAsyncTask), $loop);
        await($queue->enqueue($sentSyncTask2), $loop);
        $this->assertEquals(3, $queue->count());

        $task = await($queue->dequeue(true), $loop);
        $this->assertInstanceOf(LoopAwareInterface::class, $task);
    }

    protected function generateRedisQueue($loop, $storage = null)
    {
        return new class($loop, $storage) extends RedisTaskQueue
        {

            public function __construct(
                LoopInterface $loop,
                ?TaskStorageInterface $storage = null
            ) {
                $this->loop        = $loop;
                $this->taskStorage = $storage;
            }

            /**
             * @param Client $subClient
             */
            public function setSubClient(Client $subClient): void
            {
                $this->subClient = $subClient;
            }

            /**
             * @param Client $client
             */
            public function setClient(Client $client): void
            {
                $this->client = $client;
            }

            protected function _publishTaskChange()
            {
                $this->_count();
            }
        };
    }

    protected function generateRedisClient()
    {
        return new class implements Client
        {
            use EventEmitterTrait;

            private $storage = [];
            private $cancellations = [];
            private $queue = [];

            public function __call($name, $args)
            {
            }

            public function end()
            {
            }

            public function close()
            {
            }

            function hexists($name, $key): ExtendedPromiseInterface
            {
                return resolve(isset($this->storage[$key]) ? 1 : 0);
            }

            function hset($name, $key, $value): ExtendedPromiseInterface
            {
                $this->storage[$key] = $value;

                return resolve(1);
            }

            function hdel($name, $key): ExtendedPromiseInterface
            {
                if (isset($this->storage[$key])) {
                    unset($this->storage[$key]);

                    return resolve(1);
                }


                return resolve(0);
            }

            function hget($name, $key): ExtendedPromiseInterface
            {
                if (isset($this->storage[$key])) {
                    return resolve($this->storage[$key]);
                }

                return resolve(null);
            }

            function llen($name): ExtendedPromiseInterface
            {
                if (!isset($this->queue[$name])) {
                    $this->queue[$name] = [];
                }

                return resolve(count($this->queue[$name]));
            }

            function lpush($name, $value): ExtendedPromiseInterface
            {
                if (!isset($this->queue[$name])) {
                    $this->queue[$name] = [];
                }

                $this->queue[$name][] = $value;

                return resolve(count($this->queue[$name]));
            }

            function rpop($name): ExtendedPromiseInterface
            {
                if (!isset($this->queue[$name])) {
                    $this->queue[$name] = [];
                }

                return resolve(array_shift($this->queue[$name]));
            }

            function rpush($name, $value): ExtendedPromiseInterface
            {
                if (!isset($this->queue[$name])) {
                    $this->queue[$name] = [];
                }

                array_unshift($this->queue[$name], $value);

                return resolve(count($this->queue[$name]));
            }

            function sadd($name, $value): ExtendedPromiseInterface
            {
                if (!in_array($value, $this->cancellations)) {
                    $this->cancellations[] = $value;

                    return resolve(1);
                }

                return resolve(0);
            }

            function srem($name, $value): ExtendedPromiseInterface
            {
                if (!in_array($value, $this->cancellations)) {
                    return resolve(0);
                }

                $cancellations = array_flip($this->cancellations);
                unset($cancellations[$value]);
                $this->cancellations = array_flip($cancellations);

                return resolve(1);
            }

            function sismember($name, $value): ExtendedPromiseInterface
            {
                return resolve(in_array($value, $this->cancellations) ? 1 : 0);
            }

            function scard($name): ExtendedPromiseInterface
            {
                return resolve(count($this->cancellations));
            }
        };
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