<?php


namespace SunValley\TaskManager\Task;

use React\Promise\PromiseInterface;
use SunValley\TaskManager\MessengerAwareServiceTaskInterface;
use SunValley\TaskManager\ProcessAwareMessenger;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Factory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Class AbstractIPCServiceTask defines a service task that can be used to define communication between child and main
 * process.
 *
 * @package SunValley\TaskManager\Task
 */
abstract class AbstractIPCServiceTask extends AbstractServiceTask implements MessengerAwareServiceTaskInterface
{

    private $rpcFn;

    final public function handleChildMessenger(Messenger $messenger)
    {
        $methods = $this->getIPCMethods();

        foreach ($methods as $method => $callable) {
            if (is_callable($callable)) {
                $messenger->registerRpc(
                    $method,
                    function (Payload $payload) use ($callable) {

                        $data = unserialize($payload['data']);

                        $retval = $callable($data);

                        if (!$retval instanceof PromiseInterface) {
                            $retval = resolve(['data' => $retval]);
                        }
                        return $retval->then(
                            function () {
                                return resolve([]);
                            }
                        );
                    }
                );
            }
        }

    }

    final public function handleMainMessenger(ProcessAwareMessenger $messenger)
    {
        $this->rpcFn = function ($name, $data, $id) use ($messenger) {
            return $messenger->rpc(Factory::rpc($name, ['data' => $data], $id));
        };
    }

    final protected function callChildMethod(string $name, $data, string $id = ''): PromiseInterface
    {
        $methods = $this->getIPCMethods();
        if (!isset($methods[$name])) {
            throw new \InvalidArgumentException(
                sprintf('Wrong method %s given. Supported: %s', $name, join(',', array_keys($methods)))
            );
        }

        if (!is_callable($methods[$name])) {
            throw new \RuntimeException(
                sprintf('Method %s does not have a proper callable that will be called from child', $name)
            );
        }

        $data = serialize($data);

        return ($this->rpcFn)($name, $data, $id);
    }

    /**
     * Get IPC methods to be called. This method's implementation should be as static as possible, i.e. contents should
     * not change on runtime as it might cause undefined behaviour.
     *
     * @return iterable|array<string, callable> Should return an array with values as callable to be run on child with
     *                                the given data, while values should indicate a method name.
     */
    abstract protected function getIPCMethods(): iterable;

    /**
     * Check if process is child process by checking the environmental variable APP_CHILD is set and it is 1
     *
     * @return bool
     */
    protected function isChild(): bool
    {
        $env = $_ENV['APP_CHILD'] ?? $_SERVER['APP_CHILD'] ?? 0;

        return $env > 0;
    }
}