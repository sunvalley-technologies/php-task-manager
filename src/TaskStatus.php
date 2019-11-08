<?php


namespace SunValley\TaskManager;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Class TaskStatus indicates task statuses that can be used by tasks while processing a task
 *
 * @package SunValley\TaskManager
 * @method static TaskStatus WAITING()
 * @method static TaskStatus PROCESSING()
 * @method static TaskStatus FAILED()
 * @method static TaskStatus COMPLETED()
 * @method static TaskStatus CANCELLED()
 */
class TaskStatus extends AbstractEnumeration
{

    /**
     * Indicates a task is waiting to be processed
     */
    const WAITING = 'Waiting';

    /**
     * Indicates a task is processing
     */
    const PROCESSING = 'Processing';

    /**
     * Indicates a task is failed
     */
    const FAILED = 'Failed';

    /**
     * Indicates a task is completed
     */
    const COMPLETED = 'Completed';

    /**
     * Indicates a task is canceled before processing
     */
    const CANCELLED = 'Cancelled';

}