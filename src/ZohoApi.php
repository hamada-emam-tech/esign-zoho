<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho;

use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Talks to Zoho Sign's REST API (https://sign.zoho.<dc>/api/v1): the OAuth access token, the
 * multipart upload, and the response envelope every endpoint uses:
 *
 *     {"code": 0, "message": "Document has been added", "status": "success", "requests": {...}}
 *
 * Anything other than status "success" / code 0, or an HTTP error, becomes a ProviderError.
 *
 * Access tokens come from the refresh token at {accounts}/oauth/v2/token. They live one hour, and
 * Zoho allows only ten token requests per ten minutes, so the token is reused until shortly before
 * it expires and can be shared through a PSR-16 cache.
 * https://www.zoho.com/accounts/protocol/oauth/web-apps/access-token-expiry.html
 * https://www.zoho.com/accounts/protocol/oauth/token-limits.html
 *
 * @internal used by ZohoProvider
 */
final class ZohoApi
{
    /** Refresh this many seconds before Zoho says the token expires. */
    private const EARLY_REFRESH = 300;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ZohoCredentials $credentials,
        private readonly string $accountsUrl,
        private readonly string $signUrl,
        private readonly ?CacheInterface $cache = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<string, mixed> the whole JSON response */
    public function get(string $path): array
    {
        return $this->json('GET', $path);
    }

    /**
     * POST with an application/x-www-form-urlencoded body, as Zoho's examples send `data={...}`.
     *
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $form = []): array
    {
        return $this->json('POST', $path, $form === [] ? null : self::form($form), 'application/x-www-form-urlencoded');
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    public function put(string $path, array $form = []): array
    {
        return $this->json('PUT', $path, $form === [] ? null : self::form($form), 'application/x-www-form-urlencoded');
    }

    /**
     * POST a multipart/form-data body: text fields, then files. Built here so any PSR-17 factory works.
     *
     * @param array<string, string> $fields
     * @param list<array{name: string, filename: string, contentType: string, contents: string}> $files
     *
     * @return array<string, mixed>
     */
    public function postMultipart(string $path, array $fields, array $files): array
    {
        [$body, $boundary] = self::multipart($fields, $files);

        return $this->json('POST', $path, $body, 'multipart/form-data; boundary=' . $boundary);
    }

    /**
     * PUT a multipart/form-data body of text fields, as Zoho's curl examples do with --form.
     *
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    public function putMultipart(string $path, array $fields): array
    {
        [$body, $boundary] = self::multipart($fields, []);

        return $this->json('PUT', $path, $body, 'multipart/form-data; boundary=' . $boundary);
    }

    /**
     * GET a file. Zoho answers a file with its own content type, and an error with the usual JSON envelope.
     *
     * @return array{string, string} [content type, bytes]
     */
    public function download(string $path): array
    {
        $response = $this->call('GET', $path, null, null);
        $type = strtolower($response->getHeaderLine('Content-Type'));
        $bytes = (string) $response->getBody();

        if ($response->getStatusCode() >= 400 || str_contains($type, 'json') || str_starts_with(ltrim($bytes), '{')) {
            $this->unwrap($response, "GET {$path}", $bytes);
        }

        return [$type, $bytes];
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?string $body = null, ?string $contentType = null): array
    {
        $response = $this->call($method, $path, $body, $contentType);

        return $this->unwrap($response, "{$method} {$path}", (string) $response->getBody());
    }

