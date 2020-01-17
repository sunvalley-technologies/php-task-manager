<?php

namespace SunValley\TaskManager;

use React\Promise\PromiseInterface;

interface ServiceStatusInterface
{
    /**
     * Returns service options
     *
     * @return ServiceOptions
     */
    public function getOptions(): ServiceOptions;

    /**
     * Returns reporter. Task can be found from reporter.
     *
     * @return ProgressReporter
     */
    public function getReporter(): ProgressReporter;

    /**
     * Is service spawning
     *
     * @return bool
     */
    public function isSpawning(): bool;

    /**
     * Get how many times this service started
     *
     * @return int
     */
    public function getStartedTimes(): int;

    /**
     * Get a promise which resolves whenever the service is started. This promise can get rejected with the exception
     * as reason if a failure occurs.
     *
     * @return PromiseInterface<ProgressReporter>
     */
    public function getStartPromise(): PromiseInterface;

    /**
     * Get a promise which resolves whenever the service is stop, including termination. This promise never gets a
     * rejection.
     *
     * @return PromiseInterface<ProgressReporter>
     */
    public function getStopPromise(): PromiseInterface;
}