<?php

namespace SunValley\TaskManager;

use React\Promise\ExtendedPromiseInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Factory as RpcFactory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Rpc;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use SunValley\TaskManager\PoolOptions as Options;
use WyriHaximus\React\ChildProcess\Pool\Worker;
use function React\Promise\resolve;

/** @internal */
class PoolWorker extends Worker
{

    /** @var ProgressReporter[] */
    protected $tasks = [];
    
    /** @var ProcessAwareMessenger */
    protected $messenger;

    /** @var array */
    protected $options = [
        Options::TTL                  => 60,
        Options::MAX_JOBS_PER_PROCESS => 10,
    ];

    public function __construct(Messenger $messenger)
    {
        if (!$messenger instanceof ProcessAwareMessenger) {
            throw new \RuntimeException('Wrong messenger instance is sent to pool worker.');
        }
        parent::__construct($messenger);

        $messenger->registerRpc('task-report', \Closure::fromCallable([$this, 'handleProgressReport']));
    }

    public function setOptions(array $options)
    {
        $this->options = array_merge($this->options, $options);
    }

    protected function handleProgressReport(Payload $payload)
    {
        $report = $payload['report'];
        $report = unserialize($report);
        if ($report instanceof ProgressReporter) {
            $task = $report->getTask();
            if (isset($this->tasks[$task->getId()])) {
                $this->tasks[$task->getId()]->merge($report);
            }
        }

        return resolve([]);
    }

    /**
     * Submit a task
     *
     * @param ProgressReporter $reporter
     *
     * @return ExtendedPromiseInterface Resolves when task is submitted
     */
    public function submitTask(ProgressReporter $reporter): ExtendedPromiseInterface
    {
        foreach (['done', 'failed'] as $event) {
            $reporter->on(
                $event,
                function (ProgressReporter $reporter) {
                    $task = $reporter->getTask();
                    if (!$task instanceof LoopAwareInterface) {
                        $this->busy = false;
                    }

                    unset($this->tasks[$task->getId()]);
                    $this->emit('done', [$this]);
                }
            );
        }

        $task                        = $reporter->getTask();
        $this->tasks[$task->getId()] = $reporter;
        if (!$task instanceof LoopAwareInterface) {
            $this->busy = true;
        }

        return $this->messenger
            ->rpc(RpcFactory::rpc('submit-task', ['task' => serialize($task)]))
            ->otherwise(
                function () use ($task) {
                    if (!$task instanceof LoopAwareInterface) {
                        $this->busy = false;
                    }

                    unset($this->tasks[$task->getId()]);
                }
            );
    }

    public function rpc(Rpc $rpc)
    {
        throw new \BadMethodCallException('This method is not supported for this implementation.');
    }

    public function taskCount(): int
    {
        return count($this->tasks);
    }

    public function isBusy()
    {
        return parent::isBusy() || $this->taskCount() >= $this->options[Options::MAX_JOBS_PER_PROCESS];
    }

}