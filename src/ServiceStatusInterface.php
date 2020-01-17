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
     * Get a promise which resolves according to the status of the service when promise is received:
     *
     * - If service is not started, returned promise resolves when service is started or rejected if service fails to
     * start.
     * - If service is started, returned promise will never be resolved and will always be rejected.
     *
     * @return PromiseInterface
     */
    public function getStartPromise(): PromiseInterface;
}