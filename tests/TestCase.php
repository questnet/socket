<?php

namespace React\Tests\Socket;

use PHPUnit\Framework\TestCase as BaseTestCase;
use React\Promise\Promise;
use React\Stream\ReadableStreamInterface;
use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

class TestCase extends BaseTestCase
{
    protected function expectCallableExactly($amount)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->exactly($amount))
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($value)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($value);

        return $mock;
    }

    protected function expectCallableOnceWithException($type, $message = null, $code = null)
    {
        return $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf($type),
                $this->callback(function (\Exception $e) use ($message) {
                    return $message === null || $e->getMessage() === $message;
                }),
                $this->callback(function (\Exception $e) use ($code) {
                    return $code === null || $e->getCode() === $code;
                })
            )
        );
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        $builder = $this->getMockBuilder(\stdClass::class);
        if (method_exists($builder, 'addMethods')) {
            // PHPUnit 9+
            return $builder->addMethods(['__invoke'])->getMock();
        } else {
            // legacy PHPUnit
            return $builder->setMethods(['__invoke'])->getMock();
        }
    }

    protected function buffer(ReadableStreamInterface $stream, $timeout)
    {
        if (!$stream->isReadable()) {
            return '';
        }

        $buffer = await(timeout(new Promise(
            function ($resolve, $reject) use ($stream) {
                $buffer = '';
                $stream->on('data', function ($chunk) use (&$buffer) {
                    $buffer .= $chunk;
                });

                $stream->on('error', $reject);

                $stream->on('close', function () use (&$buffer, $resolve) {
                    $resolve($buffer);
                });
            },
            function () use ($stream) {
                $stream->close();
                throw new \RuntimeException();
            }
        ), $timeout));

        // let loop tick for reactphp/async v4 to clean up any remaining stream resources
        // @link https://github.com/reactphp/async/pull/65 reported upstream // TODO remove me once merged
        if (function_exists('React\Async\async')) {
            await(sleep(0));
        }

        return $buffer;
    }

    protected function supportsTls13()
    {
        // TLS 1.3 is supported as of OpenSSL 1.1.1 (https://www.openssl.org/blog/blog/2018/09/11/release111/)
        // The OpenSSL library version can only be obtained by parsing output from phpinfo().
        // OPENSSL_VERSION_TEXT refers to header version which does not necessarily match actual library version
        // see php -i | grep OpenSSL
        // OpenSSL Library Version => OpenSSL 1.1.1  11 Sep 2018
        ob_start();
        phpinfo(INFO_MODULES);
        $info = ob_get_clean();

        if (preg_match('/OpenSSL Library Version => OpenSSL ([\d\.]+)/', $info, $match)) {
            return version_compare($match[1], '1.1.1', '>=');
        }
        return false;
    }
}
