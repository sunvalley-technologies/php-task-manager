<?php


namespace SunValley\TaskManager;


use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\PoolOptions as Options;
use WyriHaximus\FileDescriptors\Factory as FDFactory;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollection\Single;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollectionInterface;
use React\ChildProcess\Process as ReactProcess;

/**
 * ServiceManager provides a way to run a pool of tasks like services. Given tasks are restarted according to given
 * restart policy.
 *
 * @package SunValley\TaskManager
 */
class ServiceManager extends EventEmitter
{

    /** @var int Always restart the service */
    const RESTART_POLICY_ALWAYS = -1;

    /** @var int Do not restart service */
    const RESTART_POLICY_NO = 1;

    /** @var int Do not restart service and do not emit error event */
    const RESTART_POLICY_NO_ERROR = 1;

    /** @var LoopInterface */
    protected $loop;

    /** @var ProcessCollectionInterface */
    protected $processCollection;

    /** @var array */
    protected $tasks = [];

    /** @var array */
    protected $processOptions;

    /** @var int|float */
    protected $checkInterval = 1;

    /** @var TimerInterface */
    protected $checkTimer;

    /** @var Deferred */
    protected $terminateDefer;

    /**
     * ServiceManager constructor.
     *
     * @param LoopInterface $loop
     */
    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;

        // Setup process collection
        $this->processCollection = new Single(new ProcessLauncher());

        $sp               = DIRECTORY_SEPARATOR;
        $childProcessPath = \escapeshellarg(
            __DIR__ . $sp . '..' . $sp . 'bin' . $sp . 'php-task-manager-service.php'
        );
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->processOptions = [
            Options::FD_LISTER => FDFactory::create(),
            'childProcessPath' => $childProcessPath,
        ];

