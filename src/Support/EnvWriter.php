<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Support;

/**
 * Append-only writer for a `.env` file.
 *
 * It NEVER overwrites and NEVER writes a backup. A credential file is the one place a
 * tool has no business rewriting: the value it would replace may be the only copy of a
 * secret the server showed once, and a backup would leave a second copy of it on disk.
 * When a key already exists the caller is expected to show the current value and decide,
 * with a commented duplicate as the only other outcome.
 *
 * Deliberately holds no prompts and no output — the command owns the conversation, this
 * owns the file, and that split is what makes the policy testable.
 */
final class EnvWriter
{
    public function __construct(private readonly string $path) {}

    /**
     * Is the key really defined? A commented line is NOT a definition — our own declined
     * duplicates are comments, and treating one as present would permanently block a
     * merchant who said no once from ever adding the key.
     */
    public function has(string $key): bool
    {
        return $this->matchLine($key) !== null;
    }

    /** The current value, unquoted, for showing the operator before they decide. */
    public function value(string $key): ?string
    {
        $line = $this->matchLine($key);

        if ($line === null) {
            return null;
        }

        $raw = trim(substr($line, strpos($line, '=') + 1));

        // Strip one matched pair of surrounding quotes, nothing cleverer: this is for
        // display, and a half-parsed value would mislead worse than a raw one.
        if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    /** Appends. Creates the file if absent. Never touches an existing line. */
    public function append(string $key, string $value, bool $commented = false): void
    {
        $existing = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        // Without this a file saved with no trailing newline gets the new key glued onto
        // its last value, silently corrupting both.
        $prefix = ($existing === '' || str_ends_with($existing, "\n")) ? '' : PHP_EOL;

        $line = ($commented ? '# ' : '').$key.'='.$this->quote($value);

        file_put_contents($this->path, $prefix.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** The defining line for a key, or null. */
    private function matchLine(string $key): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = (string) file_get_contents($this->path);

        // Split on either ending: a .env edited on Windows is ordinary, and this package
        // has already been bitten once by a CR riding along inside a value.
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // `export KEY=` is valid in a .env and shells source these files.
            $withoutExport = preg_replace('/^export\s+/i', '', $trimmed) ?? $trimmed;

            // Anchored on the whole name, so WLCM_API_KEY_EXTRA is not WLCM_API_KEY.
            if (preg_match('/^'.preg_quote($key, '/').'\s*=/', $withoutExport) === 1) {
                return $withoutExport;
            }
        }

        return null;
    }

    private function quote(string $value): string
    {
        // Laravel's parser needs quotes once whitespace or a comment marker appears;
        // leaving them off turns "Acme Trading LLC" into "Acme".
        return preg_match('/[\s#"\'\\\\]/', $value) === 1
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"'
            : $value;
    }
}
