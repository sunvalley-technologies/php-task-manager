<?php

namespace SunValley\TaskManager;

use React\ChildProcess\Process as ReactProcess;
use React\Socket\ConnectionInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;

/** @internal React process aware messenger to send signals to kill a worker e.g. */
class ProcessAwareMessenger extends Messenger
{

    /** @var ReactProcess */
    private $process;

    public function __construct(ReactProcess $process, ConnectionInterface $connection, array $options = [])
    {
        parent::__construct($connection, $options);
        $this->process = $process;
    }

    /**
     * @return ReactProcess
     */
    public function getProcess(): ReactProcess
    {
        return $this->process;
    }
}