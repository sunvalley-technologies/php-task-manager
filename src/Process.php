<?php

namespace SunValley\TaskManager;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Stream\Util;
use WyriHaximus\React\ChildProcess\Messenger\ChildInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Factory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use function React\Promise\reject;
use function React\Promise\resolve;

class Process extends EventEmitter implements ChildInterface
{

    /** @var TaskInterface[] */
    private $tasks = [];

    /**
     * @inheritDoc
     * @return static
     */
    public static function create(Messenger $messenger, LoopInterface $loop)
    {
        return new static($messenger, $loop);
    }

    /** @var LoopInterface */
    private $loop;

    /**
     * Worker constructor.
     *
     * @param Messenger     $messenger
     * @param LoopInterface $loop
     */
    protected function __construct(Messenger $messenger, LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->setupRpc($messenger);
        $messenger->on(
            'terminate',
            function () {
                foreach ($this->tasks as $task) {
                    if ($task instanceof ServiceTaskInterface) {
                        $task->terminateChild();
                    }
                }
            }
        );
    }

    /**
     * Setups RPC calls
     *
     * @param Messenger $messenger
     */
    protected function setupRpc(Messenger $messenger)
    {
        $genFn = function (callable $callback) {
            return function (Payload $payload, Messenger $messenger) use ($callback) {
                $retval = call_user_func($callback, $payload, $messenger);
                if (!$retval instanceof PromiseInterface) {
                    $retval = resolve($retval);
                }

                return $retval;
            };
        };

        $messenger->registerRpc('submit-task', $genFn([$this, 'submitTask']));
    }

    protected function submitTask(Payload $payload, Messenger $messenger)
    {
        $task = $payload['task'];
        $task = unserialize($task);
        if (!$task instanceof TaskInterface) {
            return reject(['error' => 'Internal Error! Task cannot be decoded!']);
        }

        if ($task instanceof LoopAwareInterface) {
            $task->setLoop($this->loop);
        }
        
        if ($task instanceof MessengerAwareServiceTaskInterface) {
            $task->handleChildMessenger($messenger);
        }

        $progressReporter = new ProgressReporter($task);
        $events           = ['done', 'failed', 'change'];
        Util::forwardEvents($progressReporter, $this, $events);
        foreach ($events as $event) {
            $progressReporter->on(
                $event,
                function (ProgressReporter $reporter) use ($messenger) {
                    $reporter = clone $reporter;
                    $reporter->removeAllListeners();

                    $messenger->rpc(Factory::rpc('task-report', ['report' => serialize($reporter)]));
                }
            );
        }

        $events = ['done', 'failed'];
        foreach ($events as $event) {
            $progressReporter->on(
                $event,
                function () use ($task) {
                    unset($this->tasks[$task->getId()]);
                }
            );
        }

        $this->tasks[$task->getId()] = $task;
        $task->run($progressReporter);

        return [];
    }
}