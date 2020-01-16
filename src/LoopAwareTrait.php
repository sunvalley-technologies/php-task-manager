<?php


namespace SunValley\TaskManager;


use React\EventLoop\LoopInterface;

trait LoopAwareTrait
{

    /** @var LoopInterface */
    protected $loop;

    /**
     * @param LoopInterface $loop
     */
    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }
}