    private function call(string $method, string $path, ?string $body, ?string $contentType, bool $isRetry = false): ResponseInterface
    {
        // Zoho Sign takes the token only in this header, never as a query parameter.
        $request = $this->requests->createRequest($method, $this->signUrl . $path)
            ->withHeader('Authorization', 'Zoho-oauthtoken ' . $this->token())
            ->withHeader('Accept', 'application/json');
        if ($body !== null && $contentType !== null) {
            $request = $request->withHeader('Content-Type', $contentType)->withBody($this->streams->createStream($body));
        }

        $response = $this->send($request, "{$method} {$path}");

        if ($response->getStatusCode() === 401 && ! $isRetry) {
            // The token was revoked or pushed out: Zoho keeps only ten live tokens per refresh token.
            $this->forgetToken();

            return $this->call($method, $path, $body, $contentType, true);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function unwrap(ResponseInterface $response, string $what, string $raw): array
    {
        $status = $response->getStatusCode();
        // Zoho IDs are 17-digit longs; keep them exact even where they arrive as numbers.
        $json = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($json)) {
            throw new ProviderError('zoho', "{$what} returned a non-JSON response.", $status);
        }

        $code = $json['code'] ?? null;
        $state = $json['status'] ?? null;
        $failed = $status >= 400
            || (is_string($state) && strtolower($state) !== 'success')
            || (is_numeric($code) && (int) $code !== 0);

        if ($failed) {
            $message = is_string($json['message'] ?? null) && $json['message'] !== '' ? $json['message'] : 'Request failed';

            throw new ProviderError('zoho', "{$what}: {$message}", $status, is_scalar($code) ? (string) $code : null);
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function token(): string
    {
        $now = ($this->clock)();
        if ($this->token !== null && $now < $this->tokenExpiresAt) {
            return $this->token;
        }

        $cached = $this->cache?->get($this->cacheKey());
        if (is_array($cached) && is_string($cached['token'] ?? null) && (int) ($cached['expires_at'] ?? 0) > $now) {
            [$this->token, $this->tokenExpiresAt] = [$cached['token'], (int) $cached['expires_at']];

            return $this->token;
        }

        // Zoho Accounts accepts the parameters as a query string or as a form body. The body keeps the
        // client secret and the refresh token out of access logs.
        $form = array_filter([
            'refresh_token' => $this->credentials->refreshToken,
            'client_id' => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'redirect_uri' => $this->credentials->redirectUri,
            'grant_type' => 'refresh_token',
        ], fn (?string $v) => $v !== null && $v !== '');

        $request = $this->requests->createRequest('POST', $this->accountsUrl . '/oauth/v2/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream(self::form($form)));

        $response = $this->send($request, 'token');
        $json = json_decode((string) $response->getBody(), true);
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;

        if ($response->getStatusCode() >= 500) {
            throw new ProviderError('zoho', 'Zoho Accounts is unavailable. Try again later.', $response->getStatusCode());
        }
        if (! is_string($token) || $token === '') {
            // Only Zoho's short error code is passed on, never the response body or the request.
            $code = is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : null;
            $message = $code === 'Access Denied'
                ? 'Zoho refused the token request: too many token requests (Zoho allows 10 per 10 minutes). Pass a PSR-16 cache to share the token between workers.'
                : "Zoho token refresh failed. Check client_id, client_secret, refresh_token and that data_center matches your account (accounts server {$this->accountsUrl}).";

            throw new ProviderError('zoho', $message, $response->getStatusCode(), $code);
        }

        // Tokens live 3600 seconds. Refresh 5 minutes early.
        $lifetime = max(60, (int) ($json['expires_in'] ?? 3600) - self::EARLY_REFRESH);
        $this->token = $token;
        $this->tokenExpiresAt = $now + $lifetime;
        $this->cache?->set($this->cacheKey(), ['token' => $token, 'expires_at' => $this->tokenExpiresAt], $lifetime);

        return $token;
    }

    private function forgetToken(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 0;
        $this->cache?->delete($this->cacheKey());
    }

    /** One key per accounts server, client and refresh token. The refresh token is hashed, never stored. */
    private function cacheKey(): string
    {
        return 'esign_zoho_token_' . sha1($this->accountsUrl . '|' . $this->credentials->clientId . '|' . hash('sha256', $this->credentials->refreshToken));
    }

    private function send(RequestInterface $request, string $what): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderError('zoho', "{$what}: could not reach Zoho ({$e->getMessage()})", null, null, $e);
        }
    }

    /** @param array<string, string> $form */
    private static function form(array $form): string
    {
        // RFC 3986 encoding, so "+", "&" and "=" inside JSON or secrets survive.
        return http_build_query($form, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $fields
     * @param list<array{name: string, filename: string, contentType: string, contents: string}> $files
     *
     * @return array{string, string} [body, boundary]
     */
    private static function multipart(array $fields, array $files): array
    {
        $all = implode('', $fields) . implode('', array_column($files, 'contents'));
        do {
            $boundary = '----esign-zoho-' . bin2hex(random_bytes(16));
        } while (str_contains($all, $boundary));

        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n"
                . 'Content-Disposition: form-data; name="' . self::quoted($name) . "\"\r\n\r\n"
                . $value . "\r\n";
        }
        foreach ($files as $file) {
            $disposition = 'form-data; name="' . self::quoted($file['name']) . '"; filename="' . self::quoted($file['filename']) . '"';
            if (preg_match('/[^\x20-\x7e]/', $file['filename'])) {
                // Non-ASCII names (Arabic, for example) also go RFC 5987 encoded.
                $disposition .= "; filename*=UTF-8''" . rawurlencode($file['filename']);
            }
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: {$disposition}\r\n"
                . 'Content-Type: ' . $file['contentType'] . "\r\n\r\n"
                . $file['contents'] . "\r\n";
        }

        return [$body . "--{$boundary}--\r\n", $boundary];
    }

    /** A header parameter value with quotes and line breaks removed, so a file name can't inject headers. */
    private static function quoted(string $value): string
    {
        return str_replace(['"', "\r", "\n", '\\'], ['\'', '', '', ''], $value);
    }
}
