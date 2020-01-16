<?php


namespace SunValley\TaskManager\Tests;

use Clue\React\Buzz\Browser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory as LoopFactory;
use SunValley\TaskManager\ServiceManager;
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
        $client       = new Browser($loop);

        $promise1 = $manager->addTask($sayHelloTask);
        $promise1->then($this->expectCallableOnce());
        $promise2 = $manager->addTask($sayByeTask);
        $promise2->then($this->expectCallableOnce());
        $return1 = null;
        $error1  = null;
        $return2 = null;
        $error2  = null;
        all(
            [
                $promise1,
                $promise2,
            ]
        )->then(
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
             function ($v) use ($manager) {
                 //$loop->stop();
                 $manager->terminate();
             }
         );

        $loop->run();

        $this->assertNull($error1);
        $this->assertNull($error2);
        $this->assertEquals($sayHelloTask->getOptions()['return'], $return1);
        $this->assertEquals($sayByeTask->getOptions()['return'], $return2);
    }
}