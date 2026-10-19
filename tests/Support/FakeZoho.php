<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Esign\Zoho\ZohoApi;
use HamadaEmamTech\Esign\Zoho\ZohoCredentials;
use HamadaEmamTech\Esign\Zoho\ZohoOptions;
use HamadaEmamTech\Esign\Zoho\ZohoProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * An in-memory Zoho Sign built from the response shapes in Zoho's API documentation
 * (https://www.zoho.com/sign/api/), not from a live account:
 * - every response is {"code": 0, "message": ..., "status": "success", ...}, failures {"code", "message", "status": "failure"};
 * - POST /requests takes multipart "file" parts and a "data" part, and answers with a draft whose
 *   document_ids and actions carry the IDs the submit call needs;
 * - POST /requests/{id}/submit takes a form-encoded "data" and refuses a signer with no field (code 9101)
 *   and a second submit (code 12008), as in https://www.zoho.com/sign/api/error-codes.html;
 * - GET /requests/{id}/pdf answers application/pdf, or application/zip for several documents;
 * - tokens come from {accounts}/oauth/v2/token and are sent as "Zoho-oauthtoken <token>".
 *
 * Where Zoho does not document a value (the HTTP status of an OAuth error, the code for an unknown
 * request, the body of a 401), the fake makes a plausible choice, commented where it is made.
 */
final class FakeZoho implements ClientInterface
{
    public const CLIENT_ID = '1000.TESTCLIENTID';
    public const CLIENT_SECRET = 'client+secret&value=1';
    public const REFRESH_TOKEN = '1000.refresh.token+value';
    public const ORIGINAL = "%PDF-1.4 original\n";
    public const SIGNED = "%PDF-1.7 signed by everyone /ByteRange [0 100 200 300]\n";
    public const ZIP = "PK\x03\x04 a zip of signed documents";

    /** @var list<array{method: string, host: string, path: string, query: string, contentType: string, authorization: string, body: string}> */
    public array $calls = [];

    public int $tokenCalls = 0;

    public int $expiresIn = 3600;

    /** Answer the next N API calls with 401, as a revoked token would. */
    public int $unauthorizedNext = 0;

    public bool $rejectRefresh = false;

    public bool $throttleRefresh = false;

    public bool $accountsOutage = false;

    /** Fail the next submit with this documented code. */
    public ?int $failSubmit = null;

    /** Report this request_status instead of the real one. */
    public ?string $forcedStatus = null;

    /** @var array<int|string, array{name: string, status: string, sequential: bool, notes: ?string, documents: list<array{document_id: string, document_name: string, document_order: string, contents: string}>, actions: list<array<string, mixed>>, deleted: bool, expireBy?: string}> */
    private array $requests = [];

    private int $sequence = 0;

    public function provider(?ZohoOptions $options = null, ?\Closure $clock = null, ?CacheInterface $cache = null): ZohoProvider
    {
        $options ??= new ZohoOptions('com', webhookSecret: 'thisisthesamplekeyfortestingpurposes');
        $factory = new HttpFactory();
        $credentials = new ZohoCredentials(self::CLIENT_ID, self::CLIENT_SECRET, self::REFRESH_TOKEN);

        return new ZohoProvider(
            new ZohoApi($this, $factory, $factory, $credentials, $options->accountsUrl, $options->signUrl, $cache, $clock),
            $options,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $body = (string) $request->getBody();
        $this->calls[] = [
            'method' => $request->getMethod(),
            'host' => $uri->getHost(),
            'path' => $path,
            'query' => $uri->getQuery(),
            'contentType' => $request->getHeaderLine('Content-Type'),
            'authorization' => $request->getHeaderLine('Authorization'),
            'body' => $body,
        ];

        if ($path === '/oauth/v2/token') {
            return $this->token($request, $body);
        }

        if ($this->unauthorizedNext > 0) {
            --$this->unauthorizedNext;

            return self::error(401, 0, 'Invalid oauth token');   // body not documented
        }
        if ($request->getHeaderLine('Authorization') !== 'Zoho-oauthtoken token-' . $this->tokenCalls) {
            return self::error(401, 0, 'Invalid oauth token');
        }

        $method = $request->getMethod();

        return match (true) {
            $method === 'POST' && $path === '/api/v1/requests' => $this->create($request, $body),
            $method === 'POST' && (bool) preg_match('#^/api/v1/requests/(\d+)/submit$#', $path, $m) => $this->submit($m[1], $body),
            $method === 'POST' && (bool) preg_match('#^/api/v1/requests/(\d+)/recall$#', $path, $m) => $this->recall($m[1]),
            $method === 'POST' && (bool) preg_match('#^/api/v1/requests/(\d+)/remind$#', $path, $m) => $this->found($m[1]) ? self::ok(['message' => 'Reminder has been sent']) : self::notFound(),
            $method === 'PUT' && (bool) preg_match('#^/api/v1/requests/(\d+)/delete$#', $path, $m) => $this->delete($m[1]),
            $method === 'PUT' && (bool) preg_match('#^/api/v1/requests/(\d+)/extend$#', $path, $m) => $this->extend($m[1], $request, $body),
            $method === 'GET' && (bool) preg_match('#^/api/v1/requests/(\d+)/pdf$#', $path, $m) => $this->pdf($m[1]),
            $method === 'GET' && (bool) preg_match('#^/api/v1/requests/(\d+)/documents/(\d+)/pdf$#', $path, $m) => $this->documentPdf($m[1], $m[2]),
            $method === 'GET' && (bool) preg_match('#^/api/v1/requests/(\d+)$#', $path, $m) => $this->details($m[1]),
            default => self::error(404, 0, 'Not found'),
        };
    }

    // ---- test controls ----------------------------------------------------------

    public function sign(string $requestId, string $email): void
    {
        foreach ($this->requests[$requestId]['actions'] as &$action) {
            if ($action['recipient_email'] === $email) {
                $action['action_status'] = 'SIGNED';
            }
        }
        $signed = array_filter($this->requests[$requestId]['actions'], fn (array $a) => $a['action_status'] === 'SIGNED');
        if (count($signed) === count($this->requests[$requestId]['actions'])) {
            $this->requests[$requestId]['status'] = 'completed';
        }
    }

    public function signAll(string $requestId): void
    {
        foreach ($this->requests[$requestId]['actions'] as $action) {
            $this->sign($requestId, (string) $action['recipient_email']);
        }
    }

    public function decline(string $requestId): void
    {
        $this->requests[$requestId]['status'] = 'declined';
    }

    public function expire(string $requestId): void
    {
        $this->requests[$requestId]['status'] = 'expired';
    }

    public function isDeleted(string $requestId): bool
    {
        return $this->requests[$requestId]['deleted'] ?? false;
    }

    /** @return list<string> "METHOD /path" */
    public function paths(): array
    {
        return array_map(fn (array $c) => $c['method'] . ' ' . $c['path'], $this->calls);
    }

    /** @return list<array{method: string, host: string, path: string, query: string, contentType: string, authorization: string, body: string}> */
    public function apiCalls(): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['path'] !== '/oauth/v2/token'));
    }

    /** @return array{method: string, host: string, path: string, query: string, contentType: string, authorization: string, body: string}|null */
    public function lastCall(string $method, string $pathPattern): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && preg_match($pathPattern, $call['path'])) {
                return $call;
            }
        }

        return null;
    }

    /**
     * The decoded "data" JSON of the last call matching the path, from a multipart or a form body.
     *
     * @return array<string, mixed>
     */
    public function lastData(string $method, string $pathPattern): array
    {
        $call = $this->lastCall($method, $pathPattern) ?? throw new \LogicException("No {$method} {$pathPattern} call");
        $data = str_starts_with($call['contentType'], 'multipart/form-data')
            ? self::parseMultipart($call['contentType'], $call['body'])['fields']['data'] ?? null
            : self::parseForm($call['body'])['data'] ?? null;
        $decoded = json_decode((string) $data, true);

        return is_array($decoded) ? $decoded : throw new \LogicException('No data JSON');
    }

    /**
     * @return array{fields: array<string, string>, files: list<array{name: string, filename: string, contentType: string, contents: string}>}
     */
    public static function parseMultipart(string $contentType, string $body): array
    {
        if (! preg_match('/boundary="?([^";]+)"?/', $contentType, $m)) {
            throw new \LogicException('No multipart boundary');
        }
        $fields = [];
        $files = [];
        foreach (explode('--' . $m[1], $body) as $part) {
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }
            [$head, $contents] = explode("\r\n\r\n", substr($part, 2), 2) + [1 => ''];
            $contents = substr($contents, 0, -2);   // the CRLF before the next boundary
            preg_match('/name="([^"]*)"/', $head, $name);
            if (preg_match('/filename="([^"]*)"/', $head, $filename)) {
                preg_match('/Content-Type: (.+)/i', $head, $type);
                $files[] = ['name' => $name[1] ?? '', 'filename' => $filename[1], 'contentType' => trim($type[1] ?? ''), 'contents' => $contents];
            } else {
                $fields[$name[1] ?? ''] = $contents;
            }
        }

        return ['fields' => $fields, 'files' => $files];
    }

    /** @return array<string, string> */
    public static function parseForm(string $body): array
    {
        $form = [];
        foreach ($body === '' ? [] : explode('&', $body) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $form[rawurldecode($key)] = rawurldecode($value);
        }

        return $form;
    }

    // ---- endpoints ----------------------------------------------------------------

    private function token(RequestInterface $request, string $body): ResponseInterface
    {
        if ($this->accountsOutage) {
            return new Response(503);
        }
        if ($this->throttleRefresh) {
            // Documented at https://www.zoho.com/accounts/protocol/oauth/token-limits.html
            return self::json(400, ['error_description' => 'You have made too many requests continuously. Please try again after some time.', 'error' => 'Access Denied']);
        }
        $form = self::parseForm($body);
        $valid = ! $this->rejectRefresh
            && $request->getMethod() === 'POST'
            && ($form['grant_type'] ?? null) === 'refresh_token'
            && ($form['client_id'] ?? null) === self::CLIENT_ID
            && ($form['client_secret'] ?? null) === self::CLIENT_SECRET
            && ($form['refresh_token'] ?? null) === self::REFRESH_TOKEN;

        if (! $valid) {
            // Zoho documents the error names, not the HTTP status; 200 with an "error" is assumed.
            return self::json(200, ['error' => 'invalid_code']);
        }
        ++$this->tokenCalls;

        return self::json(200, ['access_token' => 'token-' . $this->tokenCalls, 'api_domain' => 'https://www.zohoapis.com', 'token_type' => 'Bearer', 'expires_in' => $this->expiresIn]);
    }

    private function create(RequestInterface $request, string $body): ResponseInterface
    {
        $parts = self::parseMultipart($request->getHeaderLine('Content-Type'), $body);
        $data = json_decode($parts['fields']['data'] ?? '', true);
        $input = is_array($data) && is_array($data['requests'] ?? null) ? $data['requests'] : null;
        if ($input === null || $parts['files'] === []) {
            return self::error(400, 9015, 'Extra key found');   // not the documented code for this case; none is documented
        }

        $id = (string) (14197000000767334 + 100 * ++$this->sequence);
        $documents = [];
        foreach ($parts['files'] as $i => $file) {
            $documents[] = ['document_id' => (string) ((int) $id + 1 + $i), 'document_name' => $file['filename'], 'document_order' => (string) $i, 'contents' => $file['contents']];
        }
        $actions = [];
        foreach ((array) ($input['actions'] ?? []) as $i => $action) {
            $actions[] = [
                'action_id' => (string) ((int) $id + 50 + $i),
                'action_type' => $action['action_type'] ?? 'SIGN',
                'recipient_name' => $action['recipient_name'] ?? '',
                'recipient_email' => $action['recipient_email'] ?? '',
                'signing_order' => $action['signing_order'] ?? 0,
                'verify_recipient' => $action['verify_recipient'] ?? false,
                'verification_type' => $action['verification_type'] ?? null,
                'recipient_countrycode' => $action['recipient_countrycode'] ?? '',
                'recipient_phonenumber' => $action['recipient_phonenumber'] ?? '',
                'action_status' => 'NOACTION',
                'fields' => [],
            ];
        }
        $this->requests[$id] = [
            'name' => (string) ($input['request_name'] ?? ''),
            'status' => 'draft',
            'sequential' => (bool) ($input['is_sequential'] ?? false),
            'notes' => is_string($input['notes'] ?? null) ? $input['notes'] : null,
            'documents' => $documents,
            'actions' => $actions,
            'deleted' => false,
        ];

        return self::ok(['requests' => $this->requestJson($id), 'message' => 'Document has been added']);
    }

    private function submit(string $id, string $body): ResponseInterface
    {
        if (! $this->found($id)) {
            return self::notFound();
        }
        if ($this->failSubmit !== null) {
            return self::error(400, $this->failSubmit, 'Submit failed');
        }
        if ($this->requests[$id]['status'] !== 'draft') {
            return self::error(400, 12008, 'This document has already been submitted.');
        }
        $data = json_decode(self::parseForm($body)['data'] ?? '', true);
        foreach ((array) ($data['requests']['actions'] ?? []) as $sent) {
            foreach ($this->requests[$id]['actions'] as &$action) {
                if ($action['action_id'] === ($sent['action_id'] ?? null)) {
                    $action['fields'] = array_merge(...array_values(array_map(fn ($group) => (array) $group, (array) ($sent['fields'] ?? []))));
                }
            }
            unset($action);
        }
        foreach ($this->requests[$id]['actions'] as &$action) {
            if ($action['action_type'] === 'SIGN' && $action['fields'] === []) {
                return self::error(400, 9101, 'Add at least one field for a signer.');
            }
            $action['action_status'] = 'UNOPENED';
        }
        unset($action);
        $this->requests[$id]['status'] = 'inprogress';

        return self::ok(['requests' => $this->requestJson($id), 'message' => 'Document submitted successfully']);
    }

    private function recall(string $id): ResponseInterface
    {
        if (! $this->found($id)) {
            return self::notFound();
        }
        if ($this->requests[$id]['status'] !== 'inprogress') {
            return self::error(400, 4003, 'Document cannot be recalled');   // code not documented
        }
        $this->requests[$id]['status'] = 'recalled';

        return self::ok(['action_time' => 1546600722216, 'message' => 'Document has been recalled']);
    }

    /** --form 'expire_by="30 November 2024"' */
    private function extend(string $id, RequestInterface $request, string $body): ResponseInterface
    {
        if (! $this->found($id)) {
            return self::notFound();
        }
        $expireBy = self::parseMultipart($request->getHeaderLine('Content-Type'), $body)['fields']['expire_by'] ?? '';
        if (\DateTimeImmutable::createFromFormat('!j F Y', $expireBy) === false) {
            return self::error(400, 0, 'Invalid expire_by');   // code not documented
        }
        $this->requests[$id]['expireBy'] = $expireBy;

        return self::ok(['message' => 'Document validity has been extended']);
    }

    public function expireBy(string $requestId): ?string
    {
        return $this->requests[$requestId]['expireBy'] ?? null;
    }

    private function delete(string $id): ResponseInterface
    {
        if (! $this->found($id)) {
            return self::notFound();
        }
        $this->requests[$id]['deleted'] = true;

        return self::ok(['message' => 'Document deleted successfully']);
    }

    private function details(string $id): ResponseInterface
    {
        return $this->found($id) ? self::ok(['requests' => $this->requestJson($id), 'message' => 'Document has been retrieved']) : self::notFound();
    }

    /** Zoho serves /pdf at any stage: the original while open, the signed file once completed. */
    private function pdf(string $id): ResponseInterface
    {
        if (! $this->found($id)) {
            return self::notFound();
        }
        if (count($this->requests[$id]['documents']) > 1) {
            return new Response(200, ['Content-Type' => 'application/zip'], self::ZIP);
        }

        return new Response(200, ['Content-Type' => 'application/pdf'], $this->requests[$id]['status'] === 'completed' ? self::SIGNED : self::ORIGINAL);
    }

    private function documentPdf(string $id, string $documentId): ResponseInterface
    {
        foreach ($this->found($id) ? $this->requests[$id]['documents'] : [] as $document) {
            if ($document['document_id'] === $documentId) {
                $pdf = $this->requests[$id]['status'] === 'completed' ? self::SIGNED . $document['document_name'] : self::ORIGINAL;

                return new Response(200, ['Content-Type' => 'application/pdf'], $pdf);
            }
        }

        return self::notFound();
    }

    private function found(string $id): bool
    {
        return isset($this->requests[$id]) && ! $this->requests[$id]['deleted'];
    }

    /** @return array<string, mixed> the "requests" object, in the documented shape */
    private function requestJson(string $id): array
    {
        $r = $this->requests[$id];
        $signed = count(array_filter($r['actions'], fn (array $a) => $a['action_status'] === 'SIGNED'));

        return [
            'request_status' => $this->forcedStatus ?? $r['status'],
            'request_name' => $r['name'],
            'request_id' => $id,
            'is_sequential' => $r['sequential'],
            'notes' => $r['notes'],
            'sign_percentage' => $r['actions'] === [] ? 0 : intdiv(100 * $signed, count($r['actions'])),
            'document_ids' => array_map(fn (array $d) => [
                'document_name' => $d['document_name'],
                'document_size' => strlen($d['contents']),
                'document_order' => $d['document_order'],
                'total_pages' => 2,
                'document_id' => $d['document_id'],
            ], $r['documents']),
            'actions' => $r['actions'],
        ];
    }

    /** @param array<string, mixed> $extra */
    private static function ok(array $extra): ResponseInterface
    {
        return self::json(200, ['code' => 0] + $extra + ['status' => 'success']);
    }

    private static function error(int $http, int $code, string $message): ResponseInterface
    {
        return self::json($http, ['code' => $code, 'message' => $message, 'status' => 'failure']);
    }

    /** Zoho doesn't document the code for an unknown request. */
    private static function notFound(): ResponseInterface
    {
        return self::error(400, 4066, 'Invalid Request ID');
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json;charset=UTF-8'], (string) json_encode($body));
    }
}
