<?php

declare(strict_types=1);

namespace PhpMake\Process;

/** Converts Windows console cancellation events into cooperative process cancellation. */
final class WindowsConsoleSignalHandler
{
    private const CTRL_C_EVENT = 0;

    private const CTRL_BREAK_EVENT = 1;

    private \Closure $handler;

    private bool $registered = false;

    /** Creates a handler that reports Ctrl+C and Ctrl+Break to the process coordinator. */
    public function __construct(callable $onCancel)
    {
        $this->handler = function (int $event) use ($onCancel): bool {
            $ctrlC = defined('PHP_WINDOWS_EVENT_CTRL_C')
                ? (int) constant('PHP_WINDOWS_EVENT_CTRL_C')
                : self::CTRL_C_EVENT;
            $ctrlBreak = defined('PHP_WINDOWS_EVENT_CTRL_BREAK')
                ? (int) constant('PHP_WINDOWS_EVENT_CTRL_BREAK')
                : self::CTRL_BREAK_EVENT;

            if ($event === $ctrlC) {
                $onCancel('CTRL_C_EVENT');
                return true;
            }

            if ($event === $ctrlBreak) {
                $onCancel('CTRL_BREAK_EVENT');
                return true;
            }

            return false;
        };
    }

    /** Registers the handler when running under the Windows CLI SAPI. */
    public function register(): bool
    {
        if (!function_exists('sapi_windows_set_ctrl_handler')) {
            return false;
        }

        $this->registered = sapi_windows_set_ctrl_handler($this->handler);
        return $this->registered;
    }

    /** Unregisters only this handler and leaves other console handlers intact. */
    public function unregister(): void
    {
        if (!$this->registered || !function_exists('sapi_windows_set_ctrl_handler')) {
            return;
        }

        sapi_windows_set_ctrl_handler($this->handler, false);
        $this->registered = false;
    }

    /** Dispatches a Windows console event; exposed for deterministic platform-independent tests. */
    public function handleEvent(int $event): bool
    {
        return ($this->handler)($event);
    }
}
