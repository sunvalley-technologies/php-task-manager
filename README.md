# PHP Task Manager

[![Build Status](https://travis-ci.org/sunvalley-technologies/php-task-manager.svg?branch=master)](https://travis-ci.org/sunvalley-technologies/php-task-manager)

A task manager that helps delegating work to background be it for HTTP requests or event loops.

This documentation is mostly introductory only. Majority of the classes, interfaces and methods of this library is documented
and gives more information on specific details.

## Installing

``
composer require sunvalley-technologies/php-task-manager
``

## Usage

### Task Manager

This library provides a manager that handles child processes and delegation of tasks to them and also provides 
an interface for tasks to be defined and passed to these children from different contexts.

The task manager server can be used standalone or you can attach it to an applications event loop.

A standalone version can be started like following:

````php
<?php

$loop          = \React\EventLoop\Factory::create();
$queue         = new \SunValley\TaskManager\TaskQueue\InMemoryTaskQueue($loop);
$configuration = new \SunValley\TaskManager\Configuration();
$configuration->setMaxJobsPerProcess(2);
$configuration->setMaxProcesses(3);
$configuration->setTtl(1);

$taskManager = new \SunValley\TaskManager\TaskManager($loop, $queue, $configuration);
$task = new ExampleTask(uniqid(), ['data'=>'some data']);
$promise = $taskManager->submitTask($task); // promise resolves to a result object
$promise->then(function(\SunValley\TaskManager\ProgressReporter $reporter) use ($loop) {
    if ($reporter->isFailed()) {
        echo sprintf("Task is Failed! Error is %s", $reporter->getError());    
    } else {
        echo sprintf("Task Completed, Result is '%s'!", $reporter->getResult());
    }
    
    $loop->stop();
});
// or 
// $queue->enqueue($task); which works from any context
$loop->run();
````

Note that this manager won't do much as InMemoryTaskQueue has no way to receive a task from anywhere but the same 
application hence closure is just stopping the loop as soon as the task is resulted. 

You can use `\SunValley\TaskManager\TaskQueue\RedisTaskQueue` to have a Redis backend to send tasks from different contexts.

On above example task `ExampleTask` should be implementing `\SunValley\TaskManager\TaskInterface`. There is 
an abstract class `\SunValley\TaskManager\Task\AbstractTask` that makes easier to generate task classes.

Manager considers all tasks as synchronous by default unless a task implements `\SunValley\TaskManager\LoopAwareInterface`.
This should be used carefully as async tasks are generally fire and forget and result of a task is only controlled by 
passed ProgressReporter instance. This means that async tasks should also properly handle their errors. From managers 
point of view, it is important to properly know that a task is sync or async as manager can push more async tasks to a 
worker that is already doing another async work. This is an important point to consider while building tasks. For this reason
use the helper trait with your async tasks `\SunValley\TaskManager\Task\AsyncTaskTrait`. You should avoid using await or any other 
promise waiting method on tasks body for the loop that is passed when LoopAwareInterface is defined on the task as the task's run method is
started on the passed loop.

The tasks run method receives a progress reporter object that can be used to send progress reports and also need to be used to 
finalize the task. Generally, you always want to call `finishTask` at the end of your logic as calling this method will make the worker 
as task completed for the manager. The exceptions thrown in the run method are caught and reported to manager with `failTask($error)`.
If desired, progress information can be sent by calling `setMessage`, `setCompletionTarget`, `setCompletion` can be used to send progress 
information, however this only works for Async Tasks. Calling these methods from a child informs manager and also manager informs task storage to update information if necessary.
 
For more examples, check the integration tests in this library.

### Service Manager

ServiceManager provides a way to run tasks like services. Each task is expected to be long running task and if it fails, it is restarted according to given restart policy.

A sample can be found in the `\SunValley\TaskManager\Tests\ServiceManagerTest`

## Framework Integration

[Symfony Bundle](https://github.com/sunvalley-technologies/php-task-manager-symfony-bundle)

