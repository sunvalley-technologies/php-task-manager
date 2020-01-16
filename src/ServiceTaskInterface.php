<?php

namespace SunValley\TaskManager;

interface ServiceTaskInterface extends TaskInterface
{

    /**
     * Implementing classes should run their closing logic here if service receives a termination signal.
     * 
     * Non-async tasks can skip this, async tasks should implement for a clean shutdown.
     * 
     * @return void
     */
    public function terminate(): void;
}