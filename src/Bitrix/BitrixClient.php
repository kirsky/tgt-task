<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Kv;

/**
 * Minimal Bitrix24 REST client over an inbound webhook URL.
 *
 * It maps failures into two kinds — {@see BitrixUnavailable} (outage) and
 * {@see BitrixError} (business error) — and keeps the `bitrix_down_since` flag
 * in sync so the panel can show «Битрикс24 недоступен».
 * The webhook URL is a secret and never appears in messages or logs.
 */
final class BitrixClient
{
    /** Flag set while the portal is unreachable (value: ISO time of the first failure). */
    public const DOWN_FLAG = 'bitrix_down_since';

    /** Exception code a transport uses for "could not connect" (request never sent, safe to retry). */
    public const CONNECT_FAILED = 7;

    /** Total attempts for a call whose connection fails. */
    private const CONNECT_ATTEMPTS = 3;

    /** Addresses tried per attempt: some Bitrix24 front IPs time out from this server (measured ~25%). */
    private const ADDRESSES_PER_ATTEMPT = 4;

    /**
     * Errors meaning "this portal does not let us in right now" — typically REST access ending
     * with the trial (26.11.2026). Treated as an outage so the app switches to read-only mode.
     */
    private const ACCESS_ERRORS = [
        'NO_AUTH_FOUND', 'INVALID_CREDENTIALS', 'ACCESS_DENIED', 'PAYMENT_REQUIRED', 'insufficient_scope',
        'expired_token', 'invalid_token', 'INVALID_TOKEN', 'ERROR_OAUTH', 'PORTAL_DELETED', 'ACCOUNT_BLOCKED',
        'REST_UNAVAILABLE', 'ERROR_METHOD_NOT_ALLOWED_FOR_TARIFF', 'WRONG_AUTH_TYPE',
    ];

    /** @var callable(string, array<string,mixed>): array{status:int, body:string} */
    private $transport;

    /**
     * @param string        $webhookUrl  Inbound webhook base, e.g. `https://x.bitrix24.ru/rest/1/secret/`.
     * @param Kv            $kv          Where the outage flag is kept.
     * @param callable|null $transport   `fn(string $url, array $params): array{status:int, body:string}`;
     *                                   defaults to curl with a 15 s timeout. Injected in tests.
     * @param int           $retryDelayMs Pause before the single retry on a rate-limit error.
     * @param string|null   $proxy       Optional proxy for all requests, e.g. `socks5h://127.0.0.1:10811`
     *                                   (the portal drops much traffic from outside Russia).
     */
    public function __construct(
        private string $webhookUrl,
        private Kv $kv,
        ?callable $transport = null,
        private int $retryDelayMs = 1000,
        private ?string $proxy = null,
    ) {
        $this->transport = $transport ?? fn (string $url, array $params): array => self::curlTransport($url, $params, $this->proxy);
    }

    /**
     * Calls a REST method and returns the `result` part of the response.
     *
     * @param string               $method E.g. `tasks.task.add`.
     * @param array<string,mixed>  $params Method parameters.
     * @throws BitrixUnavailable On network errors, HTTP ≥ 500, HTTP 401/402/403 or an access/plan error
     *                           (e.g. the REST subscription ended), or a rate limit that persists.
     * @throws BitrixError       On any other error returned by the API.
     */
    public function call(string $method, array $params = []): mixed
    {
        $url = rtrim($this->webhookUrl, '/') . '/' . $method . '.json';
        $connectTries = 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                $res = ($this->transport)($url, $params);
            } catch (\Throwable $e) {
                // Bitrix24 rotates many IPs and some connections fail at random: retry only when
                // nothing was sent, so a retried tasks.task.add can never create a duplicate.
                if ($e->getCode() === self::CONNECT_FAILED && ++$connectTries < self::CONNECT_ATTEMPTS) {
                    continue;
                }
                $this->markDown();
                throw new BitrixUnavailable("Bitrix24 request failed: $method", 0, $e);
            }
            $body = json_decode($res['body'], true);
            $error = is_array($body) ? ($body['error'] ?? null) : null;

