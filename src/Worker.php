<?php

namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use WyriHaximus\React\ChildProcess\Messenger\ChildInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use function React\Promise\resolve;

class Worker implements ChildInterface
{

    /** @inheritDoc */
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
                $retval         = call_user_func($callback, $payload);
                $retval['uuid'] = $payload['uuid'];
                $retval         = resolve($retval);
                
                return $retval;
            };
        };

        $messenger->registerRpc('submitTask', $genFn([$this, 'submitTask']));
    }

    protected function submitTask(Payload $payload)
    {
        $task = $payload['task'];
        $task = unserialize($task);
        if (!$task instanceof TaskInterface) {
            return ['error' => 'Internal Error! Task cannot be decoded!'];
        }

        if ($task instanceof LoopAwareInterface) {
            $task->setLoop($this->loop);
        }

        $progressReporter = new ProgressReporter($task);

        $task->run($progressReporter);

        return ['success' => true];
    }
}