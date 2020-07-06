<?php

namespace SunValley\TaskManager\TaskStorage;

use Clue\React\Redis\Client as RedisClient;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\TaskStorageInterface;
use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Class RedisTaskStorage stores task results to a Redis hash.
 *
 * @package SunValley\TaskManager\TaskStorage
 */
class RedisTaskStorage implements TaskStorageInterface
{

    /** @var LoopInterface */
    protected $loop;

    /** @var string */
    protected $key;

    /** @var RedisClient */
    protected $client;

    /**
     * RedisTaskStorage constructor.
     *
     * @param LoopInterface $loop
     * @param string        $redisUri
     * @param string        $key Name of the hash key that the tasks will be stored. Group keys for sets are saved as
     *                           `$key_$group`
     */
    public function __construct(LoopInterface $loop, string $redisUri, string $key = 'ptm_tasks')
    {
        $this->loop   = $loop;
        $this->key    = $key;
        $redisFactory = new Factory($loop);
        $this->client = $redisFactory->createLazyClient($redisUri);
    }

    /** @inheritDoc */
    public function findById(string $taskId): PromiseInterface
    {
        return $this->client->hget($this->key, $taskId)->then(
            function ($value) {
                if ($value !== null) {
                    $reporter = unserialize($value);
                    if ($reporter instanceof ProgressReporter) {
                        return $reporter;
                    }
                }

                return resolve(null);
            }
        );
    }

    /**
     * Find tasks by their status
     *
     * @param bool $finished True if task is finished, false otherwise
     * @param int  $offset
     * @param int  $limit
     *
     * @return PromiseInterface|PromiseInterface<ProgressReporter[]>
     */
    public function findByStatus(bool $finished, int $offset, int $limit): PromiseInterface
    {
        $fromKey = !$finished ? $this->generateGroupKey('unfinished') : $this->generateGroupKey('finished');

        $data          = [];
        $currentOffset = 0;
        $count         = 10; // default
        $defer         = new Deferred();

        $iterationFn = function ($result) use (
            &$data,
            &$currentOffset,
            &$iterationFn,
            $count,
            $offset,
            $limit,
            $fromKey,
            $defer
        ) {
            [$cursor, $keys] = $result;

            // status = 0 => don't save and iterate
            // status = 1 => save and iterate
            // status = 2 => save and return

            $currentOffset = $currentOffset + $count;
            $cursor        = (int)$cursor;

            $sizeOfIteration = count($keys);
            $sizeOfData      = count($data);
            if ($currentOffset < $offset) {
                $status = 0;
            } else {
                $newSize = $sizeOfIteration + $sizeOfData;
                if ($newSize >= $limit) {
                    $status = 2;
                } else {
                    $status = 1;
                }
            }

            resolve()->then(
                function () use ($status, $keys, &$data) {
                    if ($status > 0) {
                        return call_user_func_array([$this->client, 'hmget'], array_merge([$this->key], $keys))->then(
                            function ($results) {
                                if (is_array($results)) {
                                    $found = [];

                                    foreach ($results as $result) {
                                        if ($result !== null) {
                                            $reporter = unserialize($result);
                                            if ($reporter instanceof ProgressReporter) {
                                                $found[] = $reporter;
                                            }
                                        }
                                    }

                                    return resolve($found);
                                }

                                return resolve([]);
                            }
                        )->then(
                            function ($results) use (&$data) {
                                $data = array_merge($data, $results);

                                return resolve();
                            }
                        );
                    }

                    return resolve();
                }
            )->then(
                function () use (&$data, $cursor, $status, $fromKey, $count, &$iterationFn, $defer) {
                    if ($cursor === 0 || $status === 2) {
                        $defer->resolve($data);

                        return;
                    }

                    $this->getLoop()->futureTick(
                        function ($fromKey, $count, &$iterationFn) use ($fromKey) {
                            $this->client->sscan($fromKey, 0, 'COUNT', $count)->then($iterationFn);
                        }
                    );
                }
            );
        };

        $cleanupFn = function ($v) use (&$data, &$currentOffset, &$iterationFn) {
            $data          = null;
            $currentOffset = null;
            $iterationFn   = null;

            return resolve($v);
        };

        $this->getLoop()->futureTick(
            function ($fromKey, $count, &$iterationFn) use ($fromKey) {
                $this->client->sscan($fromKey, 0, 'COUNT', $count)->then($iterationFn);
            }
        );

        return $defer->promise()->then($cleanupFn);
    }

    /** @inheritDoc */
    public function count(): PromiseInterface
    {
        return $this->client->hlen($this->key);
    }

    /** @inheritDoc */
    public function update(ProgressReporter $reporter): PromiseInterface
    {
        return $this->transactional(
            function () use ($reporter) {
                return all(
                    [
                        $this->client->hset(
                            $this->key,
                            $reporter->getTask()->getId(),
                            serialize(ProgressReporter::generateWaitingReporter($reporter->getTask()))
                        ),
                        $reporter->isCompleted() || $reporter->isFailed() ? $this->client->smove(
                            $this->generateGroupKey('unfinished'),
                            $this->generateGroupKey('finished'),
                            $reporter->getTask()->getId()
                        ) : resolve(),
                    ]
                );
            }
        );
    }

    /** @inheritDoc */
    public function insert(TaskInterface $task): PromiseInterface
    {
        return $this->transactional(
            function () use ($task) {
                return all(
                    [
                        $this->client->hset(
                            $this->key,
                            $task->getId(),
                            serialize(ProgressReporter::generateWaitingReporter($task))
                        ),
                        $this->client->sadd($this->generateGroupKey('unfinished'), $task->getId()),
                    ]
                );
            }
        );

    }

    /** @inheritDoc */
    public function cancel(TaskInterface $task): PromiseInterface
    {
        return $this->transactional(
            function () use ($task) {
                return all(
                    [
                        $this->client->hset(
                            $this->key,
                            $task->getId(),
                            serialize(ProgressReporter::generateCancelledReporter($task))
                        ),
                        $this->client->smove(
                            $this->generateGroupKey('unfinished'),
                            $this->generateGroupKey('finished'),
                            $task->getId()
                        ),
                    ]
                );
            }
        );
    }

    /** @inheritDoc */
    public function delete(string $taskId): PromiseInterface
    {
        return $this->transactional(
            function () use ($taskId) {
                return all(
                    [
                        $this->client->hdel($this->key, $taskId),
                        $this->client->srem($this->generateGroupKey('unfinished'), $taskId),
                        $this->client->srem($this->generateGroupKey('finished'), $taskId),
                    ]
                );
            }
        );

    }

    /** @inheritDoc */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * Run the given function in a Redis multi/exec
     *
     * @param callable $fn
     *
     * @return PromiseInterface
     */
    protected function transactional(callable $fn): PromiseInterface
    {
        return $this->client
            ->multi()
            ->then($fn)
            ->then([$this->client, 'exec']);
    }

    /**
     * Generate a sub-group key for sets
     *
     * @param string $group
     *
     * @return string
     */
    protected function generateGroupKey(string $group)
    {
        return $this->key . '_' . $group;
    }


}