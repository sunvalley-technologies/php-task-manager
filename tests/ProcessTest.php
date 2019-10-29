<?php

namespace SunValley\TaskManager\Tests;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\MethodProphecy;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\ConnectionInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\Process;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;

class ProcessTest extends TestCase
{

    public function testProcess()
    {
        $connection = $this->prophesize(ConnectionInterface::class);
        /** @var MethodProphecy $method */
        $method = $connection->write(Argument::type('string'));

        $messenger   = new Messenger($connection->reveal());
        $loop        = LoopFactory::create();
        $foundResult = null;
        Process::create($messenger, $loop)->on(
            'done',
            function (ProgressReporter $reporter) use (&$foundResult, $loop) {
                $foundResult = $reporter->getResult();
                $loop->stop();
            }
        );
        $options = ['number1' => 5, 'number2' => 10];
        $task    = new Fixtures\MultiplyTask(uniqid('task', true), $options);
        $messenger->callRpc('submit-task', new Payload(['task' => serialize($task)]));
        $loop->run();

        $this->assertEquals($options['number1'] * $options['number2'], $foundResult);
        $method->shouldHaveBeenCalledTimes(3);
    }

    public function testAsyncTaskProcess()
    {
        $connection = $this->prophesize(ConnectionInterface::class);
        /** @var MethodProphecy $method */
        $method = $connection->write(Argument::type('string'));

        $messenger = new Messenger($connection->reveal());
        $loop      = LoopFactory::create();
        /** @var TaskInterface[] $tasks */
        $tasks        = [
            'id1' => new Fixtures\AsyncTask('id1', ['timer' => mt_rand(10, 50) / 100, 'return' => 'ab1']),
            'id2' => new Fixtures\AsyncTask('id2', ['timer' => mt_rand(10, 50) / 100, 'return' => 'cd2']),
            'id3' => new Fixtures\AsyncTask('id3', ['timer' => mt_rand(10, 50) / 100, 'return' => 'x3']),
        ];
        $foundResults = [];

        Process::create($messenger, $loop)->on(
            'done',
            function (ProgressReporter $reporter) use (&$foundResults, $tasks, $loop) {
                $foundResults[$reporter->getTask()->getId()] = $reporter->getResult();

                if (count($foundResults) === count($tasks)) {
                    $loop->stop();
                }
            }
        );
        foreach ($tasks as $task) {
            $messenger->callRpc('submit-task', new Payload(['task' => serialize($task)]));
        }
        $loop->run();

        foreach ($tasks as $task) {
            $this->assertTrue(isset($foundResults[$task->getId()]));
            $this->assertEquals($task->getOptions()['return'], $foundResults[$task->getId()]);
        }
        $method->shouldHaveBeenCalledTimes(3);
    }
}