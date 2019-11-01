<?php

namespace SunValley\TaskManager;

use React\EventLoop\LoopInterface;
use WyriHaximus\React\ChildProcess\Pool\LauncherInterface;

class ProcessLauncher implements LauncherInterface
{

    /** @inheritDoc */
    public function __invoke(LoopInterface $loop, array $options)
    {
        return ProcessFactory::parentFromClass(Process::class, $loop, $options);
    }
}