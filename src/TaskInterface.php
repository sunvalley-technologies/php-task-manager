<?php

namespace SunValley\TaskManager;

/**
 * Implementing classes should be serializable.
 *
 * @package SunValley\TaskManager
 */
interface TaskInterface
{

    /**
     * Get ID of this task. This is usually a UUID This ID should be unique for each task instance.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Validate, normalize and set given options sent by a client
     *
     * @param array $options
     *
     * @throws Exception\TaskOptionValidationException Thrown when option cannot be validated
     */
    public function setOptions(array $options): void;

    /**
     * Returns normalized options
     *
     * @return array
     */
    public function getOptions(): array;

    /**
     * Run the given task
     *
     * @param ProgressReporter $progressReporter
     */
    public function run(ProgressReporter $progressReporter): void;
}