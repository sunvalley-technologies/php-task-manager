<?php


namespace SunValley\TaskManager\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskStatus;
use SunValley\TaskManager\TaskStorage\RedisTaskStorage;
use SunValley\TaskManager\Tests\Fixtures\Task\TestMultiplyTask;
use function Clue\React\Block\await;

class TaskStorageTest extends TestCase
{

    public function testRedisStorage()
    {
        $loop    = Factory::create();
        $storage = $this->generateRedisStorage($loop);
        $task    = $this->buildTask();
        await($storage->insert($task), $loop);
        $total = await($storage->count(), $loop);
        $this->assertEquals(1, $total);
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
        $redisUri = $_SERVER['REDIS_URI'] ?? $_ENV['REDIS_URI'] ?? 'redis://localhost:6379';

        return new RedisTaskStorage($loop, $redisUri);
    }

    protected function buildTask()
    {
        $task = new TestMultiplyTask(uniqid(), ['number1' => mt_rand(), 'number2' => mt_rand()]);

        return $task;
    }
}