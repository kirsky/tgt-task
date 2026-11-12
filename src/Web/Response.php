<?php

declare(strict_types=1);

namespace App\Web;

/**
 * An HTTP response produced by the router; sent by `public/index.php`, inspected by tests.
 */
final class Response
{
    /**
     * @param int                  $status  HTTP status code.
     * @param string               $body    Response body (HTML).
     * @param array<string,string> $headers Extra headers, e.g. `Location`.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /** A 302 redirect to a panel path. */
    public static function redirect(string $to): self
    {
        return new self(302, '', ['Location' => $to]);
    }

    /** Writes status, headers and body to the client. */
    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
