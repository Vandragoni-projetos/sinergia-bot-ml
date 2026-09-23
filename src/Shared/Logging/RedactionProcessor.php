<?php

declare(strict_types=1);

namespace Sinergia\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Sinergia\Shared\Security\Redactor;

/** Garante que nenhum log saia com segredo, mesmo que o chamador erre. */
final class RedactionProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: Redactor::redactText($record->message),
            context: Redactor::redactArray($record->context),
            extra: Redactor::redactArray($record->extra),
        );
    }
}
