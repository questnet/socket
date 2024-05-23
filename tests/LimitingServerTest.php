<?php

namespace React\Tests\Socket;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\LimitingServer;
use React\Socket\ServerInterface;
use React\Socket\TcpServer;
use function React\Async\await;
use function React\Promise\Timer\timeout;

class LimitingServerTest extends TestCase
{
    const TIMEOUT = 0.1;

    public function testGetAddressWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('getAddress')->willReturn('127.0.0.1:1234');

        $server = new LimitingServer($tcp, 100);

        $this->assertEquals('127.0.0.1:1234', $server->getAddress());
    }

    public function testPauseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('pause');

        $server = new LimitingServer($tcp, 100);

        $server->pause();
    }

    public function testPauseTwiceWillBePassedThroughToTcpServerOnce()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('pause');

        $server = new LimitingServer($tcp, 100);

        $server->pause();
        $server->pause();
    }

    public function testResumeWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('resume');

        $server = new LimitingServer($tcp, 100);

        $server->pause();
        $server->resume();
    }

    public function testResumeTwiceWillBePassedThroughToTcpServerOnce()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('resume');

        $server = new LimitingServer($tcp, 100);

        $server->pause();
        $server->resume();
        $server->resume();
    }

    public function testCloseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('close');

        $server = new LimitingServer($tcp, 100);

        $server->close();
    }

    public function testSocketErrorWillBeForwarded()
    {
        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $server = new LimitingServer($tcp, 100);

        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('error', [new \RuntimeException('test')]);
    }

    public function testSocketConnectionWillBeForwarded()
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $server = new LimitingServer($tcp, 100);
        $server->on('connection', $this->expectCallableOnceWith($connection));
        $server->on('error', $this->expectCallableNever());

        $tcp->emit('connection', [$connection]);

        $this->assertEquals([$connection], $server->getConnections());
    }

    public function testSocketConnectionWillBeClosedOnceLimitIsReached()
    {
        $first = $this->createMock(ConnectionInterface::class);
        $first->expects($this->never())->method('close');
        $second = $this->createMock(ConnectionInterface::class);
        $second->expects($this->once())->method('close');

        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $server = new LimitingServer($tcp, 1);
        $server->on('connection', $this->expectCallableOnceWith($first));
        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('connection', [$first]);
        $tcp->emit('connection', [$second]);
    }

    public function testPausingServerWillBePausedOnceLimitIsReached()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $tcp = new TcpServer(0, $loop);

        $connection = $this->createMock(ConnectionInterface::class);

        $server = new LimitingServer($tcp, 1, true);

        $tcp->emit('connection', [$connection]);
    }

    public function testSocketDisconnectionWillRemoveFromList()
    {
        $tcp = new TcpServer(0);

        $socket = stream_socket_client($tcp->getAddress());
        fclose($socket);

        $server = new LimitingServer($tcp, 100);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('error', $this->expectCallableNever());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $connection->on('close', function () use ($resolve) {
                    $resolve(null);
                });
            });
        });

        await(timeout($peer, self::TIMEOUT));

        $this->assertEquals([], $server->getConnections());

        $server->close();
    }

    public function testPausingServerWillEmitOnlyOneButAcceptTwoConnectionsDueToOperatingSystem()
    {
        $server = new TcpServer(0);
        $server = new LimitingServer($server, 1, true);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('error', $this->expectCallableNever());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $first = stream_socket_client($server->getAddress());
        $second = stream_socket_client($server->getAddress());

        await(timeout($peer, self::TIMEOUT));

        fclose($first);
        fclose($second);

        $server->close();
    }

    public function testPausingServerWillEmitTwoConnectionsFromBacklog()
    {
        $server = new TcpServer(0);
        $server = new LimitingServer($server, 1, true);
        $server->on('error', $this->expectCallableNever());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $connections = 0;
            $server->on('connection', function (ConnectionInterface $connection) use (&$connections, $resolve) {
                ++$connections;

                if ($connections >= 2) {
                    $resolve(null);
                }
            });
        });

        $first = stream_socket_client($server->getAddress());
        fclose($first);
        $second = stream_socket_client($server->getAddress());
        fclose($second);

        await(timeout($peer, self::TIMEOUT));

        $server->close();
    }
}
