<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Zoho;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\ExtendsExpiry;
use HamadaEmamTech\Esign\Contracts\Features\ReportsSigners;
use HamadaEmamTech\Esign\Contracts\Features\SendsReminders;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\Features\RecognizesWebhooks;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Support\Headers;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeDetails;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerState;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Throwable;

/**
 * Zoho Sign (sign.zoho.com and its regional data centres), API v1.
 *
 * Built from Zoho Sign's published API documentation; not yet checked against a live account.
 *
 * - send() takes two calls, as Zoho documents it: POST /requests (multipart: one "file" part per
 *   document plus a "data" JSON part with the request and its recipients), then
 *   POST /requests/{id}/submit with the signature fields. Fields need the document_id Zoho assigns
 *   on upload, so they can't go in the first call. If the submit fails, the draft is deleted.
 * - Field coordinates: Zoho's origin is the top left of the page, in the page's own units ("an A4
 *   page in portrait orientation has a width of 595 and height of 842"), which are PDF points.
 *   SignatureField is also PDF points from the top left, so x/y/width/height pass through, rounded
 *   to whole numbers (Zoho types them Int). Zoho's page_no starts at 0; SignatureField's page at 1.
 * - Zoho refuses to send a request where a signer has no field (error 9101), so every signer
 *   needs at least one SignatureField. That is checked before calling Zoho.
 * - Zoho webhooks are set per account in Zoho Sign's settings, not per request, so
 *   Envelope::$callbackUrl is not used. Zoho's API reference documents no webhook endpoints, so
 *   this driver does not implement ManagesWebhooks.
 *
 * https://www.zoho.com/sign/api/document-managment/create-document.html
 * https://www.zoho.com/sign/api/document-managment/send-document-for-signature.html
 * https://www.zoho.com/sign/api/getting-started-guide/basic-concepts/fields.html
 */
final class ZohoProvider implements SignatureProvider, HasCapabilities, ReportsSigners, SendsReminders, ExtendsExpiry, RecognizesWebhooks
{
    /** "Every document can have up to 10 files." */
    private const MAX_FILES = 10;

    private const SIGNATURE_HEADER = 'x-zs-webhook-signature';

    /**
     * Two-digit E.164 country calling codes. 1 and 7 are the only one-digit codes, and every
     * code not listed here is three digits (E.164 codes are prefix-free).
     */
    private const TWO_DIGIT_CALLING_CODES = [
        '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49',
        '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66',
        '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98',
    ];

    public function __construct(
        private readonly ZohoApi $api,
        private readonly ZohoOptions $options = new ZohoOptions(),
    ) {
    }

