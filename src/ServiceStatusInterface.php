<?php

namespace SunValley\TaskManager;

use React\Promise\PromiseInterface;

interface ServiceStatusInterface
{
    public function getOptions(): ServiceOptions;

    public function getReporter(): ProgressReporter;

    public function isSpawning(): bool;

    public function getStartedTimes(): int;

    public function getStartPromise(): PromiseInterface;
}