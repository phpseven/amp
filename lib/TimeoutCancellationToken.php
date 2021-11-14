<?php

namespace Amp;

use Revolt\EventLoop;

/**
 * A TimeoutCancellationToken automatically requests cancellation after the timeout has elapsed.
 */
final class TimeoutCancellationToken implements CancellationToken
{
    private string $watcher;

    private CancellationToken $token;

    /**
     * @param float  $timeout Seconds until cancellation is requested.
     * @param string $message Message for TimeoutException. Default is "Operation timed out".
     */
    public function __construct(float $timeout, string $message = "Operation timed out")
    {
        $this->token = $source = new Internal\CancellableToken;

        $trace = null; // Defined in case assertions are disabled.
        \assert((bool) ($trace = \debug_backtrace(0)));

        $this->watcher = EventLoop::delay($timeout, static function () use ($source, $message, $trace): void {
            if ($trace) {
                $message .= \sprintf("\r\n%s was created here: %s", self::class, Internal\formatStacktrace($trace));
            } else {
                $message .= \sprintf(" (Enable assertions for a backtrace of the %s creation)", self::class);
            }

            $source->cancel(new TimeoutException($message));
        });

        EventLoop::unreference($this->watcher);
    }

    /**
     * Cancels the delay watcher.
     */
    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(callable $callback): string
    {
        return $this->token->subscribe($callback);
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $id): void
    {
        $this->token->unsubscribe($id);
    }

    /**
     * {@inheritdoc}
     */
    public function isRequested(): bool
    {
        return $this->token->isRequested();
    }

    /**
     * {@inheritdoc}
     */
    public function throwIfRequested(): void
    {
        $this->token->throwIfRequested();
    }
}
