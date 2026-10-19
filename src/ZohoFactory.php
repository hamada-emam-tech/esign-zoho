<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds the Zoho Sign provider from its config block:
 *
 *     'zoho' => [
 *         'data_center'    => 'sa',                 // com (default), eu, in, com.au, jp, ca, sa, uk, sg, ae
 *         'client_id'      => '...', 'client_secret' => '...',
 *         'refresh_token'  => '...',
 *         'webhook_secret' => '...',                // the HMAC secret key set on the Zoho Sign webhook
 *         // optional: redirect_uri, expiration_days, email_reminders, reminder_period,
 *         //           accounts_url, sign_url (override data_center),
 *         //           http_client, request_factory, stream_factory, cache (PSR-16)
 *     ]
 */
final class ZohoFactory implements ProviderFactory
{
    private const REQUIRED = ['client_id', 'client_secret', 'refresh_token'];

    public function create(array $config): SignatureProvider
    {
        $missing = array_values(array_filter(self::REQUIRED, fn (string $key) => ! is_string($config[$key] ?? null) || $config[$key] === ''));
        if ($missing !== []) {
            throw new EsignException('Zoho config is missing: ' . implode(', ', $missing) . '.');
        }

        $options = new ZohoOptions(
            dataCenter: (string) ($config['data_center'] ?? 'com'),
            webhookSecret: self::stringOrNull($config['webhook_secret'] ?? null),
            expirationDays: self::positiveIntOrNull($config, 'expiration_days'),
            emailReminders: isset($config['email_reminders']) ? (bool) $config['email_reminders'] : null,
            reminderPeriod: self::positiveIntOrNull($config, 'reminder_period'),
            accountsUrl: self::stringOrNull($config['accounts_url'] ?? null),
            signUrl: self::stringOrNull($config['sign_url'] ?? null),
        );

        [$client, $requests, $streams] = self::http($config);
        $cache = $config['cache'] ?? null;

        $credentials = new ZohoCredentials(
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['refresh_token'],
            self::stringOrNull($config['redirect_uri'] ?? null),
        );

        return new ZohoProvider(
            new ZohoApi($client, $requests, $streams, $credentials, $options->accountsUrl, $options->signUrl, $cache instanceof CacheInterface ? $cache : null),
            $options,
        );
    }

    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient. The same order as the SADQ driver.
     *
     * The optional libraries are referenced by name, so neither Composer nor static analysis needs them.
     *
     * @param array<string, mixed> $config
     *
     * @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}
     */
    private static function http(array $config): array
    {
        $client = $config['http_client'] ?? null;
        $requests = $config['request_factory'] ?? null;
        $streams = $config['stream_factory'] ?? $requests;

        if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
            return [$client, $requests, $streams];
        }

        $found = null;
        if (class_exists('Http\Discovery\Psr18ClientDiscovery') && class_exists('Http\Discovery\Psr17FactoryDiscovery')) {
            $found = [
                self::callStatic('Http\Discovery\Psr18ClientDiscovery', 'find'),
                self::callStatic('Http\Discovery\Psr17FactoryDiscovery', 'findRequestFactory'),
                self::callStatic('Http\Discovery\Psr17FactoryDiscovery', 'findStreamFactory'),
            ];
        } elseif (class_exists('GuzzleHttp\Client') && class_exists('GuzzleHttp\Psr7\HttpFactory')) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();
            $found = [new \GuzzleHttp\Client(['timeout' => 60]), $factory, $factory];
        } elseif (class_exists('Symfony\Component\HttpClient\Psr18Client')) {
            $class = 'Symfony\Component\HttpClient\Psr18Client';
            $symfony = new $class();
            $found = [$symfony, $symfony, $symfony];
        }

        if ($found !== null) {
            $client = $client instanceof ClientInterface ? $client : $found[0];
            $requests = $requests instanceof RequestFactoryInterface ? $requests : $found[1];
            $streams = $streams instanceof StreamFactoryInterface ? $streams : $found[2];
            if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
                return [$client, $requests, $streams];
            }
        }

        throw new EsignException('Zoho Sign needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
    }

    private static function callStatic(string $class, string $method): mixed
    {
        $callable = [$class, $method];

        return is_callable($callable) ? $callable() : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $config */
    private static function positiveIntOrNull(array $config, string $key): ?int
    {
        if (! isset($config[$key])) {
            return null;
        }
        $value = $config[$key];
        if (! is_numeric($value) || (int) $value < 1) {
            throw new EsignException("Zoho {$key} must be a positive number of days.");
        }

        return (int) $value;
    }
}