    public function name(): string
    {
        return 'zoho';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            // Nafath is offered in Zoho Sign's web app (through emdha) but not documented in its API.
            identityMethods: [IdentityMethod::Email, IdentityMethod::SmsOtp],
            features: [
                Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement,
                Feature::Reminders, Feature::ExpiryExtension, Feature::SignerDetails,
            ],
            maxDocuments: self::MAX_FILES,
            // The recall call answers "Document has been recalled"; nothing documents a delay.
            asyncCancel: false,
        );
    }

    /**
     * The request's status and each signer, from the actions of GET /requests/{id}.
     * Zoho emails signing links, so signingUrl is always null.
     */
    public function details(string $envelopeId): EnvelopeDetails
    {
        $request = $this->fetch($envelopeId);
        $signers = [];
        foreach ($this->signActions($request) as $action) {
            $signers[] = new SignerState(
                (string) ($action['recipient_email'] ?? ''),
                trim((string) ($action['recipient_name'] ?? '')),
                self::signerStatus($action),
                null,
                // Zoho counts signing_order from 0; SignerState counts from 1 like Signer::$order.
                is_numeric($action['signing_order'] ?? null) ? (int) $action['signing_order'] + 1 : null,
            );
        }
        usort($signers, fn (SignerState $a, SignerState $b) => ($a->order ?? 0) <=> ($b->order ?? 0));

        return new EnvelopeDetails(new EnvelopeRef('zoho', $envelopeId), $this->map($request, $envelopeId), $signers);
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        // Everything that can be checked is checked before the first call, so a refusal costs nothing.
        if (count($envelope->documents) > self::MAX_FILES) {
            throw new EsignException('Zoho Sign takes at most ' . self::MAX_FILES . ' files per request; this envelope has ' . count($envelope->documents) . '.');
        }
        $orders = $this->signingOrders($envelope->signers);
        $actions = [];
        foreach ($envelope->signers as $i => $signer) {
            $this->checkFields($signer, count($envelope->documents));
            $actions[] = $this->action($signer, $orders[$i]);
        }

        $request = array_filter([
            'request_name' => $envelope->subject,
            'notes' => $envelope->message !== '' ? $envelope->message : null,
            'is_sequential' => count(array_unique($orders)) > 1,
            'expiration_days' => $this->options->expirationDays,
            'email_reminders' => $this->options->emailReminders,
            'reminder_period' => $this->options->emailReminders === true ? $this->options->reminderPeriod : null,
            'actions' => $actions,
        ], fn ($v) => $v !== null);

        $files = [];
        foreach ($envelope->documents as $document) {
            $files[] = ['name' => 'file', 'filename' => self::filename($document->name, $document->mimeType), 'contentType' => $document->mimeType, 'contents' => $document->contents];
        }

        $created = $this->api->postMultipart('/api/v1/requests', ['data' => self::json(['requests' => $request])], $files);
        $draft = is_array($created['requests'] ?? null) ? $created['requests'] : [];
        $requestId = self::id($draft['request_id'] ?? null);
        if ($requestId === null) {
            throw new ProviderError('zoho', 'The request was created without a request_id.');
        }

        try {
            $documentIds = $this->documentIds($draft);
            if (count($documentIds) !== count($envelope->documents)) {
                throw new ProviderError('zoho', sprintf('Uploaded %d files but Zoho lists %d documents on request %s.', count($envelope->documents), count($documentIds), $requestId));
            }
            $submit = $this->submitActions($envelope->signers, $orders, $draft, $documentIds);
            $this->api->post('/api/v1/requests/' . rawurlencode($requestId) . '/submit', ['data' => self::json(['requests' => ['actions' => $submit]])]);
        } catch (Throwable $e) {
            // Don't leave a half-made draft behind. Deleting moves it to Zoho's trash.
            try {
                $this->api->put('/api/v1/requests/' . rawurlencode($requestId) . '/delete');
            } catch (Throwable) {
            }
            throw $e;
        }

        // Zoho emails each signer their link. Links for embedded signing need a separate call
        // (is_embedded + /embedtoken) that this driver does not make.
        return new SentEnvelope('zoho', $requestId, EnvelopeStatus::Sent);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        return $this->map($this->fetch($envelopeId), $envelopeId);
    }

    /**
     * The signed PDF of a single-document request. Zoho returns a ZIP from /pdf when a request has
     * several documents; use downloadDocuments() for those.
     */
    public function download(string $envelopeId): string
    {
        $request = $this->signedRequest($envelopeId);
        $documents = $this->documentIds($request);
        if (count($documents) > 1) {
            throw new EsignException(sprintf('Zoho request %s has %d documents, and Zoho returns them as a ZIP. Use downloadDocuments() to get each signed PDF.', $envelopeId, count($documents)));
        }

        [, $bytes] = $this->api->download('/api/v1/requests/' . rawurlencode($envelopeId) . '/pdf');

        return self::pdf($bytes, $envelopeId);
    }

    /** Recall: "recipients can no longer view or sign it". */
    public function cancel(string $envelopeId): void
    {
        $this->api->post('/api/v1/requests/' . rawurlencode($envelopeId) . '/recall');
    }

    public function recognizesWebhook(array $headers, string $rawBody): bool
    {
        return Headers::has($headers, self::SIGNATURE_HEADER);
    }

    /**
     * Zoho signs webhooks with HMAC-SHA256 over the raw body, keyed with the secret set on the webhook,
     * base64-encoded in X-ZS-WEBHOOK-SIGNATURE. Without a configured secret every webhook is refused.
     * https://help.zoho.com/portal/en/kb/zoho-sign/admin-guide/webhooks/articles/securing-zoho-sign-webhooks-with-hmac-authentication
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $secret = (string) $this->options->webhookSecret;
        if ($secret === '') {
            throw new InvalidWebhook('No Zoho webhook_secret is configured. Refusing the webhook: turn on the HMAC signature on the Zoho Sign webhook and set its secret key as webhook_secret.');
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        $signatures = [];
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) === self::SIGNATURE_HEADER) {
                $signatures = [...$signatures, ...array_map('strval', (array) $values)];
            }
        }
        if ($signatures === []) {
            throw new InvalidWebhook('Zoho webhook has no X-ZS-WEBHOOK-SIGNATURE header.');
        }
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, trim($signature)) || $valid;
        }
        if (! $valid) {
            throw new InvalidWebhook('Zoho webhook signature does not match.');
        }

        $payload = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING);
        if (! is_array($payload)) {
            throw new InvalidWebhook('Zoho webhook body is not JSON.');
        }
        // {"notifications": {"operation_type": ...}, "requests": {"request_id": ...}}
        $id = self::id(is_array($payload['requests'] ?? null) ? ($payload['requests']['request_id'] ?? null) : null);
        if ($id === null) {
            throw new InvalidWebhook('Zoho webhook has no requests.request_id.');
        }

        /** @var array<string, mixed> $payload */
        return new WebhookEvent('zoho', $id, null, $payload);
    }

    // ---- Zoho-only operations ------------------------------------------------------

    /**
     * Every signed PDF of a request, by file name, from /requests/{id}/documents/{document_id}/pdf.
     * Only once the request is completed.
     *
     * @return array<string, string> file name => PDF bytes
     */
    public function downloadDocuments(string $envelopeId): array
    {
        $request = $this->signedRequest($envelopeId);
        $pdfs = [];
        foreach ($this->documentIds($request) as $i => $document) {
            [, $bytes] = $this->api->download('/api/v1/requests/' . rawurlencode($envelopeId) . '/documents/' . rawurlencode($document['id']) . '/pdf');
            $name = $document['name'] !== '' ? $document['name'] : "document-{$i}.pdf";
            $pdfs[isset($pdfs[$name]) ? "{$i}-{$name}" : $name] = self::pdf($bytes, $envelopeId);
        }

        return $pdfs;
    }

    /**
     * POST /requests/{id}/remind: "A reminder is sent to the person who needs to sign."
     * https://www.zoho.com/sign/api/document-managment/remind-recipient.html
     *
     * Zoho's endpoint takes no recipient: it reminds whoever still has to sign. So this checks that
     * $signerEmail is one of the request's signers and has not signed yet, then asks Zoho to remind.
     * Nobody is added twice.
     */
    public function remind(string $envelopeId, string $signerEmail): void
    {
        $request = $this->fetch($envelopeId);
        $status = $this->map($request, $envelopeId);
        if ($status->isFinal()) {
            throw new EsignException("Zoho request {$envelopeId} is {$status->value}; there is nobody to remind.");
        }
        $pending = false;
        foreach ($this->signActions($request) as $action) {
            if (strcasecmp((string) ($action['recipient_email'] ?? ''), $signerEmail) === 0) {
                $pending = $pending || self::signerStatus($action) === SignerStatus::Pending;
            }
        }
        if (! $pending) {
            throw new EsignException("{$signerEmail} is not a signer of Zoho request {$envelopeId} who still has to sign.");
        }

        $this->api->post('/api/v1/requests/' . rawurlencode($envelopeId) . '/remind');
    }

    /**
     * PUT /requests/{id}/extend with expire_by, "the expiration date of the envelope signing link".
     * https://www.zoho.com/sign/api/document-managment/extend-document.html
     *
     * Zoho's example sends a date only ("30 November 2024"), so the time of day is dropped and the
     * date is taken in UTC. Pass the last day signers may sign.
     */
    public function extend(string $envelopeId, DateTimeInterface $until): void
    {
        $day = DateTimeImmutable::createFromInterface($until)->setTimezone(new DateTimeZone('UTC'));
        if ($day->format('Y-m-d') < gmdate('Y-m-d')) {
            throw new EsignException('The new deadline for Zoho request ' . $envelopeId . ' is in the past.');
        }

        $this->api->putMultipart('/api/v1/requests/' . rawurlencode($envelopeId) . '/extend', ['expire_by' => $day->format('j F Y')]);
    }

    // ---- internals ------------------------------------------------------------------

    /**
     * Zoho's signing_order for each signer. The documented example numbers recipients from 0, so the
     * signers' orders are ranked from 0, and signers that share an order share a rank.
     * Zoho does not document how ties behave in a sequential request.
     *
     * @param list<Signer> $signers
     *
     * @return list<int>
     */
    private function signingOrders(array $signers): array
    {
        $distinct = array_values(array_unique(array_map(fn (Signer $s) => $s->order, $signers)));
        sort($distinct);
        $rank = array_flip($distinct);

        return array_map(fn (Signer $s) => $rank[$s->order], $signers);
    }

    /** @return array<string, mixed> */
    private function action(Signer $signer, int $order): array
    {
        $action = [
            'action_type' => 'SIGN',
            'recipient_name' => $signer->name,
            'recipient_email' => $signer->email,
            'signing_order' => $order,
            // The signing link goes to the signer's email: that is the email identity check.
            'verify_recipient' => false,
        ];

        return match ($signer->identity) {
            IdentityMethod::Email => $action,
            IdentityMethod::SmsOtp => [...$action, 'verify_recipient' => true, 'verification_type' => 'SMS', ...$this->phone($signer)],
            // Zoho Sign offers Nafath through emdha in its web app, but its API reference documents only
            // EMAIL, SMS, WHATSAPP and OFFLINE verification. Nothing to send for Nafath.
            default => throw new EsignException("Zoho Sign's API does not support the \"{$signer->identity->value}\" identity method. Use email or sms_otp."),
        };
    }

    /**
     * recipient_countrycode and recipient_phonenumber, "required if SMS verification is used".
     *
     * @return array{recipient_countrycode: string, recipient_phonenumber: string}
     */
    private function phone(Signer $signer): array
    {
        $mobile = preg_replace('/[\s().-]/', '', (string) $signer->mobile) ?? '';
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $mobile)) {
            throw new EsignException("Signer \"{$signer->name}\" needs a mobile number in international format (+9665XXXXXXXX) for SMS verification.");
        }
        $digits = substr($mobile, 1);
        $length = in_array($digits[0], ['1', '7'], true) ? 1 : (in_array(substr($digits, 0, 2), self::TWO_DIGIT_CALLING_CODES, true) ? 2 : 3);

        return ['recipient_countrycode' => '+' . substr($digits, 0, $length), 'recipient_phonenumber' => substr($digits, $length)];
    }

    private function checkFields(Signer $signer, int $documents): void
    {
        if ($signer->fields === []) {
            throw new EsignException("Signer \"{$signer->name}\" has no SignatureField. Zoho Sign refuses to send a request where a signer has no field to sign (error 9101).");
        }
        foreach ($signer->fields as $field) {
            if ($field->documentIndex < 0 || $field->documentIndex >= $documents) {
                throw new EsignException("Signer \"{$signer->name}\" has a field on document {$field->documentIndex}, but the envelope has {$documents} document(s).");
            }
            if ($field->page < 1) {
                throw new EsignException("Signer \"{$signer->name}\" has a field on page {$field->page}. Pages start at 1.");
            }
            if ($field->x < 0 || $field->y < 0 || $field->width <= 0 || $field->height <= 0) {
                throw new EsignException("Signer \"{$signer->name}\" has a field with a negative position or an empty size.");
            }
        }
    }

    /**
     * The submit body: each created action, by its action_id, with its signature fields.
     *
     * @param list<Signer> $signers
     * @param list<int> $orders
     * @param array<mixed> $draft the "requests" object Zoho returned on create
     * @param list<array{id: string, name: string}> $documentIds
     *
     * @return list<array<string, mixed>>
     */
    private function submitActions(array $signers, array $orders, array $draft, array $documentIds): array
    {
        // Match created actions to signers by email and order, not by position: Zoho doesn't promise to
        // keep the order. Same email and order twice is matched in sequence.
        $created = [];
        foreach (is_array($draft['actions'] ?? null) ? $draft['actions'] : [] as $action) {
            $id = is_array($action) ? self::id($action['action_id'] ?? null) : null;
            if ($id !== null) {
                $created[strtolower((string) ($action['recipient_email'] ?? '')) . '|' . (int) ($action['signing_order'] ?? 0)][] = $id;
            }
        }

        $submit = [];
        foreach ($signers as $i => $signer) {
            $key = strtolower($signer->email) . '|' . $orders[$i];
            $actionId = isset($created[$key]) ? array_shift($created[$key]) : null;
            if ($actionId === null) {
                throw new ProviderError('zoho', "Zoho did not return an action for signer \"{$signer->name}\".");
            }
            $submit[] = [
                'action_id' => $actionId,
                'action_type' => 'SIGN',
                'recipient_name' => $signer->name,
                'recipient_email' => $signer->email,
                // The submit reference groups fields by category; Signature is an "image" field.
                'fields' => ['image_fields' => array_map(fn (SignatureField $f) => $this->field($f, $actionId, $documentIds[$f->documentIndex]['id']), $signer->fields)],
            ];
        }

        return $submit;
    }

    /** @return array<string, mixed> */
    private function field(SignatureField $field, string $actionId, string $documentId): array
    {
        return [
            'field_type_name' => 'Signature',
            'field_name' => 'Signature',
            'field_label' => 'Signature',
            'document_id' => $documentId,
            'action_id' => $actionId,
            'is_mandatory' => true,
            'page_no' => $field->page - 1,
            'x_coord' => (int) round($field->x),
            'y_coord' => (int) round($field->y),
            'abs_width' => (int) round($field->width),
            'abs_height' => (int) round($field->height),
        ];
    }

    /**
     * The request's documents in Zoho's document_order, which follows the upload order.
     *
     * @param array<mixed> $request
     *
     * @return list<array{id: string, name: string}>
     */
    private function documentIds(array $request): array
    {
        $documents = [];
        foreach (is_array($request['document_ids'] ?? null) ? $request['document_ids'] : [] as $i => $document) {
            $id = is_array($document) ? self::id($document['document_id'] ?? null) : null;
            if ($id !== null && is_array($document)) {
                $order = is_numeric($document['document_order'] ?? null) ? (int) $document['document_order'] : (int) $i;
                $documents[] = ['order' => $order, 'position' => (int) $i, 'id' => $id, 'name' => is_string($document['document_name'] ?? null) ? $document['document_name'] : ''];
            }
        }
        usort($documents, fn (array $a, array $b) => [$a['order'], $a['position']] <=> [$b['order'], $b['position']]);

        return array_map(fn (array $d) => ['id' => $d['id'], 'name' => $d['name']], $documents);
    }

    /** @return array<string, mixed> the "requests" object of GET /requests/{id} */
    private function fetch(string $envelopeId): array
    {
        $json = $this->api->get('/api/v1/requests/' . rawurlencode($envelopeId));
        $request = $json['requests'] ?? null;
        if (! is_array($request)) {
            throw new ProviderError('zoho', "Zoho returned no details for request {$envelopeId}.");
        }

        /** @var array<string, mixed> $request */
        return $request;
    }

    /** @return array<string, mixed> */
    private function signedRequest(string $envelopeId): array
    {
        $request = $this->fetch($envelopeId);
        $status = $this->map($request, $envelopeId);
        if ($status !== EnvelopeStatus::Signed) {
            throw new EsignException("Zoho request {$envelopeId} is {$status->value}, not signed. There is no signed PDF yet.");
        }

        return $request;
    }

    /**
     * request_status → EnvelopeStatus. Values from Zoho's docs: draft, inprogress, completed, and
     * "in progress, completed, expired, declined, or recalled" in the basic-concepts reference.
     *
     * @param array<string, mixed> $request
     */
    private function map(array $request, string $envelopeId): EnvelopeStatus
    {
        $raw = is_string($request['request_status'] ?? null) ? $request['request_status'] : '';

        return match (strtolower(str_replace([' ', '_', '-'], '', trim($raw)))) {
            'inprogress' => $this->anyoneSigned($request) ? EnvelopeStatus::PartiallySigned : EnvelopeStatus::Sent,
            'completed' => EnvelopeStatus::Signed,
            'declined' => EnvelopeStatus::Declined,
            'expired' => EnvelopeStatus::Expired,
            'recalled' => EnvelopeStatus::Cancelled,
            // A request this driver sent is never a draft: send() deletes drafts it can't submit.
            'draft' => throw new ProviderError('zoho', "Zoho request {$envelopeId} is a draft: it was never submitted for signature."),
            default => throw new ProviderError('zoho', "Zoho returned a request status this driver does not know: \"{$raw}\". Please report it."),
        };
    }

    /** @param array<string, mixed> $request */
    private function anyoneSigned(array $request): bool
    {
        foreach ($this->signActions($request) as $action) {
            if (self::signerStatus($action) === SignerStatus::Signed) {
                return true;
            }
        }

        return false;
    }

    /**
     * The actions that sign (SIGN, INPERSONSIGN), not VIEW copies or approvers.
     *
     * @param array<string, mixed> $request
     *
     * @return list<array<string, mixed>>
     */
    private function signActions(array $request): array
    {
        $actions = [];
        foreach (is_array($request['actions'] ?? null) ? $request['actions'] : [] as $action) {
            if (is_array($action) && in_array(strtoupper((string) ($action['action_type'] ?? '')), ['SIGN', 'INPERSONSIGN'], true)) {
                /** @var array<string, mixed> $action */
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * action_status → SignerStatus. Zoho documents UNOPENED, VIEWED and SIGNED (and returns NOACTION
     * before submit), all but SIGNED meaning the signer still has to act. A declined status isn't
     * documented; DECLINED and REJECTED are read as declined, anything else as pending.
     *
     * @param array<string, mixed> $action
     */
    private static function signerStatus(array $action): SignerStatus
    {
        return match (strtoupper((string) ($action['action_status'] ?? ''))) {
            'SIGNED' => SignerStatus::Signed,
            'DECLINED', 'REJECTED' => SignerStatus::Declined,
            default => SignerStatus::Pending,
        };
    }

    private static function pdf(string $bytes, string $envelopeId): string
    {
        if (! str_starts_with($bytes, '%PDF')) {
            throw new ProviderError('zoho', "Zoho returned something other than a PDF for request {$envelopeId}.");
        }

        return $bytes;
    }

    /** Zoho checks the file extension (error 9020), so a PDF without one gets ".pdf". */
    private static function filename(string $name, string $mimeType): string
    {
        $name = $name !== '' ? $name : 'document';

        return $mimeType === 'application/pdf' && ! str_ends_with(strtolower($name), '.pdf') ? $name . '.pdf' : $name;
    }

    /** Zoho IDs are long numbers, sent as strings; accept either. */
    private static function id(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') || is_int($value) ? (string) $value : null;
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
