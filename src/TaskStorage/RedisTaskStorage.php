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

    public function countByStatus(bool $finished): PromiseInterface
    {
        $fromKey = !$finished ? $this->generateGroupKey('unfinished') : $this->generateGroupKey('finished');

        return $this->client->llen($fromKey);
    }

    /**
     * @inheritDoc
     */
    public function findByStatus(bool $finished, int $offset, int $limit): PromiseInterface
    {
        $fromKey = !$finished ? $this->generateGroupKey('unfinished') : $this->generateGroupKey('finished');

        return $this->client->lrange($fromKey, $offset, $offset + $limit - 1)->then(
            function ($keys = null) {
                if (is_array($keys)) {
                    return call_user_func_array([$this->client, 'hmget'], array_merge([$this->key], $keys))->then(
                        function ($reporters = null) {
                            if (is_array($reporters)) {
                                $found = [];

                                foreach ($reporters as $result) {
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
                    );
                }

                return resolve([]);
            }
        );
    }

    /** @inheritDoc */
    public function count(): PromiseInterface
    {
        return $this->client->hlen($this->key);
    }

    /** @inheritDoc */
    public function update(ProgressReporter $reporter): PromiseInterface
    {
        $taskId = $reporter->getTask()->getId();

        return resolve(
            all(
                [
                    $this->client->hset(
                        $this->key,
                        $taskId,
                        serialize($reporter)
                    ),
                    ($reporter->isCompleted() || $reporter->isFailed()) ?
                        $this->client->lrem($this->generateGroupKey('unfinished'), 1, $taskId) : resolve(),
                    ($reporter->isCompleted() || $reporter->isFailed()) ?
                        $this->client->lrem($this->generateGroupKey('finished'), 1, $taskId) : resolve(),
                    ($reporter->isCompleted() || $reporter->isFailed()) ?
                        $this->client->lpush($this->generateGroupKey('finished'), $taskId) : resolve(),
                ]
            )
        );

    }

    /** @inheritDoc */
    public function insert(TaskInterface $task): PromiseInterface
    {
        return resolve(
            all(
                [
                    $this->client->hset(
                        $this->key,
                        $task->getId(),
                        serialize(ProgressReporter::generateWaitingReporter($task))
                    ),
                    $this->client->lpush($this->generateGroupKey('unfinished'), $task->getId()),
                ]
            )
        );

    }

    /** @inheritDoc */
    public function cancel(TaskInterface $task): PromiseInterface
    {
        return resolve(
            all(
                [
                    $this->client->hset(
                        $this->key,
                        $task->getId(),
                        serialize(ProgressReporter::generateCancelledReporter($task))
                    ),
                    $this->client->lrem($this->generateGroupKey('unfinished'), 1, $task->getId()),
                    $this->client->lrem($this->generateGroupKey('finished'), 1, $task->getId()),
                    $this->client->lpush($this->generateGroupKey('finished'), $task->getId()),
                ]
            )
        );
    }

    /** @inheritDoc */
    public function delete(string $taskId): PromiseInterface
    {
        $this->client->hdel($this->key, $taskId);
        $this->client->lrem($this->generateGroupKey('unfinished'), 1, $taskId);
        $this->client->lrem($this->generateGroupKey('finished'), 1, $taskId);

        return resolve();
    }

    /** @inheritDoc */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
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