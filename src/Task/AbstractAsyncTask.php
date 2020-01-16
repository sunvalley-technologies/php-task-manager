<?php

namespace SunValley\TaskManager\Task;

use SunValley\TaskManager\LoopAwareInterface;

abstract class AbstractAsyncTask extends AbstractTask implements LoopAwareInterface
{
    use AsyncTaskTrait;
}