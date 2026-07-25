<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

/**
 * Audit columns are fixed width. One over-long merchant id made the whole INSERT throw,
 * and both writers swallow that, so a real payment left no row in either table. Values
 * are cut to the declared width instead — the body json keeps the whole thing.
 */
final class ColumnFit
{
    // Mirrors the shipped migrations. Widening instead would still leave a ceiling,
    // and would not help the merchants already running the old schema.
    private const array WIDTHS = [
        'connection' => 64,
        'direction' => 16,
        'api' => 16,
        'method' => 8,
        'path' => 255,
        'external_id' => 100,
        'order_id' => 64,
        'payment_id' => 64,
        'transaction_id' => 64,
        'result' => 16,
        'provider' => 32,
        'state_text' => 32,
        'delivery_key' => 160,
    ];

    // Cut value sits exactly on the width and ends here: truncation is readable, not silent.
    // Ascii on purpose — an ellipsis is unrepresentable in a latin1 column and would throw
    // the very write this saves.
    private const string MARKER = '~';

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function row(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value)) {
                $row[$column] = self::value($column, $value);
            }
        }

        return $row;
    }

    // Lookups cut the same way or a cut row is never found again.
    public static function value(string $column, ?string $text): ?string
    {
        $width = self::WIDTHS[$column] ?? null;

        // Characters, not bytes: varchar(n) is n characters on mysql and pgsql alike.
        return $text === null || $width === null || mb_strlen($text) <= $width
            ? $text
            : mb_substr($text, 0, $width - 1).self::MARKER;
    }
}
