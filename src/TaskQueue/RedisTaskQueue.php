<?php

namespace SunValley\TaskManager\TaskQueue;

use Clue\React\Redis\Client as RedisClient;
use Clue\React\Redis\Factory as RedisFactory;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\Exception\TaskQueueRetryException;
use SunValley\TaskManager\Exception\TaskQueueException;
use SunValley\TaskManager\LoopAwareInterface;
use SunValley\TaskManager\Stats;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\TaskStorageInterface;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Class RedisTaskQueue utilizes redis lists to provide a task queue.
 *
 * This implementation can be used safely from different processes which makes the task manager scalable on many
 * instances. Internally this implementation uses two lists as queues one for sync tasks and one for async tasks. There
 * is also a hash that stores serialized task data and a set that stores canceled tasks.
 *
 * Since there are two different queues for sync and async tasks, task execution depends on the availability of the
 * workers. In a full queue, async tasks are always favored over sync tasks.
 *
 *
 * @package SunValley\TaskManager\TaskQueue
 */
class RedisTaskQueue extends AbstractTaskQueue
{

    protected const ASYNC_QUEUE = 'php_task_manager_async_queue';
    protected const SYNC_QUEUE = 'php_task_manager_sync_queue';
    protected const TASK_CHANGE_CHANNEL = 'php_task_manager_submit_channel';
    protected const TASK_STORAGE = 'php_task_manager_storage';
    protected const CANCEL_SET = 'php_task_manager_cancellations';

    /** @var RedisClient */
    protected $client;

    /** @var RedisClient */
    protected $subClient;

    /** @var callable */
    protected $onAvailableCallback;

    /** @var int */
    protected $asyncTaskCount = 0;

    /** @var int */
    protected $syncTaskCount = 0;

    /** @var int */
    protected $canceledTaskCount = 0;

    /** @var bool */
    protected $lastDequeueCheckIsAsync = false;

    public function __construct(string $redisUri, LoopInterface $loop, ?TaskStorageInterface $storage = null)
    {
        parent::__construct($loop, $storage);
        $redisFactory    = new RedisFactory($loop);
        $this->client    = $redisFactory->createLazyClient($redisUri);
        $this->subClient = $redisFactory->createLazyClient($redisUri);
    }

    /** @inheritDoc */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /** @inheritDoc */
    public function enqueue(TaskInterface $task): ExtendedPromiseInterface
    {
        return $this->client
            ->hexists(static::TASK_STORAGE, $task->getId())
            ->then(
                function ($result) use ($task) {
                    if ($result) {
                        return reject(new TaskQueueException('Task already exists in this queue'));
                    } else {
                        return $this->client->hset(static::TASK_STORAGE, $task->getId(), serialize($task));
                    }
                }
            )
            ->then(
                function ($result) use ($task) {
                    if ($result > 0) {
                        if ($task instanceof LoopAwareInterface) {
                            $promise = $this->client->lpush(static::ASYNC_QUEUE, $task->getId());
                        } else {
                            $promise = $this->client->lpush(static::SYNC_QUEUE, $task->getId());
                        }

                        return $promise->then(
                            function () use ($task) {
                                $this->taskStorage !== null && $this->taskStorage->insert($task);
                                $this->_publishTaskChange();

                                return resolve();
                            },
                            function () {
                                return reject(new TaskQueueException('Failed to append task to queue'));
                            }
                        );
                    }

                    return reject(new TaskQueueException('Failed to save the task'));
                }
            );
    }

    /** @inheritDoc */
    public function enqueueRemote(TaskInterface $task): ExtendedPromiseInterface
    {
        return $this->enqueue($task);
    }

    /** @inheritDoc */
    public function dequeue(bool $asyncOnly = false): ExtendedPromiseInterface
    {

        if ($asyncOnly || !$this->lastDequeueCheckIsAsync) {
            $this->lastDequeueCheckIsAsync = true;
            $promise                       = $this->_dequeueAsync();
            $nextAsync                     = false;
        } else {
            $this->lastDequeueCheckIsAsync = false;
            $promise                       = $this->_dequeueSync();
            $nextAsync                     = true;
        }

        /** @var ExtendedPromiseInterface $retval */
        $retval = $promise->then(
            function (?TaskInterface $value) use ($asyncOnly, $nextAsync) {
                if ($value === null) {
                    if ($asyncOnly) {
                        return resolve(null);
                    }

                    if ($nextAsync) {
                        return $this->_dequeueAsync();
                    } else {
                        return $this->_dequeueSync();
                    }
                }

                return $value;
            }
        )->then(
            function ($value) {
                $this->_count();

                return $value;
            }
        );

        return $retval;
    }

    protected function _dequeueSync(): ExtendedPromiseInterface
    {
        return $this->client->rpop(static::SYNC_QUEUE)
                            ->then(
                                function ($taskId) {
                                    return $this->_handleCancellation($taskId);
                                }
                            )
                            ->then(
                                function ($taskId) {
                                    return $this->_handleDequeue($taskId);
                                }
                            );
    }

    protected function _dequeueAsync(): ExtendedPromiseInterface
    {
        return $this->client->rpop(static::ASYNC_QUEUE)
                            ->then(
                                function ($taskId) {
                                    return $this->_handleCancellation($taskId);
                                }
                            )
                            ->then(
                                function ($taskId) {
                                    return $this->_handleDequeue($taskId);
                                }
                            );
    }

