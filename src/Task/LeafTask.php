<?php

namespace SunValley\TaskManager\Task;

use React\EventLoop\LoopInterface;
use SunValley\TaskManager\LoopAwareInterface;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\TaskInterface;

/**
 * Class LeafTask helps generating simple task instances that specifies the task ID. This class is useful to pass to
 * certain operations that only accepts to find task id such as cancel task method.
 *
 * @package SunValley\TaskManager\Task
 */
class LeafTask implements TaskInterface
{

    /** @var string */
    private $id;

    /** @var array */
    private $options;

    /**
     * Generate a leaf task.
     *
     * @param string $class  Class name of the original task
     * @param string $taskId Task ID of
     *
     * @return LeafTask
     */
    public static function generate(string $class, string $taskId): LeafTask
    {
        if (is_a($class, LoopAwareInterface::class, true)) {
            return AsyncLeafTask::generate($class, $taskId);
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        return new static($taskId);
    }

    protected function __construct(string $id, array $options = [])
    {

        $this->id      = $id;
        $this->options = $options;
    }

    /** @inheritDoc */
    public function run(ProgressReporter $progressReporter): void
    {
        throw new \RuntimeException('Leaf tasks are not meant to be run');
    }

    /** @inheritDoc */
    public function serialize()
    {
        return json_encode(
            [
                'id'      => $this->getId(),
                'options' => $this->getOptions(),
            ]
        );
    }

    /** @inheritDoc */
    public function unserialize($serialized)
    {
        $data = json_decode($serialized, true);

        $this->__construct($data['id'], $data['options']);
    }

    /** @inheritDoc */
    public function getId(): string
    {
        return $this->id;
    }

    /** @inheritDoc */
    public function setOptions(array $options): void
    {
    }

    /** @inheritDoc */
    public function getOptions(): array
    {
        return [];
    }
}

class AsyncLeafTask extends LeafTask implements LoopAwareInterface
{

    /** @var LoopInterface */
    private $loop;

    public static function generate(string $class, string $taskId): LeafTask
    {
        return new static($taskId);
    }

    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }
}