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

    /** @var callable(string,array):array{status:int,body:string} */
    private $transport;

    /**
     * @param string        $webhookUrl  Inbound webhook base, e.g. `https://x.bitrix24.ru/rest/1/secret/`.
     * @param Kv            $kv          Where the outage flag is kept.
     * @param callable|null $transport   `fn(string $url, array $params): array{status:int, body:string}`;
     *                                   defaults to curl with a 15 s timeout. Injected in tests.
     * @param int           $retryDelayMs Pause before the single retry on a rate-limit error.
     */
    public function __construct(
        private string $webhookUrl,
        private Kv $kv,
        ?callable $transport = null,
        private int $retryDelayMs = 1000,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * Calls a REST method and returns the `result` part of the response.
     *
     * @param string               $method E.g. `tasks.task.add`.
     * @param array<string,mixed>  $params Method parameters.
     * @throws BitrixUnavailable On network errors, HTTP ≥ 500, or a rate limit that persists after one retry.
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
            if ($res['status'] >= 500 || $error === 'QUERY_LIMIT_EXCEEDED' || !is_array($body)) {
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
     * @param array<string,mixed> $params
     * @return array{status:int, body:string}
     * @throws \RuntimeException On a network-level failure; code {@see CONNECT_FAILED} when
     *                           the connection was never established.
     */
    private static function curlTransport(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // the portal has no working IPv6 from this server
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $notConnected = curl_errno($ch) === CURLE_COULDNT_CONNECT
                || (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT && curl_getinfo($ch, CURLINFO_CONNECT_TIME) == 0.0);
            throw new \RuntimeException('curl: ' . curl_error($ch), $notConnected ? self::CONNECT_FAILED : 0);
        }

        return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string) $body];
    }
}
