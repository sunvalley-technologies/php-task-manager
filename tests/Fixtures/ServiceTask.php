<?php

namespace SunValley\TaskManager\Tests\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Http\Server as HttpServer;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Server as SocketServer;
use SunValley\TaskManager\ProgressReporter;
use SunValley\TaskManager\ServiceTaskInterface;
use SunValley\TaskManager\Task\AbstractAsyncTask;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ServiceTask extends AbstractAsyncTask implements ServiceTaskInterface
{


    /** @var Deferred */
    private $taskResolver;

    /** @var SocketServer */
    private $socket;

    /**
     * @inheritDoc
     */
    function buildOptionsResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['http-port', 'return']);
        $resolver->setAllowedTypes('http-port', 'int');
        $resolver->setAllowedTypes('return', 'string');

        return $resolver;
    }

    protected function _run(LoopInterface $loop, ProgressReporter $reporter): PromiseInterface
    {
        $server       = new HttpServer(\Closure::fromCallable([$this, 'handleRequest']));
        $this->socket = new SocketServer('127.0.0.1:' . $this->getOptions()['http-port'], $loop);
        $server->listen($this->socket);

        return ($this->taskResolver = new Deferred())->promise();
    }

    /**
     * @inheritDoc
     */
    public function terminateChild(): void
    {
        $this->socket->close();
        $this->taskResolver->resolve();
    }

    public function handleRequest(ServerRequestInterface $request)
    {
        return new Response(200, ['Content-Type' => 'text/plain'], $this->getOptions()['return']);
    }

    /**
     * @inheritDoc
     */
    public function terminateMain(): void
    {
        
    }
}