    protected function _handleCancellation(?string $taskId)
    {
        if ($taskId === null) {
            return resolve(null);
        }

        return $this->client->sismember(static::CANCEL_SET, $taskId)->then(
            function ($value) use ($taskId) {
                if ($value) {
                    $this->_cleanTask($taskId);

                    return reject(new TaskQueueRetryException());
                } else {
                    return resolve($taskId);
                }
            },
            function () use ($taskId) {
                return reject(
                    new TaskQueueException(
                        sprintf('Task is failed to be checked from cancellation set for task %s', $taskId)
                    )
                );
            }
        );
    }

    protected function _handleDequeue(?string $taskId): PromiseInterface
    {
        if ($taskId === null) {
            return resolve(null);
        }

        return $this->client->hget(static::TASK_STORAGE, $taskId)->then(
            function ($rawTask) use ($taskId) {
                if ($rawTask === null) {
                    return reject(
                        new TaskQueueException(sprintf('Task cannot be found in storage for task %s', $taskId))
                    );
                }

                $task = unserialize($rawTask);
                if ($task instanceof TaskInterface) {
                    return $task;
                }

                return reject(new TaskQueueException(sprintf('De-serialization failed for task %s', $taskId)));
            },
            function () use ($taskId) {
                return reject(
                    new TaskQueueException(sprintf('Task is failed to be retrieved from storage for task %s', $taskId))
                );
            }
        );
    }

    /** @inheritDoc */
    public function cancel(TaskInterface $task): ExtendedPromiseInterface
    {
        return $this->client
            ->hexists(static::TASK_STORAGE, $task->getId())
            ->then(
                function ($result) use ($task) {
                    if (!$result) {
                        return reject(new TaskQueueException('Task does not exist in this queue'));
                    } else {
                        return $this->client->sadd(static::CANCEL_SET, $task->getId())->then(
                            function () use ($task) {
                                $this->_publishTaskChange();
                                $this->taskStorage !== null && $this->taskStorage->cancel($task);
                            }
                        );
                    }
                }
            );
    }

    /** @inheritDoc */
    public function cancelRemote(TaskInterface $task): ExtendedPromiseInterface
    {
        return $this->cancel($task);
    }

    /** @inheritDoc */
    public function complete(TaskInterface $task): ExtendedPromiseInterface
    {
        $this->_cleanTask($task->getId());

        return resolve();
    }

    /** @inheritDoc */
    public function fail(TaskInterface $task): ExtendedPromiseInterface
    {
        $this->_cleanTask($task->getId());

        return resolve();
    }

    protected function _cleanCancellation(string $taskId)
    {
        $this->client->srem(static::CANCEL_SET, $taskId);
        $this->_publishTaskChange();
    }
    
    protected function _cleanTask(string $taskId)
    {
        $this->client->hdel(static::TASK_STORAGE, $taskId);
        $this->_cleanCancellation($taskId);
    }

    /** @inheritDoc */
    public function refund(TaskInterface $task): ExtendedPromiseInterface
    {
        return $this->client
            ->hexists(static::TASK_STORAGE, $task->getId())
            ->then(
                function ($result) use ($task) {
                    if (!$result) {
                        return reject(new TaskQueueException('Task does not exist in this queue'));
                    } else {
                        if ($task instanceof LoopAwareInterface) {
                            $promise = $this->client->rpush(static::ASYNC_QUEUE, $task->getId());
                        } else {
                            $promise = $this->client->rpush(static::SYNC_QUEUE, $task->getId());
                        }

                        return $promise->then(
                            function ($value) {
                                $this->_publishTaskChange();

                                return $value;
                            }
                        );

                    }
                }
            );
    }

    /** @inheritDoc */
    public function onAvailableTask(callable $callback): void
    {
        $this->onAvailableCallback = $callback;
    }

    /** @inheritDoc */
    public function count(): int
    {
        return $this->syncTaskCount + $this->asyncTaskCount - $this->canceledTaskCount;
    }

    /** @inheritDoc */
    public function close(): ExtendedPromiseInterface
    {
        $this->client->end();
        $this->subClient->close();

        return resolve();
    }

    /** @inheritDoc */
    public function info(): array
    {
        return [
            Stats::CURRENT_TASKS => $this->count(),
        ];
    }

    public function start(): void
    {
        $this->subClient->subscribe(static::TASK_CHANGE_CHANNEL);
        $this->subClient->on(
            'message',
            function ($channel, $payload) {
                if ($channel !== static::TASK_CHANGE_CHANNEL) {
                    return;
                }

                $this->_count();
            }
        );
    }

    protected function _count()
    {
        return all(
            [
                $this->client->llen(static::SYNC_QUEUE)->then(
                    function ($count) {
                        $this->syncTaskCount = (int)$count;

                        return resolve();
                    }
                ),
                $this->client->llen(static::ASYNC_QUEUE)->then(
                    function ($count) {
                        $this->asyncTaskCount = (int)$count;

                        return resolve();
                    }
                ),
                $this->client->scard(static::CANCEL_SET)->then(
                    function ($count) {
                        $this->canceledTaskCount = (int)$count;

                        return resolve();
                    }
                ),

            ]
        )->then(
            function () {
                if ($this->count() > 0) {
                    call_user_func($this->onAvailableCallback);
                }
            }
        );
    }

    /** reports other instances to trigger _count basically */
    protected function _publishTaskChange()
    {
        $this->client->publish(static::TASK_CHANGE_CHANNEL);
    }
}