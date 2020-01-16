<?php

namespace SunValley\TaskManager;

use WyriHaximus\React\ChildProcess\Messenger\Messenger;

/**
 * Interface MessengerAwareServiceTaskInterface. Implementing services can handle messengers from child and main.
 *
 * @package SunValley\TaskManager
 */
interface MessengerAwareServiceTaskInterface extends ServiceTaskInterface
{

    /**
     * Called from main process.
     * 
     * @param ProcessAwareMessenger $messenger
     */
    public function handleMainMessenger(ProcessAwareMessenger $messenger);

    /**
     * Called from child process
     * 
     * @param Messenger $messenger
     */
    public function handleChildMessenger(Messenger $messenger);

}