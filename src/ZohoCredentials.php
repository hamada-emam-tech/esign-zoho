<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho;

/**
 * The OAuth client from Zoho's API console plus a long-lived refresh token.
 * Kept out of var_dump() and error trackers.
 *
 * Zoho's refresh token "has unlimited usage until revoked", so treat it like a password.
 */
final readonly class ZohoCredentials
{
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter] public string $clientSecret,
        #[\SensitiveParameter] public string $refreshToken,
        /** Only sent when set. Zoho Sign's quick start lists it; Zoho Accounts' refresh reference does not. */
        public ?string $redirectUri = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'redirectUri' => $this->redirectUri, 'secrets' => '***'];
    }
}
