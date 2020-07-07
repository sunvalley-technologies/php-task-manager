<?php


namespace SunValley\TaskManager\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskStatus;
use SunValley\TaskManager\TaskStorage\RedisTaskStorage;
use SunValley\TaskManager\Tests\Fixtures\Task\TestMultiplyTask;
use function Clue\React\Block\await;
use function React\Promise\all;

class TaskStorageTest extends TestCase
{

    public function testRedisStorage()
    {
        $loop    = Factory::create();
        $storage = $this->generateRedisStorage($loop);
        $storage->clean();
        $task  = $this->buildTask();
        $task2 = $this->buildTask();
        $task3 = $this->buildTask();
        $task4 = $this->buildTask();
        $task5 = $this->buildTask();

        await($storage->insert($task), $loop);

        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($task2), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($task3), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($task4), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($this->buildTask()), $loop);
        await($storage->insert($task5), $loop);

        $total = await($storage->count(), $loop);
        $this->assertEquals(26, $total);
        /** @var ProgressReporter $fetchTask */
        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertNotNull($fetchTask);
        $this->assertInstanceOf(ProgressReporter::class, $fetchTask);
        $this->assertEquals($fetchTask->getTask()->getId(), $task->getId());

        $fetchTask->finishTask();
        await($storage->update($fetchTask), $loop);

        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertEquals($fetchTask->getStatus(), TaskStatus::COMPLETED());

        await($storage->cancel($task), $loop);
        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertEquals($fetchTask->getStatus(), TaskStatus::CANCELLED());

        // try finding by status
        $finishedCount = await($storage->countByStatus(true), $loop);
        $this->assertEquals(1, $finishedCount);
        $finishedCount = await($storage->countByStatus(false), $loop);
        $this->assertEquals(25, $finishedCount);

        // try scanning unfinished to see if finding by status is working
        /** @var ProgressReporter[] $unfinishedTasks */
        $unfinishedTasks = await($storage->findByStatus(false, 10, 5), $loop);
        $this->assertTrue(count($unfinishedTasks) >= 5);
        $this->assertTrue(count($unfinishedTasks) <= 7); // more or less
        // check if offset is working by checking task3 is in the list
        $found = false;
        foreach ($unfinishedTasks as $unfinishedTask) {
            if ($unfinishedTask->getTask()->getId() === $task3->getId()) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        // try delete
        await($storage->delete($task->getId()), $loop);
        $fetchTask = await($storage->findById($task->getId()), $loop);
        $this->assertNull($fetchTask);
        $finishedCount = await($storage->countByStatus(true), $loop);
        $this->assertEquals(0, $finishedCount);


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

    protected function buildTask()
    {
        $task = new TestMultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);

        return $task;
    }
}