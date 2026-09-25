<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Operação de lote recusada; o motivo é um código exibível (nunca dados de outra conta). */
final class BatchRejected extends \RuntimeException
{
    public const string NOT_FOUND = 'batch_not_found';
    public const string CLOSED = 'batch_closed';
    public const string NOTHING_TO_EXPORT = 'nothing_to_export';
    public const string EMPTY_PASTE = 'empty_paste';
    public const string TOO_LARGE = 'paste_too_large';
    public const string NOT_PREVIEWED = 'not_previewed';
    public const string NOTHING_SELECTED = 'nothing_selected';
    public const string INVALID_LINE = 'invalid_line';
    public const string UNKNOWN_ITEM = 'unknown_item';
    public const string ITEM_TWICE = 'item_twice';
    public const string ID_CONFLICT = 'id_conflict';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Lote de links recusado: ' . $reason . '.');
    }
}
