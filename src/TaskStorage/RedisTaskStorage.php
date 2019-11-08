<?php

namespace SunValley\TaskManager\TaskStorage;

use Clue\React\Redis\Client as RedisClient;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\TaskStorageInterface;
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
     * @param string        $key Name of the hash key that the tasks will be stored
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

    /** @inheritDoc */
    public function count(): PromiseInterface
    {
        return $this->client->hlen($this->key);
    }

    /** @inheritDoc */
    public function update(ProgressReporter $reporter): PromiseInterface
    {
        return $this->client->hset(
            $this->key,
            $reporter->getTask()->getId(),
            serialize($reporter)
        );
    }

    /** @inheritDoc */
    public function insert(TaskInterface $task): PromiseInterface
    {
        return $this->client->hset(
            $this->key,
            $task->getId(),
            serialize(ProgressReporter::generateWaitingReporter($task))
        );
    }

    /** @inheritDoc */
    public function cancel(TaskInterface $task): PromiseInterface
    {
        return $this->client->hset(
            $this->key,
            $task->getId(),
            serialize(ProgressReporter::generateCancelledReporter($task))
        );
    }

    /** @inheritDoc */
    public function delete(string $taskId): PromiseInterface
    {
        return $this->client->hdel($this->key, $taskId);
    }
}