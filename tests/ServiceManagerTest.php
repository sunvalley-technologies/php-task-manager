<?php


namespace SunValley\TaskManager\Tests;

use Clue\React\Buzz\Browser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory as LoopFactory;
use SunValley\TaskManager\ServiceManager;
use SunValley\TaskManager\ServiceTaskInterface;
use SunValley\TaskManager\Tests\Fixtures\FailingServiceTask;
use SunValley\TaskManager\Tests\Fixtures\ServiceTask;
use SunValley\Tests\CallableUtil\CallableTestTrait;
use function React\Promise\all;

class ServiceManagerTest extends TestCase
{
    use CallableTestTrait;

    public function testServiceManager()
    {
        $loop         = LoopFactory::create();
        $manager      = new ServiceManager($loop);
        $port1        = 9832;
        $port2        = 9833;
        $sayHelloTask = new ServiceTask('say-hello', ['http-port' => $port1, 'return' => 'Hello']);
        $sayByeTask   = new ServiceTask('say-bye', ['http-port' => $port2, 'return' => 'Bye']);
        $failingTask  = new FailingServiceTask('fail');
        $client       = new Browser($loop);

        $promise1 = $manager->addTask($sayHelloTask);
        $promise1->then($this->expectCallableOnce());
        $promise2 = $manager->addTask($sayByeTask);
        $promise2->then($this->expectCallableOnce());
        $failPromise = $manager->addTask($failingTask);
        $failPromise->otherwise($this->expectCallableOnce());

        $this->assertContainsOnlyInstancesOf(ServiceTaskInterface::class, $manager->getTasks());
        $this->assertCount(3, $manager->getTasks());
        $this->assertEquals($failingTask, $manager->getTaskById($failingTask->getId()));

        $return1 = null;
        $error1  = null;
        $return2 = null;
        $error2  = null;
        $return3 = null;
        $error3  = null;
        $errorG  = null;
        $manager->start()
                ->then(
                    function () use ($promise2, $promise1) {
                        return all(
                            [
                                $promise1,
                                $promise2,
                            ]
                        );
                    }
                )
                ->then(
                    function () use (&$error2, &$return2, &$return1, $port2, &$error1, $port1, $client) {
                        $promise1 = $client->get('http://127.0.0.1:' . $port1)->then(
                            function (ResponseInterface $response) use (&$return1) {
                                $return1 = (string)$response->getBody();
                            },
                            function ($e) use (&$error1) {
                                $error1 = $e;
                            }
                        );

                        $promise2 = $client->get('http://127.0.0.1:' . $port2)->then(
                            function (ResponseInterface $response) use (&$return2) {
                                $return2 = (string)$response->getBody();
                            },
                            function ($e) use (&$error2) {
                                $error2 = $e;
                            }
                        );

                        return all(
                            [
                                $promise1,
                                $promise2,
                            ]
                        );
                    }
                )
                ->then(
                    function () use ($sayByeTask) {
                        return $sayByeTask->setExternalOutput('forced-output');
                    }
                )
                ->then(
                    function () use (&$error3, &$return3, $port2, $client) {
                        return $client->get('http://127.0.0.1:' . $port2)->then(
                            function (ResponseInterface $response) use (&$return3) {
                                $return3 = (string)$response->getBody();
                            },
                            function ($e) use (&$error3) {
                                $error3 = $e;
                            }
                        );
                    }
                )
                ->then(
                    function ($v) use ($manager) {
                        //$loop->stop();
                        $manager->terminate();
                    }
                )
                ->otherwise(
                    function ($e) use (&$errorG, $loop) {
                        $errorG = $e;
                        $loop->stop();
                    }
                );

        $loop->run();

        $this->assertNull($error1);
        $this->assertNull($error2);
        $this->assertNull($error3);
        $this->assertNull($errorG);
        $this->assertEquals($sayHelloTask->getOptions()['return'], $return1);
        $this->assertEquals($sayByeTask->getOptions()['return'], $return2);
        $this->assertEquals('forced-output', $return3);
    }
}