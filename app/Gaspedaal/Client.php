<?php

namespace App\Gaspedaal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Fetches Gaspedaal pages the way a browser would: an ordinary GET with a browser User-Agent.
 */
class Client
{
    /**
     * A search URL as entered, made canonical: https://www.gaspedaal.nl, no page parameter.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || ! preg_match('/(^|\.)gaspedaal\.nl$/i', $parts['host'])) {
            throw new InvalidArgumentException('Dat is geen gaspedaal.nl-adres.');
        }

        // The query as the user verified it in the browser, only without a page number.
        $query = implode('&', array_filter(
            explode('&', $parts['query'] ?? ''),
            fn (string $pair) => $pair !== '' && ! preg_match('/^page(=|$)/', $pair),
        ));

        $path = $parts['path'] ?? '/';

        return 'https://www.gaspedaal.nl'.$path.($query !== '' ? "?{$query}" : '');
    }

    /**
     * The link to an ad on one of its portals, as Gaspedaal's own site makes it. The klikUrl in the
     * data (api.gaspedaal.nl/redirect/vehicle/…) refuses a direct visit (403); the site sends
     * clicks through www.gaspedaal.nl/api/proxy/redirect/… with these parameters instead.
     *
     * @param  array{klikUrl?: string, siteNr?: int, portaalType?: string}  $portal
     */
    public static function portalUrl(array $portal, int $portals, string $sort = 'df-a'): ?string
    {
        $parts = parse_url((string) ($portal['klikUrl'] ?? ''));
        $path = $parts['path'] ?? '';

        $path = match (true) {
            str_starts_with($path, '/redirect/vehicle') => "/api/proxy{$path}",
            str_starts_with($path, '/api/v1/clickouts') => str_replace('/api/v1/clickouts', '/api/proxy/redirect/clickout', $path),
            default => null,
        };

        if ($path === null) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $query = [
            ...$query,
            'app_source' => 'react',
            'portals_amount' => (string) $portals,
            'portals_dealersite' => ($portal['portaalType'] ?? null) === 'dealer' ? '1' : '0',
            'sorteren' => $sort,
        ];

        // AutoScout24 and AutoTrack.
        if (in_array($portal['siteNr'] ?? null, [1, 2], true)) {
            $query['siteNr'] = (string) $portal['siteNr'];
        }

        return 'https://www.gaspedaal.nl'.$path.'?'.http_build_query($query);
    }

    public static function pageUrl(string $url, int $page): string
    {
        if ($page <= 1) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'page='.$page;
    }

    /**
     * @return array{status: int, body: string}
     */
    public function get(string $url): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('gaspedaal.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.5',
            ])
                ->timeout(config('gaspedaal.timeout'))
                ->get($url);
        } catch (ConnectionException $e) {
            throw new FetchException("Connection failed: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new FetchException("HTTP {$response->status()} for {$url}", $response->status());
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * Fetches and parses one page, retrying on failure as config/gaspedaal.php says.
     *
     * @param  callable(int $attempt, \Throwable $e): void|null  $onRetry
     * @return array{html: string, status: int, page: ParsedPage}
     */
    public function fetchPage(string $url, int $page, ?callable $onRetry = null, ?PageParser $parser = null): array
    {
        $parser ??= new PageParser;
        $attempts = (int) config('gaspedaal.attempts');
        $waits = config('gaspedaal.retry_waits');

        for ($attempt = 1; ; $attempt++) {
            $response = null;

            try {
                $response = $this->get(self::pageUrl($url, $page));
                $parsed = $parser->parse($response['body']);

                return ['html' => $response['body'], 'status' => $response['status'], 'page' => $parsed];
            } catch (FetchException|ParseException $e) {
                if ($e instanceof ParseException && $response !== null) {
                    $e->html = $response['body'];
                }

                if ($attempt >= $attempts) {
                    throw $e;
                }

                if ($onRetry) {
                    $onRetry($attempt, $e);
                }

                self::pause($waits[$attempt - 1] ?? end($waits));
            }
        }
    }

    /**
     * Sleeps, unless a test has turned pauses off.
     */
    public static function pause(float $seconds): void
    {
        if ($seconds > 0 && ! app()->runningUnitTests()) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    public static function politeDelay(): void
    {
        $min = (int) config('gaspedaal.delay_min');
        $max = (int) config('gaspedaal.delay_max');

        self::pause(random_int($min * 1000, max($min, $max) * 1000) / 1000);
    }
}