        $this->checkTimer = $loop->addPeriodicTimer(
            $this->checkInterval,
            \Closure::fromCallable([$this, 'checkServices'])
        );
    }

    /**
     * Terminates all services and send them termination signal.
     *
     * @return PromiseInterface<self> Resolves when all services and workers are terminated
     */
    public function terminate(): PromiseInterface
    {
        if ($this->terminateDefer) {
            return $this->terminateDefer->promise();
        }

        $this->terminateDefer = new Deferred();

        return $this->terminateDefer->promise();
    }

    /**
     * Add a task to be watched over
     *
     * @param ServiceTaskInterface $task
     * @param int                  $restartPolicy
     *
     * @return PromiseInterface<ProgressReporter> Promise resolves when the task is run for the first time.
     */
    public function addTask(
        ServiceTaskInterface $task,
        int $restartPolicy = self::RESTART_POLICY_ALWAYS
    ): PromiseInterface {
        if (isset($this->tasks[$task->getId()])) {
            throw new \InvalidArgumentException(
                sprintf('Given task %s already is in this service name manager', $task->getId())
            );
        }

        $policies = [static::RESTART_POLICY_ALWAYS, static::RESTART_POLICY_NO, static::RESTART_POLICY_NO_ERROR];
        if (!in_array($restartPolicy, $policies, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid restart policy %d is given!', $restartPolicy));
        }

        $deferred                    = new Deferred();
        $this->tasks[$task->getId()] = [
            'reporter'        => new ProgressReporter($task),
            'restart'         => $restartPolicy,
            'options'         => [
                'terminate_timeout'      => 5,
                'terminate_timeout_hard' => 10,
            ],
            'worker'          => null,
            'spawn'           => false,
            'started'         => 0,
            'first_terminate' => 0,
            'error'           => null,
            'process'         => null,
            'start_defer'     => $deferred,
        ];

        return $deferred->promise();
    }

    protected function checkServices(): void
    {
        foreach ($this->tasks as $task) {
            // don't check if spawning
            if ($task['spawn']) {
                continue;
            }

            // don't check if already started and restart policy is not to restart
            /** @var PoolWorker $worker */
            $worker = $task['worker'];

            /** @var ProgressReporter $reporter */
            $reporter = $task['reporter'];

            if ($this->terminateDefer === null) {
                if ($task['started'] > 0 && $task['restart'] > 0) {
                    continue;
                }

                if ($worker !== null && $worker->taskCount() === 1 &&
                    !$reporter->isCompleted() && !$reporter->isFailed()) {
                    continue;
                }
            }

            if ($worker !== null) {
                if (!$worker->isTerminated()) {
                    $worker->terminate();
                } else {
                    $passed = time() - $worker->getTerminationTimestamp();
                    if ($passed > $task['options']['terminate_timeout']) {
                        /** @var ReactProcess $process */
                        $process = $task['process'];
                        $process->terminate($passed > $task['options']['terminate_timeout_hard'] ? 9 : 15);
                    }
                }

                continue;
            }

            if ($this->terminateDefer === null) {
                $this->spawn($reporter);
            }
        }

        // check termination
        if ($this->terminateDefer) {
            foreach ($this->tasks as $task) {
                if ($task['worker'] !== null) {
                    return;
                }
            }

            // all terminated
            $this->loop->cancelTimer($this->checkTimer);
            $this->terminateDefer->resolve($this);
        }
    }

    protected function spawn(ProgressReporter $reporter): PromiseInterface
    {
        $this->tasks[$reporter->getTask()->getId()]['spawn'] = true;
        $this->tasks[$reporter->getTask()->getId()]['started']++;
        $current = $this->processCollection->current();
        $promise = $this->spawnAndGetMessenger($current);
        $promise
            ->then(
                function (ProcessAwareMessenger $messenger) use ($reporter, &$worker) {
                    $worker  = $this->buildWorker($messenger);
                    $process = $messenger->getProcess();
                    $process->on(
                        'exit',
                        function () use ($reporter) {
                            $this->clearTask($reporter->getTask());
                        }
                    );
                    $this->tasks[$reporter->getTask()->getId()]['process'] = $process;

                    return $worker->submitTask($reporter);
                }
            )
            ->then(
                function (PoolWorker $worker) use ($reporter) {
                    $this->tasks[$reporter->getTask()->getId()]['worker'] = $worker;
                    $this->tasks[$reporter->getTask()->getId()]['spawn']  = false;
                    if ($this->tasks[$reporter->getTask()->getId()]['start_defer']) {
                        /** @var Deferred $deferred */
                        $deferred = $this->tasks[$reporter->getTask()->getId()]['start_defer'];
                        $deferred->resolve($reporter);
                    }
                    $this->tasks[$reporter->getTask()->getId()]['start_defer'] = null;
                }
            )
            ->otherwise(
                function () use ($reporter) {
                    $this->clearTask($reporter->getTask());
                }
            );

        $this->processCollection->next();
        if (!$this->processCollection->valid()) {
            $this->processCollection->rewind();
        }

        return $promise;

    }

    protected function clearTask(TaskInterface $task)
    {
        $this->tasks[$task->getId()]['process'] = null;
        $this->tasks[$task->getId()]['worker']  = null;
        $this->tasks[$task->getId()]['spawn']   = false;
    }

    protected function buildWorker(Messenger $messenger)
    {
        $worker = new PoolWorker($messenger);
        $worker->setOptions([Options::MAX_JOBS_PER_PROCESS => 1]);
        $worker->on(
            'done',
            function (PoolWorker $worker) {
                $worker->terminate()->then(\Closure::fromCallable([$this, 'checkServices']));
            }
        );

        return $worker;
    }

    protected function spawnAndGetMessenger(callable $current): ExtendedPromiseInterface
    {
        return $current($this->loop, $this->processOptions)->then(
            function ($timeoutOrMessenger) use ($current) {
                if ($timeoutOrMessenger instanceof Messenger) {
                    return \React\Promise\resolve($timeoutOrMessenger);
                }

                return $this->spawnAndGetMessenger($current);
            }
        );
    }

}