            if ($error === 'QUERY_LIMIT_EXCEEDED' && $attempt - $connectTries === 1) {
                usleep($this->retryDelayMs * 1000);
                continue;
            }
            if ($res['status'] >= 500 || in_array($res['status'], [401, 402, 403], true) || $error === 'QUERY_LIMIT_EXCEEDED'
                || in_array($error, self::ACCESS_ERRORS, true) || !is_array($body)) {
                $this->markDown();
                throw new BitrixUnavailable("Bitrix24 unavailable ($method, HTTP {$res['status']})");
            }
            if ($error !== null) {
                $this->kv->delete(self::DOWN_FLAG);
                throw new BitrixError("$method: $error " . ($body['error_description'] ?? ''));
            }
            $this->kv->delete(self::DOWN_FLAG);

            return $body['result'] ?? null;
        }
    }

    /**
     * Base URL of the portal (scheme + host), for building links to tasks.
     */
    public function portalBase(): string
    {
        $p = parse_url($this->webhookUrl);

        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    /** Records the start of an outage once (keeps the earliest time). */
    private function markDown(): void
    {
        if ($this->kv->get(self::DOWN_FLAG) === null) {
            $this->kv->set(self::DOWN_FLAG, date(DATE_ATOM));
        }
    }

    /**
     * Default transport: form-encoded POST via curl.
     *
     * With a proxy, the proxy resolves and connects (one attempt; the client retries only
     * failures that prove nothing was sent). Without a proxy, the portal host resolves to many
     * front servers and some of them time out from this server, so on a failed connect another
     * resolved address is tried (pinned with CURLOPT_RESOLVE). Nothing is sent until a
     * connection exists, so trying another address never duplicates a request.
     *
     * @param array<string,mixed> $params
     * @return array{status:int, body:string}
     * @throws \RuntimeException On a network-level failure; code {@see CONNECT_FAILED} when
     *                           the request was certainly not sent.
     */
    private static function curlTransport(string $url, array $params, ?string $proxy): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $body = http_build_query($params);
        $targets = [null];
        if ($proxy === null) {
            $ips = gethostbynamel($host) ?: [];
            shuffle($ips);
            $targets = array_map(fn ($ip) => "$host:443:$ip", array_slice($ips, 0, self::ADDRESSES_PER_ATTEMPT)) ?: [null];
        }

        $last = null;
        foreach ($targets as $resolve) {
            $ch = curl_init($url);
            curl_setopt_array($ch, self::curlOptions($body, $proxy, $resolve));
            $out = curl_exec($ch);
            if ($out !== false) {
                return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string) $out];
            }
            $notConnected = self::notConnected(curl_errno($ch), (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME), $proxy !== null);
            $last = new \RuntimeException('curl: ' . curl_error($ch), $notConnected ? self::CONNECT_FAILED : 0);
            if (!$notConnected) {
                break; // the request may have been sent: never resend it
            }
        }

        throw $last;
    }

    /**
     * curl options for one request.
     *
     * @param string      $body    Form-encoded POST body.
     * @param string|null $proxy   Proxy URL, or null for a direct connection.
     * @param string|null $resolve `host:443:ip` pin for a direct connection, or null.
     * @return array<int,mixed>
     */
    public static function curlOptions(string $body, ?string $proxy, ?string $resolve): array
    {
        $o = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => $proxy !== null ? 10 : 3,
        ];
        if ($proxy !== null) {
            $o[CURLOPT_PROXY] = $proxy;
        } else {
            $o[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            if ($resolve !== null) {
                $o[CURLOPT_RESOLVE] = [$resolve];
            }
        }

        return $o;
    }

    /**
     * Whether a failed curl request certainly never reached the server (safe to retry).
     *
     * Direct: "couldn't connect", or a timeout before the connection was made.
     * Proxied: curl reports connect time 0 even after connecting through the proxy,
     * so only explicit connect/proxy errors count.
     */
    public static function notConnected(int $errno, float $connectTime, bool $proxied): bool
    {
        if (in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_PROXY, 97 /* CURLE_PROXY */], true)) {
            return true;
        }

        return !$proxied && $errno === CURLE_OPERATION_TIMEDOUT && $connectTime == 0.0;
    }
}
