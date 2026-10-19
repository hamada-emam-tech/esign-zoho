<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Testing\AssertsProviderConformance;
use HamadaEmamTech\Esign\Zoho\Tests\Support\FakeZoho;
use HamadaEmamTech\Esign\Zoho\ZohoCredentials;
use HamadaEmamTech\Esign\Zoho\ZohoFactory;
use HamadaEmamTech\Esign\Zoho\ZohoOptions;
use HamadaEmamTech\Esign\Zoho\ZohoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZohoFactoryTest extends TestCase
{
    use AssertsProviderConformance;

    /** @return array<string, string> */
    private function config(): array
    {
        return ['client_id' => FakeZoho::CLIENT_ID, 'client_secret' => FakeZoho::CLIENT_SECRET, 'refresh_token' => FakeZoho::REFRESH_TOKEN];
    }

    #[Test]
    public function it_builds_the_provider_and_finds_guzzle_on_its_own(): void
    {
        self::assertInstanceOf(ZohoProvider::class, (new ZohoFactory())->create($this->config()));
    }

    #[Test]
    public function it_conforms_to_the_shared_provider_contract(): void
    {
        $this->assertProviderConformance(new ZohoFactory(), $this->config(), 'zoho', __DIR__ . '/../../composer.json');
    }

    #[Test]
    public function missing_settings_are_all_named_at_once(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('Zoho config is missing: client_secret, refresh_token.');
        (new ZohoFactory())->create(['client_id' => 'x', 'client_secret' => '']);
    }

    #[Test]
    public function an_unknown_data_centre_is_refused(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('data_center must be one of com, eu, in, com.au, jp, ca, sa, uk, sg, ae, got "mars"');
        (new ZohoFactory())->create(['data_center' => 'mars'] + $this->config());
    }

    #[Test]
    public function a_bad_number_of_days_is_refused(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('expiration_days');
        (new ZohoFactory())->create(['expiration_days' => 0] + $this->config());
    }

    /** @return array<string, array{string, string, string}> */
    public static function dataCentres(): array
    {
        return [
            'US' => ['com', 'accounts.zoho.com', 'sign.zoho.com'],
            'US alias' => ['us', 'accounts.zoho.com', 'sign.zoho.com'],
            'Europe' => ['eu', 'accounts.zoho.eu', 'sign.zoho.eu'],
            'India' => ['in', 'accounts.zoho.in', 'sign.zoho.in'],
            'Australia' => ['com.au', 'accounts.zoho.com.au', 'sign.zoho.com.au'],
            'Japan' => ['jp', 'accounts.zoho.jp', 'sign.zoho.jp'],
            'Canada' => ['ca', 'accounts.zohocloud.ca', 'sign.zohocloud.ca'],
            'Saudi Arabia' => ['sa', 'accounts.zoho.sa', 'sign.zoho.sa'],
            'Saudi Arabia, with a dot' => ['.SA', 'accounts.zoho.sa', 'sign.zoho.sa'],
            'United Kingdom' => ['uk', 'accounts.zoho.uk', 'sign.zoho.uk'],
        ];
    }

    #[Test]
    #[DataProvider('dataCentres')]
    public function each_data_centre_talks_to_its_own_hosts(string $dataCenter, string $accounts, string $sign): void
    {
        $fake = new FakeZoho();
        $factory = new HttpFactory();
        $provider = (new ZohoFactory())->create(['data_center' => $dataCenter, 'http_client' => $fake, 'request_factory' => $factory] + $this->config());

        $provider->send(new Envelope([new Document('a.pdf', '%PDF')], [Signer::email('A', 'a@example.sa', 1, [new SignatureField(1, 10, 10)])]));

        self::assertSame([$accounts, $sign], array_values(array_unique(array_column($fake->calls, 'host'))));
    }

    #[Test]
    public function both_hosts_can_be_overridden(): void
    {
        $options = new ZohoOptions('anything', accountsUrl: 'https://accounts.proxy.test/', signUrl: 'https://sign.proxy.test/');

        self::assertSame(['https://accounts.proxy.test', 'https://sign.proxy.test'], [$options->accountsUrl, $options->signUrl]);
    }

    #[Test]
    public function the_package_declares_itself_to_the_core_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $installedJson = sys_get_temp_dir() . '/esign-zoho-installed-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installedJson, (string) json_encode(['packages' => [$composer]]));

        $registry = new ProviderRegistry($installedJson);
        $found = $registry->has('zoho');   // discovery reads the file lazily, on first use
        unlink($installedJson);

        self::assertTrue($found);
        self::assertInstanceOf(ZohoProvider::class, $registry->create('zoho', $this->config()));
    }

    #[Test]
    public function credentials_stay_out_of_debug_output(): void
    {
        $dump = print_r(new ZohoCredentials('client-id', 'client-secret-value', 'refresh-token-value'), true);
        $dump .= print_r(new ZohoOptions(webhookSecret: 'webhook-secret-value'), true);

        foreach (['client-secret-value', 'refresh-token-value', 'webhook-secret-value'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertStringContainsString('client-id', $dump);
    }
}
