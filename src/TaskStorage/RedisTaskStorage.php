<?php

namespace SunValley\TaskManager\TaskStorage;

use Clue\React\Redis\Client as RedisClient;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use function React\Promise\all;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskInterface;
use SunValley\TaskManager\TaskStorageInterface;
use function React\Promise\reject;
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

    private function somethingInternal(Deferred $deferred, array $accumulator, int $cursor = null)
    {
        /** @var PromiseInterface $promise */
        $promise = $client->hscan($key, $cursor);

        $promise->then(
            function() use ($deferred, &$accumulator) {
                $cursor = 123;

                if ($cursor != 0) {
                    $this->getLoop()->futureTick(function() use ($deferred, &$accumulator, $cursor) {
                        $this->somethingInternal($deferred, $accumulator, $cursor);
                    });
                } else {
                    $deferred->resolve($accumulator);
                }
            }
        );
    }


    private function processOneHscanResultData(array $data, array &$resultsAccumulator)
    {

        while(true) {
            array_shift($data); // we do not care about task id
            $serializedReporter = array_shift($data);

            try {
                $reporter = unserialize($serializedReporter);
                if ($reporter instanceof ProgressReporter) {
                    $resultsAccumulator[] = $reporter;
                }
            } catch (\Exception $e) {
                // deliberately just swallowing the exception to make it stable in case of some junk it might find
            }


            if (count($data) == 0) {
                break;
            }
        }
    }


    private function processOneBatch(Deferred $deferred, int $cursor, string $pattern, array &$accumulator = null)
    {

        if (null === $accumulator) {
            $accumulator = [];
        }

        /** @var PromiseInterface $promise */
        $promise = $this->client->hscan($this->key, $cursor, 'MATCH', $pattern);

        $promise->then(
            function($rawValue) use ($deferred, &$accumulator, $pattern) {

                $cursor = $rawValue[0];

                if (!is_string($cursor)) {
                    return reject('first field in hscan result should be a string holding a cursor value.');
                }

                if (!is_numeric($cursor)) {
                    return reject('this cursor value is not numeric: ' . $cursor);
                }

                $data = $rawValue[1];

                $this->processOneHscanResultData($data, $accumulator);

                if ($cursor != 0) {
                    $this->getLoop()->futureTick(function() use ($deferred, $cursor, $pattern, &$accumulator) {
                        $this->processOneBatch($deferred, $cursor, $pattern, $accumulator);
                    });
                } else {
                    $deferred->resolve($accumulator);
                }

                return null;
            }
        );
    }

    /**
     * @param array $states
     *
     * searches for the tasks in each of the requested states and returns a joined promise.
     *
     * The glob pattern for SCAN does not support "OR" expressions
     *
     * @return PromiseInterface
     */
    public function findAllInStates(array $states): PromiseInterface
    {
        $perStatePromises = [];

        foreach ($states as $state) {
            $pattern = $this->createHscanMatchingPatternForState($state);
            $perStatePromises[] = $this->findAllMatchingPattern($pattern);
        }

        return all($perStatePromises);
    }

    /**
     * This function creates a substring that should be expected to be found
     * in serialized form of a ProgressReporter object and packs it into
     * a glob-style expression.
     *
     * @see https://user-images.githubusercontent.com/21345604/86157366-f787d800-bb0f-11ea-940c-2ff01cdcfaa9.png
     *
     * @param string $state
     *
     * @return string
     */``
    private function createHscanMatchingPatternForState(string $state): string
    {
        return ':\"' . $state . '\";';
    }


    /**
     * @inheritDoc
     */
    private function findAllMatchingPattern(string $pattern): PromiseInterface
    {
        $deferred = new Deferred();
        $this->processOneBatch($deferred, 0, $pattern);
        return $deferred->promise();
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

    /** @inheritDoc */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }
}