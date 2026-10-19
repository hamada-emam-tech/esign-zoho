<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho;

use HamadaEmamTech\Esign\Exceptions\EsignException;

/** Behaviour settings for the Zoho Sign driver. Everything except the credentials. */
final readonly class ZohoOptions
{
    /**
     * Data centre => [accounts server, Sign API host].
     *
     * From https://www.zoho.com/sign/api/api-endpoint.html ("Sign URL: https://sign.zoho.<Domain>/api/v1/",
     * "Accounts URL: https://accounts.zoho.<Domain>") and the accounts servers listed at
     * https://www.zoho.com/accounts/protocol/oauth/multi-dc.html. Canada's domain is ".zohocloud.ca", and
     * Zoho Accounts lists https://accounts.zohocloud.ca, so its Sign host is taken to be sign.zohocloud.ca.
     */
    public const DATA_CENTERS = [
        'com' => ['https://accounts.zoho.com', 'https://sign.zoho.com'],
        'eu' => ['https://accounts.zoho.eu', 'https://sign.zoho.eu'],
        'in' => ['https://accounts.zoho.in', 'https://sign.zoho.in'],
        'com.au' => ['https://accounts.zoho.com.au', 'https://sign.zoho.com.au'],
        'jp' => ['https://accounts.zoho.jp', 'https://sign.zoho.jp'],
        'ca' => ['https://accounts.zohocloud.ca', 'https://sign.zohocloud.ca'],
        'sa' => ['https://accounts.zoho.sa', 'https://sign.zoho.sa'],
        'uk' => ['https://accounts.zoho.uk', 'https://sign.zoho.uk'],
        'sg' => ['https://accounts.zoho.sg', 'https://sign.zoho.sg'],
        'ae' => ['https://accounts.zoho.ae', 'https://sign.zoho.ae'],
    ];

    /** Other names people use for the same data centres. */
    private const ALIASES = ['us' => 'com', 'au' => 'com.au', 'zohocloud.ca' => 'ca'];

    public string $dataCenter;

    public string $accountsUrl;

    public string $signUrl;

    public function __construct(
        /** The domain your Zoho Sign account lives on: the end of the URL you log in to (sign.zoho.<this>). */
        string $dataCenter = 'com',
        /** The HMAC secret key set on the webhook in Zoho Sign. Without it every webhook is refused. */
        #[\SensitiveParameter] public ?string $webhookSecret = null,
        /** Days until the request expires. Null leaves it to the account's default. */
        public ?int $expirationDays = null,
        /** Zoho's automatic reminders. Null leaves it to the account's default. */
        public ?bool $emailReminders = null,
        /** Remind every n days. Only sent together with $emailReminders. */
        public ?int $reminderPeriod = null,
        /** Overrides the accounts server from $dataCenter, e.g. for a proxy. */
        ?string $accountsUrl = null,
        /** Overrides the Sign API host from $dataCenter. Without the /api/v1 suffix. */
        ?string $signUrl = null,
    ) {
        $key = strtolower(ltrim(trim($dataCenter), '.'));
        $key = self::ALIASES[$key] ?? $key;
        $urls = self::DATA_CENTERS[$key] ?? null;

        if ($urls === null && ($accountsUrl === null || $signUrl === null)) {
            throw new EsignException(sprintf(
                'Zoho data_center must be one of %s, got "%s". Or set both accounts_url and sign_url.',
                implode(', ', array_keys(self::DATA_CENTERS)),
                $dataCenter,
            ));
        }

        $this->dataCenter = $key;
        $this->accountsUrl = rtrim($accountsUrl ?? $urls[0] ?? '', '/');
        $this->signUrl = rtrim($signUrl ?? $urls[1] ?? '', '/');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'dataCenter' => $this->dataCenter,
            'accountsUrl' => $this->accountsUrl,
            'signUrl' => $this->signUrl,
            'webhookSecret' => $this->webhookSecret === null ? null : '***',
            'expirationDays' => $this->expirationDays,
            'emailReminders' => $this->emailReminders,
            'reminderPeriod' => $this->reminderPeriod,
        ];
    }
}
