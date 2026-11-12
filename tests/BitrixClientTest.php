<?php

declare(strict_types=1);

namespace Tests;

use App\Bitrix\BitrixClient;
use App\Bitrix\BitrixError;
use App\Bitrix\BitrixUnavailable;
use App\Db;
use App\Kv;
use PHPUnit\Framework\TestCase;

/**
 * Error mapping of the Bitrix24 REST client and the «Битрикс24 недоступен» flag.
 */
final class BitrixClientTest extends TestCase
{
    private Kv $kv;

    protected function setUp(): void
    {
        $this->kv = new Kv(Db::open(':memory:'));
    }

    /**
     * Client whose transport replays the given responses in order and records calls.
     *
     * @param list<array{status:int, body:string}|\Throwable> $responses
     * @param list<array{0:string,1:array<string,mixed>}> $calls Filled with [url, params] per call.
     */
    private function client(array $responses, array &$calls = []): BitrixClient
    {
        $transport = function (string $url, array $params) use (&$responses, &$calls): array {
            $calls[] = [$url, $params];
            $r = array_shift($responses);
            if ($r instanceof \Throwable) {
                throw $r;
            }
            if ($r === null) {
                throw new \LogicException('No more fake responses');
            }

            return $r;
        };

        return new BitrixClient('https://portal.test/rest/1/abc/', $this->kv, $transport, retryDelayMs: 0);
    }

    /** A successful call returns the `result` part and hits `<base>/<method>.json`. */
    public function test_returns_result(): void
    {
        $calls = [];
        $c = $this->client([['status' => 200, 'body' => '{"result":{"task":{"id":"5"}}}']], $calls);
        self::assertSame(['task' => ['id' => '5']], $c->call('tasks.task.add', ['fields' => ['TITLE' => 'x']]));
        self::assertSame('https://portal.test/rest/1/abc/tasks.task.add.json', $calls[0][0]);
    }

    /** HTTP 5xx means the portal is unavailable; the outage flag is set. */
    public function test_503_throws_unavailable_and_sets_kv(): void
    {
        $c = $this->client([['status' => 503, 'body' => 'oops']]);
        try {
            $c->call('tasks.task.list');
            self::fail('expected BitrixUnavailable');
        } catch (BitrixUnavailable) {
        }
        self::assertNotNull($this->kv->get('bitrix_down_since'));
    }

    /** A rate-limit error is retried once before giving up. */
    public function test_query_limit_retries_once_then_succeeds(): void
    {
        $calls = [];
        $c = $this->client([
            ['status' => 503, 'body' => '{"error":"QUERY_LIMIT_EXCEEDED"}'],
            ['status' => 200, 'body' => '{"result":true}'],
        ], $calls);
        self::assertTrue($c->call('tasks.task.complete', ['taskId' => 1]));
        self::assertCount(2, $calls);
    }

    /** A business error from the API (bad params, no access) is not an outage. */
    public function test_api_error_throws_bitrix_error(): void
    {
        $c = $this->client([['status' => 400, 'body' => '{"error":"ERROR_CORE","error_description":"bad"}']]);
        $this->expectException(BitrixError::class);
        $c->call('tasks.task.add');
    }

    /** A successful call clears a previous outage flag. */
    public function test_success_clears_down_flag(): void
    {
        $this->kv->set('bitrix_down_since', '2026-11-26T00:00:00+03:00');
        $this->client([['status' => 200, 'body' => '{"result":[]}']])->call('department.get');
        self::assertNull($this->kv->get('bitrix_down_since'));
    }

    /** A failure to connect (nothing was sent, so a retry cannot duplicate a task). */
    private static function connectFail(): \RuntimeException
    {
        return new \RuntimeException('connect timeout', BitrixClient::CONNECT_FAILED);
    }

    /** A read timeout after the request was sent is not retried: the task may already exist. */
    public function test_read_timeout_is_not_retried(): void
    {
        $calls = [];
        $c = $this->client([new \RuntimeException('read timeout'), ['status' => 200, 'body' => '{"result":1}']], $calls);
        try {
            $c->call('tasks.task.add');
            self::fail('expected BitrixUnavailable');
        } catch (BitrixUnavailable) {
        }
        self::assertCount(1, $calls);
    }

    /**
     * Through the Russian SOCKS5 proxy the API is reliable (30/30 measured) if the connect
     * timeout covers the extra hop; the proxy resolves DNS itself (socks5h) and no address pinning is used.
     */
    public function test_curl_options_with_proxy(): void
    {
        $o = BitrixClient::curlOptions('a=1', 'socks5h://127.0.0.1:10811', null);
        self::assertSame('socks5h://127.0.0.1:10811', $o[CURLOPT_PROXY]);
        self::assertSame(10, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $o);
        self::assertSame('a=1', $o[CURLOPT_POSTFIELDS]);

        $direct = BitrixClient::curlOptions('a=1', null, 'host:443:1.2.3.4');
        self::assertArrayNotHasKey(CURLOPT_PROXY, $direct);
        self::assertSame(['host:443:1.2.3.4'], $direct[CURLOPT_RESOLVE]);
        self::assertSame(CURL_IPRESOLVE_V4, $direct[CURLOPT_IPRESOLVE]);
    }

    /**
     * Through a proxy curl reports connect time 0 even after connecting, so a timeout is NOT
     * proof that nothing was sent: only explicit connect/proxy errors are retried there.
     */
    public function test_not_connected_detection(): void
    {
        self::assertTrue(BitrixClient::notConnected(CURLE_COULDNT_CONNECT, 0.0, proxied: false));
        self::assertTrue(BitrixClient::notConnected(CURLE_OPERATION_TIMEDOUT, 0.0, proxied: false));
        self::assertFalse(BitrixClient::notConnected(CURLE_OPERATION_TIMEDOUT, 0.3, proxied: false));
        self::assertFalse(BitrixClient::notConnected(CURLE_OPERATION_TIMEDOUT, 0.0, proxied: true));
        self::assertTrue(BitrixClient::notConnected(CURLE_COULDNT_CONNECT, 0.0, proxied: true));
        self::assertTrue(BitrixClient::notConnected(97 /* CURLE_PROXY */, 0.0, proxied: true));
    }

    /** A network failure that persists over all attempts is an outage. */
    public function test_network_error_is_unavailable(): void
    {
        $this->expectException(BitrixUnavailable::class);
        $this->client([self::connectFail(), self::connectFail(), self::connectFail()])->call('department.get');
    }

    /**
     * Bitrix24 rotates many IPs and ~1 in 12 connections fails at random (seen on the demo portal),
     * so a failed connection is retried and the next one usually succeeds.
     */
    public function test_network_error_retries_then_succeeds(): void
    {
        $calls = [];
        $c = $this->client([self::connectFail(), ['status' => 200, 'body' => '{"result":true}']], $calls);
        self::assertTrue($c->call('tasks.task.complete', ['taskId' => 1]));
        self::assertCount(2, $calls);
        self::assertNull($this->kv->get('bitrix_down_since'));
    }
}
