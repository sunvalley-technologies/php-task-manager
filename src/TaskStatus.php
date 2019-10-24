<?php


namespace SunValley\TaskManager;

use Eloquent\Enumeration\AbstractEnumeration;
use MyCLabs\Enum\Enum;

/**
 * Class TaskStatus indicates task statuses that can be used by tasks while processing a task
 *
 * @package SunValley\TaskManager
 * @method static TaskStatus PROCESSING()
 * @method static TaskStatus FAILED()
 * @method static TaskStatus COMPLETED()
 */
class TaskStatus extends AbstractEnumeration
{

    /**
     * Indicates a task is processing
     */
    private const PROCESSING = 'Processing';

    /**
     * Indicates a task is failed
     */
    private const FAILED = 'Failed';

    /**
     * Indicates a task is completed
     */
    private const COMPLETED = 'Completed';
    
}