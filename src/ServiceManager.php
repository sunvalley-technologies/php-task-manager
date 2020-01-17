<?php


namespace SunValley\TaskManager;


use Closure;
use Evenement\EventEmitter;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\PoolOptions as Options;
use SunValley\TaskManager\Service\ServiceOptions;
use SunValley\TaskManager\Service\ServiceStatus;
use WyriHaximus\FileDescriptors\Factory as FDFactory;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollection\Single;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollectionInterface;
use function escapeshellarg;
use function React\Promise\all;
use function React\Promise\resolve;

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

    /** @var ServiceStatus[] */
    protected $status = [];

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
        $childProcessPath = escapeshellarg(
            __DIR__ . $sp . '..' . $sp . 'bin' . $sp . 'php-task-manager-service.php'
        );
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->processOptions = [
            Options::FD_LISTER => FDFactory::create(),
            'childProcessPath' => $childProcessPath,
        ];
    }

    /**
     * Starts all services
     *
     * @return PromiseInterface<self> Resolves when all services are started (once). This promise never rejects. Check
     *                                individual addTask promises.
     */
    public function start(): PromiseInterface
    {
        if ($this->checkTimer === null) {
            $this->checkTimer = $this->loop->addPeriodicTimer(
                $this->checkInterval,
                Closure::fromCallable([$this, 'checkServices'])
            );
        }

        $promises = [];
        foreach ($this->status as $status) {
            $promises[] = $status->getStartPromise();
        }

        $defer = new Deferred();
        all($promises)->always(
            function () use ($defer) {
                $defer->resolve($this);
            }
        );

        return $defer->promise();
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
     * @param ServiceOptions|null  $options
     *
     * @return PromiseInterface<ProgressReporter> Promise resolves when the task is run for the first time or rejected
     *                                            if it fails for the first time.
     */
    public function addTask(ServiceTaskInterface $task, ?ServiceOptions $options = null): PromiseInterface
    {
        if (isset($this->status[$task->getId()])) {
            throw new InvalidArgumentException(
                sprintf('Given task %s already is in this service name manager', $task->getId())
            );
        }

        $status                       = new ServiceStatus($task, $options);
        $this->status[$task->getId()] = $status;

        return $status->getStartPromise();
    }

    protected function checkServices(): void
    {
        foreach ($this->status as $status) {
            // don't check if spawning
            if ($status->isSpawning()) {
                continue;
            }

            $worker   = $status->getWorker();
            $reporter = $status->getReporter();

            // don't check if already started and restart policy is not to restart
            $options = $status->getOptions();
            if ($this->terminateDefer === null) {
                if ($status->getStartedTimes() > 0 && $options->getRestartPolicy() > 0) {
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
                    if ($passed > $options->getTerminateTimeout()) {
                        $process = $status->getProcess();
                        $process->terminate($passed > $options->getTerminateTimeoutHard() ? 9 : 15);
                    }
                }

                continue;
            }

            if ($this->terminateDefer === null) {
                $this->spawn($status);
            }
        }

        // check termination
        if ($this->terminateDefer) {
            foreach ($this->status as $status) {
                if ($status->getWorker() !== null) {
                    return;
                }
            }

            // all terminated
            $this->loop->cancelTimer($this->checkTimer);
            $this->terminateDefer->resolve($this);
        }
    }

    protected function spawn(ServiceStatus $status): void
    {
        $reporter = $status->generateProgressReporter();
        $current  = $this->processCollection->current();
        $this->spawnAndGetMessenger($current)
             ->then(
                 function (ProcessAwareMessenger $messenger) use ($reporter, $status) {
                     $worker  = $this->buildWorker($messenger);
                     $process = $messenger->getProcess();
                     $process->on('exit', Closure::fromCallable([$status, 'stopTask']));
                     $task = $reporter->getTask();
                     if ($task instanceof LoopAwareInterface) {
                         $task->setLoop($this->loop);
                     }

                     if ($task instanceof MessengerAwareServiceTaskInterface) {
                         $task->handleMainMessenger($messenger);
                     }

                     $status->setProcess($process);

                     return $worker->submitTask($reporter);
                 }
             )
             ->then(Closure::fromCallable([$status, 'setWorker']))
             ->otherwise(Closure::fromCallable([$status, 'stopTask']));

        $this->processCollection->next();
        if (!$this->processCollection->valid()) {
            $this->processCollection->rewind();
        }
    }

    protected function buildWorker(Messenger $messenger)
    {
        $worker = new PoolWorker($messenger);
        $worker->setOptions([Options::MAX_JOBS_PER_PROCESS => 1]);
        $worker->on(
            'done',
            function (PoolWorker $worker) {
                $worker->terminate()->then(Closure::fromCallable([$this, 'checkServices']));
            }
        );

        return $worker;
    }

    protected function spawnAndGetMessenger(callable $current): ExtendedPromiseInterface
    {
        return $current($this->loop, $this->processOptions)->then(
            function ($timeoutOrMessenger) use ($current) {
                if ($timeoutOrMessenger instanceof Messenger) {
                    return resolve($timeoutOrMessenger);
                }

                return $this->spawnAndGetMessenger($current);
            }
        );
    }

    /**
     * Returns a task by its id.
     *
     * @param string $taskId
     *
     * @return ServiceTaskInterface
     * @throws InvalidArgumentException When task does not exist
     */
    public function getTaskById(string $taskId): ServiceTaskInterface
    {
        /** @var ServiceTaskInterface $task */
        $task = $this->getStatusById($taskId)->getReporter()->getTask();

        return $task;
    }

    /**
     * Get tasks
     *
     * @return iterable|ServiceTaskInterface[]
     */
    public function getTasks(): iterable
    {
        return array_map(
            function (ServiceStatus $status) {
                return $status->getReporter()->getTask();
            },
            $this->status
        );
    }

    /**
     * Get status information
     * 
     * @param TaskInterface $task
     *
     * @return ServiceStatusInterface
     */
    public function getStatus(TaskInterface $task): ServiceStatusInterface
    {
        return $this->getStatusById($task->getId());
    }

    /**
     * Get status information by id
     * 
     * @param string $taskId
     *
     * @return ServiceStatusInterface
     */
    public function getStatusById(string $taskId): ServiceStatusInterface
    {
        if (!isset($this->status[$taskId])) {
            throw new InvalidArgumentException(sprintf('Given task ID `%s` does not exist', $taskId));
        }

        return $this->status[$taskId];
    }

}

