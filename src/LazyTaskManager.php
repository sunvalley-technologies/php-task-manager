<?php


namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/**
 * Class LazyTaskManager, does not start automatically on class initialization
 *
 * @package SunValley\TaskManager
 */
class LazyTaskManager extends TaskManager
{

    /** @var bool */
    private $initialized = false;

    public function __construct(
        LoopInterface $loop,
        TaskQueueInterface $queue,
        Configuration $configuration,
        ?TaskStorageInterface $storage = null
    ) {
        parent::__construct($loop, $queue, $configuration, $storage);

        $this->initialized = true;
    }

    public function start(): PromiseInterface
    {
        if (!$this->initialized) {
            return resolve();
        }

        return parent::start();
    }
}