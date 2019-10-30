<?php


namespace SunValley\TaskManager;

use WyriHaximus\React\ChildProcess\Pool\Options;

/**
 * Class PoolOptions
 *
 * @package SunValley\TaskManager
 * @internal
 */
class PoolOptions extends Options
{
    const MAX_JOBS_PER_PROCESS = 'max_jobs_per_process';
}