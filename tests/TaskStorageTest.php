<?php


namespace SunValley\TaskManager\Tests;

use Clue\React\Redis\Client;
use Evenement\EventEmitterTrait;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskStatus;
use SunValley\TaskManager\TaskStorage\RedisTaskStorage;
use SunValley\TaskManager\Tests\Fixtures\Task\TestMultiplyTask;
use function Clue\React\Block\await;
use function React\Promise\resolve;

class TaskStorageTest extends TestCase
{

    public function testRedisStorage()
    {
        $loop    = Factory::create();
        $storage = $this->generateRedisStorage($loop);
        $client  = $this->generateRedisClient();
        $storage->setClient($client);
        $task = $this->buildTask();

        // testing getting all before insertion
        $total = await($storage->count(), $loop);
        $this->assertEquals(0, $total);

        $all = await($storage->findAll(), $loop);
        $this->assertEquals([], $all);

        await($storage->insert($task), $loop);
        $total = await($storage->count(), $loop);
        $this->assertEquals(1, $total);

        // testing getting all right after the insertion
        $all = await($storage->findAll(), $loop);
        $this->assertIsArray($all);
        $this->assertCount(1, $all);
        $this->assertInstanceOf(ProgressReporter::class, $all[0]);

        /** @var ProgressReporter $fetchTask */
        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertNotNull($fetchTask);
        $this->assertInstanceOf(ProgressReporter::class, $fetchTask);
        $this->assertEquals($fetchTask->getTask()->getId(), $task->getId());

        $fetchTask->finishTask();
        await($storage->update($fetchTask), $loop);

        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertEquals($fetchTask->getStatus(), TaskStatus::COMPLETED());

        $storage->cancel($task);
        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertEquals($fetchTask->getStatus(), TaskStatus::CANCELLED());

        $storage->delete($task->getId());
        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertNull($fetchTask);

    }

    protected function generateRedisStorage($loop)
    {
        return new class($loop) extends RedisTaskStorage
        {

            public function __construct(LoopInterface $loop)
            {
                $this->loop = $loop;
            }

            /**
             * @param Client $client
             */
            public function setClient(Client $client): void
            {
                $this->client = $client;
            }

        };
    }

    protected function generateRedisClient()
    {
        return new class implements Client
        {
            use EventEmitterTrait;

            private $storage = [];

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

            function hgetall($hashKey): ExtendedPromiseInterface
            {
                return resolve($this->storage);
            }

            function hlen($name): ExtendedPromiseInterface
            {

                return resolve(count($this->storage));
            }


            public function __call($name, $args)
            {
            }

            public function end()
            {
            }

            public function close()
            {
            }


        };
    }

    protected function buildTask()
    {
        $task = new TestMultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);

        return $task;
    }
}