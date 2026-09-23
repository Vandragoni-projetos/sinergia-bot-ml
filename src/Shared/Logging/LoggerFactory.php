<?php

declare(strict_types=1);

namespace Sinergia\Shared\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/** Logs estruturados em JSON (stderr), sempre com redação de segredos. */
final class LoggerFactory
{
    public static function create(string $appEnv, ?HandlerInterface $handler = null): LoggerInterface
    {
        if ($handler === null) {
            $handler = $appEnv === 'test'
                ? new NullHandler()
                : new StreamHandler('php://stderr', $appEnv === 'production' ? Level::Info : Level::Debug);
        }
        // Nem todo handler aceita formatter (ex.: NullHandler no Monolog 3).
        if ($handler instanceof FormattableHandlerInterface) {
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
        }

        $logger = new Logger('sinergia');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new RedactionProcessor());
        $logger->pushProcessor(static fn ($record) => $record->with(extra: $record->extra + ['env' => $appEnv]));

        return $logger;
    }
}
