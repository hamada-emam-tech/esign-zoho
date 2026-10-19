<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho\Tests\Unit;

use HamadaEmamTech\Esign\Capabilities\EnvelopeValidator;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerState;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Exceptions\EnvelopeNotSupported;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use HamadaEmamTech\Esign\Zoho\Tests\Support\ArrayCache;
use HamadaEmamTech\Esign\Zoho\Tests\Support\FakeZoho;
use HamadaEmamTech\Esign\Zoho\ZohoOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZohoProviderTest extends TestCase
{
    /** The key and payload of Zoho's own HMAC example; its documented signature is below. */
    private const SAMPLE_SECRET = 'thisisthesamplekeyfortestingpurposes';
    private const SAMPLE_PAYLOAD = '{{"requests":{"request_name":"Test Name"},"notifications":{"operation_type":"RequestSigningSuccess"}}';
    private const SAMPLE_SIGNATURE = 'drbSrM4H816RYKpZiRBLddUa0yHaTrwjtY04sIZFZus=';

    private FakeZoho $zoho;

    protected function setUp(): void
    {
        $this->zoho = new FakeZoho();
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope(
            [new Document('contract.pdf', FakeZoho::ORIGINAL)],
            $signers === [] ? [
                Signer::email('Ahmed Ali', 'ahmed@example.sa', 1, [new SignatureField(2, 60.4, 700.6, 150, 50)]),
                Signer::email('Sara', 'sara@example.sa', 2, [new SignatureField(1, 300, 100, 120.2, 40)]),
            ] : array_values($signers),
            subject: 'Please sign',
            message: 'Lease for unit 4',
        );
    }

    // ---- send ----------------------------------------------------------------------

    #[Test]
    public function send_uploads_the_file_and_the_request_in_one_multipart_body(): void
    {
        $sent = $this->zoho->provider()->send($this->envelope());

        self::assertSame('zoho', $sent->provider);
        self::assertSame(EnvelopeStatus::Sent, $sent->status);
        self::assertSame([], $sent->signingUrls);       // Zoho emails the links

        $create = $this->zoho->lastCall('POST', '#^/api/v1/requests$#');
        self::assertNotNull($create);
        self::assertSame('sign.zoho.com', $create['host']);
        self::assertSame('Zoho-oauthtoken token-1', $create['authorization']);
        $parts = FakeZoho::parseMultipart($create['contentType'], $create['body']);
        self::assertSame([['name' => 'file', 'filename' => 'contract.pdf', 'contentType' => 'application/pdf', 'contents' => FakeZoho::ORIGINAL]], $parts['files']);

        self::assertSame(['requests' => [
            'request_name' => 'Please sign',
            'notes' => 'Lease for unit 4',
            'is_sequential' => true,
            'actions' => [
                ['action_type' => 'SIGN', 'recipient_name' => 'Ahmed Ali', 'recipient_email' => 'ahmed@example.sa', 'signing_order' => 0, 'verify_recipient' => false],
                ['action_type' => 'SIGN', 'recipient_name' => 'Sara', 'recipient_email' => 'sara@example.sa', 'signing_order' => 1, 'verify_recipient' => false],
            ],
        ]], $this->zoho->lastData('POST', '#^/api/v1/requests$#'));
    }

    #[Test]
    public function fields_go_in_the_submit_call_in_pdf_points_with_pages_from_zero(): void
    {
        $id = $this->zoho->provider()->send($this->envelope())->envelopeId;
        $submit = $this->zoho->lastCall('POST', '#/submit$#');
        self::assertNotNull($submit);
        self::assertSame("/api/v1/requests/{$id}/submit", $submit['path']);
        self::assertSame('application/x-www-form-urlencoded', $submit['contentType']);

        [$ahmed, $sara] = $this->zoho->lastData('POST', '#/submit$#')['requests']['actions'];
        $documentId = (string) ((int) $id + 1);

        self::assertSame((string) ((int) $id + 50), $ahmed['action_id']);
        self::assertSame([
            'field_type_name' => 'Signature',
            'field_name' => 'Signature',
            'field_label' => 'Signature',
            'document_id' => $documentId,
            'action_id' => $ahmed['action_id'],
            'is_mandatory' => true,
            'page_no' => 1,             // page 2
            'x_coord' => 60,
            'y_coord' => 701,
            'abs_width' => 150,
            'abs_height' => 50,
        ], $ahmed['fields']['image_fields'][0]);
        self::assertSame(['page_no' => 0, 'x_coord' => 300, 'y_coord' => 100, 'abs_width' => 120, 'abs_height' => 40], array_intersect_key($sara['fields']['image_fields'][0], array_flip(['page_no', 'x_coord', 'y_coord', 'abs_width', 'abs_height'])));
        self::assertSame(EnvelopeStatus::Sent, $this->zoho->provider()->status($id));
    }

    #[Test]
    public function signers_with_the_same_order_sign_in_parallel(): void
    {
        $field = [new SignatureField(1, 10, 10)];
        $this->zoho->provider()->send($this->envelope(Signer::email('A', 'a@example.sa', 3, $field), Signer::email('B', 'b@example.sa', 3, $field)));
        $data = $this->zoho->lastData('POST', '#^/api/v1/requests$#')['requests'];

        self::assertFalse($data['is_sequential']);
        self::assertSame([0, 0], array_column($data['actions'], 'signing_order'));
    }

    #[Test]
    public function signing_orders_are_ranked_from_zero_whatever_numbers_you_use(): void
    {
        $field = [new SignatureField(1, 10, 10)];
        $this->zoho->provider()->send($this->envelope(
            Signer::email('Last', 'c@example.sa', 9, $field),
            Signer::email('First', 'a@example.sa', 2, $field),
            Signer::email('Middle', 'b@example.sa', 5, $field),
        ));
        $data = $this->zoho->lastData('POST', '#^/api/v1/requests$#')['requests'];

        self::assertTrue($data['is_sequential']);
        self::assertSame([2, 0, 1], array_column($data['actions'], 'signing_order'));
    }

    #[Test]
    public function every_document_is_a_file_part_and_fields_land_on_the_right_one(): void
    {
        $envelope = new Envelope(
            [new Document('a.pdf', '%PDF a'), new Document('annex', '%PDF b')],
            [Signer::email('A', 'a@example.sa', 1, [new SignatureField(1, 10, 10, documentIndex: 1)])],
        );
        $id = $this->zoho->provider()->send($envelope)->envelopeId;

        $create = $this->zoho->lastCall('POST', '#^/api/v1/requests$#');
        self::assertNotNull($create);
        $files = FakeZoho::parseMultipart($create['contentType'], $create['body'])['files'];
        self::assertSame(['a.pdf', 'annex.pdf'], array_column($files, 'filename'));     // Zoho checks the extension
        self::assertSame(['file', 'file'], array_column($files, 'name'));

        $field = $this->zoho->lastData('POST', '#/submit$#')['requests']['actions'][0]['fields']['image_fields'][0];
        self::assertSame((string) ((int) $id + 2), $field['document_id']);
    }

    #[Test]
    public function account_options_are_sent_with_the_request(): void
    {
        $options = new ZohoOptions('com', expirationDays: 14, emailReminders: true, reminderPeriod: 3);
        $this->zoho->provider($options)->send($this->envelope());
        $data = $this->zoho->lastData('POST', '#^/api/v1/requests$#')['requests'];

        self::assertSame(14, $data['expiration_days']);
        self::assertTrue($data['email_reminders']);
        self::assertSame(3, $data['reminder_period']);
    }

    #[Test]
    public function sms_otp_sends_the_phone_split_into_country_code_and_number(): void
    {
        $field = [new SignatureField(1, 10, 10)];
        $this->zoho->provider()->send($this->envelope(
            new Signer('Omar', 'omar@example.sa', IdentityMethod::SmsOtp, mobile: '+966 50 000 0000', fields: $field),
            new Signer('Lee', 'lee@example.com', IdentityMethod::SmsOtp, mobile: '+1-415-555-0100', fields: $field),
            new Signer('Asha', 'asha@example.in', IdentityMethod::SmsOtp, mobile: '+919999999999', fields: $field),
        ));
        $actions = $this->zoho->lastData('POST', '#^/api/v1/requests$#')['requests']['actions'];

        self::assertTrue($actions[0]['verify_recipient']);
        self::assertSame('SMS', $actions[0]['verification_type']);
        self::assertSame(['+966', '500000000'], [$actions[0]['recipient_countrycode'], $actions[0]['recipient_phonenumber']]);
        self::assertSame(['+1', '4155550100'], [$actions[1]['recipient_countrycode'], $actions[1]['recipient_phonenumber']]);
        self::assertSame(['+91', '9999999999'], [$actions[2]['recipient_countrycode'], $actions[2]['recipient_phonenumber']]);
    }

    #[Test]
    public function nafath_is_refused_before_any_http_call(): void
    {
        try {
            $this->zoho->provider()->send($this->envelope(Signer::nafath('1012345678', 'Ahmed', 'ahmed@example.sa', 1, [new SignatureField(1, 10, 10)])));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('"nafath"', $e->getMessage());
        }
        self::assertSame([], $this->zoho->calls);       // not even a token request
    }

    #[Test]
    public function sms_without_a_usable_mobile_is_refused_before_any_http_call(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('international format');
        try {
            $this->zoho->provider()->send($this->envelope(new Signer('Omar', 'omar@example.sa', IdentityMethod::SmsOtp, mobile: '0500000000', fields: [new SignatureField(1, 10, 10)])));
        } finally {
            self::assertSame([], $this->zoho->calls);
        }
    }

    #[Test]
    public function a_signer_without_a_field_is_refused_before_any_http_call(): void
    {
        try {
            $this->zoho->provider()->send($this->envelope(Signer::email('Sara', 'sara@example.sa')));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('9101', $e->getMessage());
        }
        self::assertSame([], $this->zoho->calls);
    }

    #[Test]
    public function a_field_on_a_document_that_does_not_exist_is_refused_before_any_http_call(): void
    {
        try {
            $this->zoho->provider()->send($this->envelope(Signer::email('A', 'a@example.sa', 1, [new SignatureField(1, 10, 10, documentIndex: 1)])));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('document 1', $e->getMessage());
        }
        self::assertSame([], $this->zoho->calls);
    }

    #[Test]
    public function more_than_ten_files_are_refused_before_any_http_call(): void
    {
        $documents = array_map(fn (int $i) => new Document("{$i}.pdf", '%PDF'), range(1, 11));

        try {
            $this->zoho->provider()->send(new Envelope($documents, [Signer::email('A', 'a@example.sa', 1, [new SignatureField(1, 10, 10)])]));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('at most 10', $e->getMessage());
        }
        self::assertSame([], $this->zoho->calls);
    }

    #[Test]
    public function a_failed_submit_deletes_the_draft_and_reports_zohos_code(): void
    {
        $this->zoho->failSubmit = 9101;

        try {
            $this->zoho->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('9101', $e->providerCode);
            self::assertSame(400, $e->httpStatus);
        }
        $delete = $this->zoho->lastCall('PUT', '#/delete$#');
        self::assertNotNull($delete);
        self::assertTrue($this->zoho->isDeleted(basename(dirname($delete['path']))));
    }

    // ---- status --------------------------------------------------------------------

    #[Test]
    public function status_follows_the_signers(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        $this->zoho->sign($id, 'ahmed@example.sa');
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));
        $this->zoho->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function declined_expired_and_recalled_are_final(): void
    {
        $provider = $this->zoho->provider();

        $declined = $provider->send($this->envelope())->envelopeId;
        $this->zoho->decline($declined);
        self::assertSame(EnvelopeStatus::Declined, $provider->status($declined));

        $expired = $provider->send($this->envelope())->envelopeId;
        $this->zoho->expire($expired);
        self::assertSame(EnvelopeStatus::Expired, $provider->status($expired));

        $recalled = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($recalled);
        self::assertSame(EnvelopeStatus::Cancelled, $provider->status($recalled));
        self::assertContains("POST /api/v1/requests/{$recalled}/recall", $this->zoho->paths());
    }

    #[Test]
    public function a_draft_is_reported_as_never_submitted(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->forcedStatus = 'draft';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('never submitted');
        $provider->status($id);
    }

    #[Test]
    public function an_unknown_zoho_status_fails_loudly_instead_of_guessing(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->forcedStatus = 'archived';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('"archived"');
        $provider->status($id);
    }

    #[Test]
    public function recalling_twice_surfaces_zohos_error(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        $this->expectException(ProviderError::class);
        $provider->cancel($id);
    }

    #[Test]
    public function an_unknown_request_is_a_provider_error_with_zohos_code(): void
    {
        try {
            $this->zoho->provider()->status('99999');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('4066', $e->providerCode);
            self::assertStringContainsString('Invalid Request ID', $e->getMessage());
            self::assertStringNotContainsString('token-1', $e->getMessage());
            self::assertStringNotContainsString(FakeZoho::REFRESH_TOKEN, $e->getMessage());
        }
    }

    // ---- download ------------------------------------------------------------------

    #[Test]
    public function download_returns_the_signed_pdf_once_completed(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->signAll($id);

        self::assertSame(FakeZoho::SIGNED, $provider->download($id));
        self::assertContains("GET /api/v1/requests/{$id}/pdf", $this->zoho->paths());
    }

    #[Test]
    public function download_refuses_before_everyone_signed_without_fetching_the_file(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->sign($id, 'ahmed@example.sa');

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('partially_signed', $e->getMessage());
        }
        // Zoho would serve the unsigned original here.
        self::assertNotContains("GET /api/v1/requests/{$id}/pdf", $this->zoho->paths());
    }

    #[Test]
    public function several_documents_are_downloaded_one_by_one_not_as_a_zip(): void
    {
        $provider = $this->zoho->provider();
        $envelope = new Envelope(
            [new Document('a.pdf', '%PDF a'), new Document('b.pdf', '%PDF b')],
            [Signer::email('A', 'a@example.sa', 1, [new SignatureField(1, 10, 10)])],
        );
        $id = $provider->send($envelope)->envelopeId;
        $this->zoho->signAll($id);

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('downloadDocuments()', $e->getMessage());
        }
        self::assertNotContains("GET /api/v1/requests/{$id}/pdf", $this->zoho->paths());

        self::assertSame(['a.pdf' => FakeZoho::SIGNED . 'a.pdf', 'b.pdf' => FakeZoho::SIGNED . 'b.pdf'], $provider->downloadDocuments($id));
    }

    // ---- authentication --------------------------------------------------------------

    #[Test]
    public function the_token_is_refreshed_once_and_reused(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->status($id);
        $provider->status($id);

        self::assertSame(1, $this->zoho->tokenCalls);
    }

    #[Test]
    public function the_refresh_goes_in_a_form_body_to_the_accounts_server(): void
    {
        $this->zoho->provider()->send($this->envelope());
        $token = $this->zoho->lastCall('POST', '#^/oauth/v2/token$#');

        self::assertNotNull($token);
        self::assertSame('accounts.zoho.com', $token['host']);
        self::assertSame('', $token['query']);      // secrets stay out of URLs and access logs
        self::assertSame('application/x-www-form-urlencoded', $token['contentType']);
        self::assertSame(
            ['refresh_token' => FakeZoho::REFRESH_TOKEN, 'client_id' => FakeZoho::CLIENT_ID, 'client_secret' => FakeZoho::CLIENT_SECRET, 'grant_type' => 'refresh_token'],
            FakeZoho::parseForm($token['body']),
        );
    }

    #[Test]
    public function the_token_is_refreshed_before_it_expires(): void
    {
        $now = 1_000_000;
        $provider = $this->zoho->provider(clock: function () use (&$now) {
            return $now;
        });
        $id = $provider->send($this->envelope())->envelopeId;

        $now += 3600 - 301;     // still before the 5-minute margin
        $provider->status($id);
        self::assertSame(1, $this->zoho->tokenCalls);

        $now += 2;              // inside it
        $provider->status($id);
        self::assertSame(2, $this->zoho->tokenCalls);
    }

    #[Test]
    public function a_psr16_cache_shares_the_token_between_workers(): void
    {
        $cache = new ArrayCache();
        $id = $this->zoho->provider(cache: $cache)->send($this->envelope())->envelopeId;
        $this->zoho->provider(cache: $cache)->status($id);     // a second worker

        self::assertSame(1, $this->zoho->tokenCalls);
        self::assertStringNotContainsString(FakeZoho::REFRESH_TOKEN, (string) json_encode(array_keys($cache->items)));
    }

    #[Test]
    public function a_401_gets_a_fresh_token_and_one_retry(): void
    {
        $cache = new ArrayCache();
        $provider = $this->zoho->provider(cache: $cache);
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->unauthorizedNext = 1;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        self::assertSame(2, $this->zoho->tokenCalls);
        self::assertSame('token-2', array_values($cache->items)[0]['token']);
    }

    #[Test]
    public function a_second_401_is_not_retried_forever(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->unauthorizedNext = 2;

        try {
            $provider->status($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(401, $e->httpStatus);
        }
        self::assertSame(2, $this->zoho->tokenCalls);
    }

    #[Test]
    public function a_failed_refresh_never_leaks_credentials_into_the_error(): void
    {
        $this->zoho->rejectRefresh = true;

        try {
            $this->zoho->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('invalid_code', $e->providerCode);
            foreach ([FakeZoho::CLIENT_SECRET, FakeZoho::REFRESH_TOKEN] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
                self::assertStringNotContainsString($secret, (string) $e);
            }
        }
    }

    #[Test]
    public function the_token_throttle_is_explained(): void
    {
        $this->zoho->throttleRefresh = true;

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('10 per 10 minutes');
        $this->zoho->provider()->send($this->envelope());
    }

    #[Test]
    public function an_accounts_outage_is_reported_as_an_outage_not_as_bad_credentials(): void
    {
        $this->zoho->accountsOutage = true;

        try {
            $this->zoho->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertStringContainsString('unavailable', $e->getMessage());
        }
    }

    // ---- data centres ----------------------------------------------------------------

    #[Test]
    public function the_saudi_data_centre_uses_saudi_hosts_for_every_call(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions('sa'));
        $provider->status($provider->send($this->envelope())->envelopeId);

        self::assertSame(['accounts.zoho.sa', 'sign.zoho.sa'], array_values(array_unique(array_column($this->zoho->calls, 'host'))));
    }

    // ---- webhooks ------------------------------------------------------------------

    #[Test]
    public function zohos_documented_hmac_example_verifies(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions(webhookSecret: self::SAMPLE_SECRET));

        // The documented sample payload isn't valid JSON, so verification passes and parsing then refuses it.
        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('not JSON');
        $provider->parseWebhook(['X-ZS-WEBHOOK-SIGNATURE' => self::SAMPLE_SIGNATURE], self::SAMPLE_PAYLOAD);
    }

    #[Test]
    public function a_signed_webhook_says_which_request_changed(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions(webhookSecret: 'hook-secret'));
        $body = '{"notifications":{"operation_type":"RequestCompleted","action_id":"1000000000090"},"requests":{"request_status":"completed","request_id":"1000000000000","document_ids":[]}}';
        $signature = base64_encode(hash_hmac('sha256', $body, 'hook-secret', true));

        $event = $provider->parseWebhook(['x-zs-webhook-signature' => [$signature]], $body);

        self::assertSame('zoho', $event->provider);
        self::assertSame('1000000000000', $event->envelopeId);
        self::assertSame('RequestCompleted', $event->payload['notifications']['operation_type']);
    }

    #[Test]
    public function a_numeric_request_id_keeps_every_digit(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions(webhookSecret: 'hook-secret'));
        $body = '{"notifications":{},"requests":{"request_id":14197000000767334}}';

        $event = $provider->parseWebhook(['X-ZS-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, 'hook-secret', true))], $body);

        self::assertSame('14197000000767334', $event->envelopeId);
    }

    #[Test]
    public function a_tampered_webhook_is_refused(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions(webhookSecret: 'hook-secret'));
        $body = '{"requests":{"request_id":"1000000000000"}}';
        $signature = base64_encode(hash_hmac('sha256', $body, 'hook-secret', true));

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('does not match');
        $provider->parseWebhook(['X-ZS-WEBHOOK-SIGNATURE' => $signature], str_replace('1000000000000', '1000000000001', $body));
    }

    #[Test]
    public function a_webhook_without_a_signature_header_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('X-ZS-WEBHOOK-SIGNATURE');
        $this->zoho->provider()->parseWebhook(['content-type' => 'application/json'], '{"requests":{"request_id":"1"}}');
    }

    #[Test]
    public function without_a_webhook_secret_every_webhook_is_refused(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions());
        $body = '{"requests":{"request_id":"1000000000000"}}';

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('No Zoho webhook_secret');
        // Even one "signed" with an empty key.
        $provider->parseWebhook(['X-ZS-WEBHOOK-SIGNATURE' => base64_encode(hash_hmac('sha256', $body, '', true))], $body);
    }

    #[Test]
    public function a_signed_webhook_without_a_request_id_is_refused(): void
    {
        $provider = $this->zoho->provider(new ZohoOptions(webhookSecret: 'hook-secret'));
        $body = '{"notifications":{"operation_type":"RequestViewed"}}';

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('request_id');
        $provider->parseWebhook(['X-ZS-WEBHOOK-SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'hook-secret', true))], $body);
    }

    // ---- capabilities, details, reminders, extension ---------------------------------------

    #[Test]
    public function capabilities_declare_only_what_zoho_documents(): void
    {
        $capabilities = $this->zoho->provider()->capabilities();

        self::assertSame([IdentityMethod::Email, IdentityMethod::SmsOtp], $capabilities->identityMethods);
        self::assertSame(10, $capabilities->maxDocuments);
        self::assertFalse($capabilities->asyncCancel);
        foreach ([Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement, Feature::Reminders, Feature::ExpiryExtension, Feature::SignerDetails] as $feature) {
            self::assertTrue($capabilities->supports($feature), $feature->value);
        }
        self::assertFalse($capabilities->supports(Feature::SigningUrls));          // Zoho emails the links
        self::assertFalse($capabilities->supports(Feature::WebhookManagement));    // no webhook API documented
    }

    #[Test]
    public function the_core_validator_accepts_what_the_driver_sends_and_refuses_nafath(): void
    {
        $capabilities = $this->zoho->provider()->capabilities();
        EnvelopeValidator::check($this->envelope(), $capabilities, 'zoho');

        $this->expectException(EnvelopeNotSupported::class);
        EnvelopeValidator::check($this->envelope(Signer::nafath('1012345678', 'A', 'a@example.sa', 1, [new SignatureField(1, 10, 10)])), $capabilities, 'zoho');
    }

    #[Test]
    public function details_report_each_signer_from_the_actions(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->sign($id, 'ahmed@example.sa');

        $details = $provider->details($id);

        self::assertSame("zoho:{$id}", (string) $details->ref);
        self::assertSame(EnvelopeStatus::PartiallySigned, $details->status);
        self::assertEquals([
            new SignerState('ahmed@example.sa', 'Ahmed Ali', SignerStatus::Signed, null, 1),
            new SignerState('sara@example.sa', 'Sara', SignerStatus::Pending, null, 2),
        ], $details->signers);
        self::assertSame(['sara@example.sa'], array_map(fn (SignerState $s) => $s->email, $details->pending()));
    }

    #[Test]
    public function remind_asks_zoho_to_remind_a_signer_who_still_has_to_sign(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $create = count(array_keys($this->zoho->paths(), 'POST /api/v1/requests', true));

        $provider->remind($id, 'SARA@example.sa');

        self::assertContains("POST /api/v1/requests/{$id}/remind", $this->zoho->paths());
        self::assertSame($create, count(array_keys($this->zoho->paths(), 'POST /api/v1/requests', true)));   // nobody re-added
    }

    #[Test]
    public function remind_refuses_someone_who_already_signed_or_is_not_a_signer(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->zoho->sign($id, 'ahmed@example.sa');

        foreach (['ahmed@example.sa', 'stranger@example.sa'] as $email) {
            try {
                $provider->remind($id, $email);
                self::fail("Expected an exception for {$email}");
            } catch (EsignException $e) {
                self::assertStringContainsString('still has to sign', $e->getMessage());
            }
        }
        self::assertNotContains("POST /api/v1/requests/{$id}/remind", $this->zoho->paths());
    }

    #[Test]
    public function remind_refuses_a_finished_request(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('cancelled');
        $provider->remind($id, 'sara@example.sa');
    }

    #[Test]
    public function extend_sends_the_new_date_in_zohos_format(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        $provider->extend($id, new \DateTimeImmutable('2099-11-30 23:30', new \DateTimeZone('Asia/Riyadh')));

        $call = $this->zoho->lastCall('PUT', '#/extend$#');
        self::assertNotNull($call);
        self::assertStringStartsWith('multipart/form-data', $call['contentType']);
        self::assertSame('30 November 2099', $this->zoho->expireBy($id));     // 20:30 UTC, same day
    }

    #[Test]
    public function extend_refuses_a_date_in_the_past_before_calling_zoho(): void
    {
        $provider = $this->zoho->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $calls = count($this->zoho->calls);

        $this->expectException(EsignException::class);
        try {
            $provider->extend($id, new \DateTimeImmutable('2001-01-01'));
        } finally {
            self::assertCount($calls, $this->zoho->calls);
        }
    }
}
