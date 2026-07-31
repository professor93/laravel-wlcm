<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

/**
 * Rotation for the JSONL driver, which otherwise grows until the disk does not.
 *
 * Rename-based, and that is compatible with the write path because each write reopens the
 * file by name: a writer holding the old inode finishes its line into the rotated file
 * rather than losing it, and the next write lands in a fresh one. The alternative —
 * truncating in place — can cut a line in half.
 *
 * Everything here runs inside FileLogStore's existing swallow-and-report guard, so a
 * rotation failure can never break a payment call. The cost of that is a log file that
 * keeps growing while report() explains why, which is the right way round.
 */
final class FileRotation
{
    /**
     * The path to write to now, rotating first if the configured limits say so.
     *
     * @param  array<string, mixed>  $config
     */
    public static function resolve(string $path, array $config): string
    {
        $daily = ($config['daily'] ?? false) === true;

        if ($daily) {
            // Date in the NAME rather than a rotate-on-date-change check: two processes
            // crossing midnight cannot then disagree about whose turn it is to rotate.
            $path = self::dated($path);
        }

        $maxSize = self::bytes($config['max_size'] ?? null);

        if ($maxSize !== null && is_file($path) && (int) @filesize($path) >= $maxSize) {
            self::rotate($path, $config);
        }

        return $path;
    }

    /** `logs/wlcm.jsonl` becomes `logs/wlcm-2026-08-01.jsonl`. */
    private static function dated(string $path): string
    {
        $directory = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.'.$extension;

        // UTC, not the app timezone: a file name is a filing decision, and a host that
        // moves timezone must not start writing yesterday's file again.
        return $directory.'/'.$name.'-'.gmdate('Y-m-d').$suffix;
    }

    /** @param array<string, mixed> $config */
    private static function rotate(string $path, array $config): void
    {
        $target = self::freeTarget($path);

        // Best-effort: a lost race means the other process already rotated, and the size
        // check will simply not fire again for this write.
        if (! @rename($path, $target)) {
            return;
        }

        self::prune($path, $config);
    }

    /** A `.1`, `.2`, … suffix, never overwriting an existing archive. */
    private static function freeTarget(string $path): string
    {
        $n = 1;

        while (is_file($path.'.'.$n)) {
            $n++;
        }

        return $path.'.'.$n;
    }

    /** @param array<string, mixed> $config */
    private static function prune(string $path, array $config): void
    {
        $keep = $config['max_files'] ?? null;

        if (! is_numeric($keep) || (int) $keep < 0) {
            return;
        }

        $archives = glob($path.'.*') ?: [];

        // Newest first, so the survivors are the ones anyone would want.
        usort($archives, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));

        foreach (array_slice($archives, (int) $keep) as $stale) {
            @unlink($stale);
        }
    }

    /** `10MB`, `512K`, or a plain byte count. Null switches size rotation off. */
    private static function bytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)b?$/i', trim($value), $m) !== 1) {
            return null;
        }

        $multiplier = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][strtolower($m[2])];
        $bytes = (int) ((float) $m[1] * $multiplier);

        return $bytes > 0 ? $bytes : null;
    }
}
