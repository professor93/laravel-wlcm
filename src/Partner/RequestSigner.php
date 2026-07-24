<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner;

final class RequestSigner
{
    public function __construct(private readonly string $apiSecret) {}

    public function sign(string $method, string $url, string $timestampMs, string $rawBody): string
    {
        $message = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($url),
            $timestampMs,
            hash('sha256', $rawBody),
        ]);

        return hash_hmac('sha256', $message, $this->apiSecret);
    }

    public function canonicalPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $path;
        }

        // Mirror python sorted(parse_qsl(...)): pairs sorted by (key, value), RFC1738 encoding.
        $pairs = [];
        foreach (explode('&', $query) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $chunk, 2), 2, '');
            $pairs[] = [urldecode($k), urldecode($v)];
        }

        // strcmp: byte order; spaceship would numeric-compare numeric strings, diverging from python sorted().
        usort($pairs, fn (array $a, array $b) => $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]));

        // quote_plus equivalent: rawurlencode keeps ~ literal, then space as +.
        $encode = fn (string $s): string => str_replace('%20', '+', rawurlencode($s));
        $encoded = implode('&', array_map(
            fn (array $p) => $encode($p[0]).'='.$encode($p[1]),
            $pairs,
        ));

        return $encoded === '' ? $path : "{$path}?{$encoded}";
    }
}
