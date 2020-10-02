<?php

namespace Amp\Test;

use Amp\AsyncGenerator;
use Amp\CancellationToken;
use Amp\CancellationTokenSource;
use Amp\Deferred;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Success;
use function Amp\sleep;

class CancellationTest extends AsyncTestCase
{
    private function createAsyncIterator(CancellationToken $cancellationToken): Pipeline
    {
        return new AsyncGenerator(function () use ($cancellationToken): \Generator {
            $running = true;
            $cancellationToken->subscribe(function () use (&$running): void {
                $running = false;
            });

            $i = 0;
            while ($running) {
                yield $i++;
            }
        });
    }

    public function testCancellationCancelsIterator(): void
    {
        $cancellationSource = new CancellationTokenSource;

        $pipeline = $this->createAsyncIterator($cancellationSource->getToken());

        $count = 0;

        while (null !== $current = $pipeline->continue()) {
            $count++;

            $this->assertIsInt($current);

            if ($current === 3) {
                $cancellationSource->cancel();
            }
        }

        $this->assertSame(4, $count);
    }

    public function testUnsubscribeWorks(): void
    {
        $cancellationSource = new CancellationTokenSource;

        $id = $cancellationSource->getToken()->subscribe(function () {
            $this->fail("Callback has been called");
        });

        $cancellationSource->getToken()->subscribe(function () {
            $this->assertTrue(true);
        });

        $cancellationSource->getToken()->unsubscribe($id);

        $cancellationSource->cancel();
    }

    public function testSubscriptionsRunAsCoroutine(): \Generator
    {
        $deferred = new Deferred;

        $this->expectOutputString("abc");

        $cancellationSource = new CancellationTokenSource;
        $cancellationSource->getToken()->subscribe(function () use ($deferred) {
            print yield new Success("a");
            print yield new Success("b");
            print yield new Success("c");
            $deferred->resolve();
        });

        $cancellationSource->cancel();

        yield $deferred->promise();
    }

    public function testThrowingCallbacksEndUpInLoop(): void
    {
        Loop::setErrorHandler(function (\Throwable $exception) use (&$reason): void {
            $reason = $exception;
        });

        $cancellationSource = new CancellationTokenSource;
        $cancellationSource->getToken()->subscribe(function () {
            throw new TestException;
        });

        $cancellationSource->cancel();

        sleep(0); // Tick event loop to invoke callbacks.

        $this->assertInstanceOf(TestException::class, $reason);
    }

    public function testThrowingCallbacksEndUpInLoopIfCoroutine(): void
    {
        Loop::setErrorHandler(function (\Throwable $exception) use (&$reason): void {
            $reason = $exception;
        });

        $cancellationSource = new CancellationTokenSource;
        $cancellationSource->getToken()->subscribe(function () {
            if (false) {
                yield;
            }

            throw new TestException;
        });

        $cancellationSource->cancel();

        sleep(1); // Tick event loop a couple of times to invoke callbacks.

        $this->assertInstanceOf(TestException::class, $reason);
    }

    public function testDoubleCancelOnlyInvokesOnce(): void
    {
        $cancellationSource = new CancellationTokenSource;
        $cancellationSource->getToken()->subscribe($this->createCallback(1));

        $cancellationSource->cancel();
        $cancellationSource->cancel();
    }

    public function testCalledIfSubscribingAfterCancel(): void
    {
        $cancellationSource = new CancellationTokenSource;
        $cancellationSource->cancel();
        $cancellationSource->getToken()->subscribe($this->createCallback(1));
    }
}
