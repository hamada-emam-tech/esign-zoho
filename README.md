# hamada-emam-tech/esign-zoho

[Zoho Sign](https://www.zoho.com/sign/) driver for [hamada-emam-tech/esign](https://github.com/hamada-emam-tech/esign-php): e-signature requests on any Zoho data centre, including Saudi Arabia (`sign.zoho.sa`).

> **Status: built from Zoho Sign's official API documentation, not yet verified against a live Zoho account.**
> Every endpoint, field and response shape comes from the pages listed under [Sources](#sources), and the test suite runs against a fake built from those same shapes. Where the docs are ambiguous, the driver picks the safest reading. Those choices are listed under [Assumptions](#assumptions-to-confirm-on-a-live-account). Please report anything a real account does differently.

```bash
vendor/bin/esign add zoho          # or: composer require hamada-emam-tech/esign-zoho
```

The core discovers the driver on install. You also need a PSR-18 HTTP client: Guzzle and Symfony HttpClient are picked up automatically. Zoho Sign's API needs an Enterprise or API plan, and each submitted envelope uses API credits.

## Configure

Create a server-based client in the Zoho API console of your data centre (`https://api-console.zoho.<dc>`), generate a grant code with the scopes `ZohoSign.documents.ALL`, and exchange it once for a refresh token. Zoho's [quick start](https://www.zoho.com/sign/api/quick-start-with-zoho-sign-api.html) walks through it. The driver turns the refresh token into access tokens on its own.

```php
$esign = new Esign([
    'default'   => 'zoho',
    'providers' => [
        'zoho' => [
            'data_center'    => 'sa',          // the end of the URL you log in to: sign.zoho.sa
            'client_id'      => getenv('ZOHO_CLIENT_ID'),
            'client_secret'  => getenv('ZOHO_CLIENT_SECRET'),
            'refresh_token'  => getenv('ZOHO_REFRESH_TOKEN'),
            'webhook_secret' => getenv('ZOHO_WEBHOOK_SECRET'),
            'cache'          => $psr16Cache,   // recommended: Zoho allows 10 token requests per 10 minutes
        ],
    ],
]);
```

| Option | Default | Notes |
|---|---|---|
| `data_center` | `com` | `com` (US), `eu`, `in`, `com.au`, `jp`, `ca`, `sa`, `uk`, `sg`, `ae`. Selects both hosts (see below). `us` and `au` are accepted as aliases. |
| `client_id`, `client_secret`, `refresh_token` | required | From the Zoho API console. The refresh token works until revoked: keep it secret. |
| `redirect_uri` | none | Sent with the token request only if set. |
| `webhook_secret` | none | The HMAC secret key set on the Zoho Sign webhook. **Without it every webhook is refused.** |
| `expiration_days` | account default | Days until a request expires. |
| `email_reminders`, `reminder_period` | account default | Zoho's automatic reminders, every `reminder_period` days. |
| `accounts_url`, `sign_url` | from `data_center` | Override both hosts, for a proxy or a data centre not listed. |
| `http_client`, `request_factory`, `stream_factory` | auto-detected | PSR-18 / PSR-17 objects. |
| `cache` | none | A PSR-16 cache, to share the access token across requests and workers. |

| `data_center` | Accounts server (tokens) | API host |
|---|---|---|
| `com` | `https://accounts.zoho.com` | `https://sign.zoho.com/api/v1` |
| `eu` | `https://accounts.zoho.eu` | `https://sign.zoho.eu/api/v1` |
| `in` | `https://accounts.zoho.in` | `https://sign.zoho.in/api/v1` |
| `com.au` | `https://accounts.zoho.com.au` | `https://sign.zoho.com.au/api/v1` |
| `jp` | `https://accounts.zoho.jp` | `https://sign.zoho.jp/api/v1` |
| `ca` | `https://accounts.zohocloud.ca` | `https://sign.zohocloud.ca/api/v1` |
| `sa` | `https://accounts.zoho.sa` | `https://sign.zoho.sa/api/v1` |
| `uk` | `https://accounts.zoho.uk` | `https://sign.zoho.uk/api/v1` |
| `sg` | `https://accounts.zoho.sg` | `https://sign.zoho.sg/api/v1` |
| `ae` | `https://accounts.zoho.ae` | `https://sign.zoho.ae/api/v1` |

A token from one data centre does not work in another, so `data_center` must match your account.

## Send

```php
use HamadaEmamTech\Esign\Envelope\{Envelope, Document, Signer, SignatureField, IdentityMethod};

$sent = $esign->send(new Envelope(
    documents: [Document::fromPath('contract.pdf')],
    signers: [
        Signer::email('Ahmed Ali', 'ahmed@example.sa', order: 1, fields: [new SignatureField(page: 1, x: 60, y: 700)]),
        new Signer('Sara', 'sara@example.sa', IdentityMethod::SmsOtp, mobile: '+966500000000', order: 2,
            fields: [new SignatureField(page: 2, x: 300, y: 650)]),
    ],
    subject: 'Please sign the agreement',     // Zoho's request_name
    message: 'The lease for unit 4',          // Zoho's notes, sent to every recipient
));

$ref = (string) $sent->ref();    // "zoho:14197000000767334". Store this one string.
```

- **Every signer needs at least one `SignatureField`.** Zoho refuses to send a request where a signer has nothing to sign (error 9101), so the driver refuses it first.
- Coordinates are PDF points from the top left of the page, exactly as Zoho uses them (A4 portrait is 595 × 842). Pages start at 1 here and at 0 in Zoho; the driver converts.
- Signers with different `order` values sign one after another (`is_sequential: true`). Signers who all share one order sign in parallel.
- Up to 10 documents per envelope. Each becomes a file in the same Zoho request.
- Zoho emails each signer their link, so `$sent->signingUrls` is empty.

## Webhook, then status, then download

In Zoho Sign: **Settings → Developer settings → Webhooks → Create webhook**, enter your URL, pick the events, and **turn on the HMAC signature** with a secret key. Put that key in `webhook_secret`. Zoho can't show the key again later. Webhooks are per account (two at most), not per envelope, so `Envelope::$callbackUrl` is not used.

```php
// POST /webhooks/zoho
$result = $esign->handleWebhook($request->headers->all(), $request->getContent(), 'zoho');
if ($result->isValid()) {
    dispatch(new SyncEnvelope((string) $result->ref()));    // do the rest in a queue
}
return response($result->response->body, $result->response->status);   // always 200, or Zoho retries

// In the job:
$status = $esign->status($ref);                // "zoho:…", stored from $sent->ref()
if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->download($ref);
}
```

`handleWebhook()` checks `X-ZS-WEBHOOK-SIGNATURE` (base64 HMAC-SHA256 of the raw body, keyed with your secret, compared in constant time) and returns only `requests.request_id`. It never reads the status from the body: `status()` asks Zoho. Pass the body exactly as received. A re-encoded JSON body won't verify.

`download()` returns the signed PDF and runs only when the status is `Signed`. Zoho's `/pdf` endpoint returns a ZIP when a request has several documents, so for those `download()` throws and points you to:

```php
/** @var \HamadaEmamTech\Esign\Zoho\ZohoProvider $zoho */
$zoho = $esign->provider('zoho');
$pdfs = $zoho->downloadDocuments($envelopeId);    // ['contract.pdf' => '%PDF…', 'annex.pdf' => '%PDF…']
```

## Status mapping

| Zoho `request_status` | `EnvelopeStatus` |
|---|---|
| `inprogress`, no signer's `action_status` is `SIGNED` | `Sent` |
| `inprogress`, at least one signer `SIGNED` | `PartiallySigned` |
| `completed` | `Signed` |
| `declined` | `Declined` |
| `expired` | `Expired` |
| `recalled` | `Cancelled` |
| `draft` | throws `ProviderError` (never submitted; `send()` deletes drafts it can't submit) |
| anything else | throws `ProviderError`. Please report it. |

| Zoho `action_status` (SIGN and INPERSONSIGN actions) | `SignerStatus` |
|---|---|
| `SIGNED` | `Signed` |
| `DECLINED`, `REJECTED` (not in Zoho's docs, read defensively) | `Declined` |
| `UNOPENED`, `VIEWED`, `NOACTION`, anything else | `Pending` |

## Capabilities and optional features

| | Zoho Sign |
|---|---|
| Identity methods | `email`, `sms_otp` |
| Documents per envelope | 10 |
| Multiple documents, signing order, field placement | yes |
| Signing links returned by `send()` | no (Zoho emails them) |
| `remind()`, `extendDeadline()`, `details()` | yes |
| Webhook management through the API | no (set up in Zoho Sign's settings) |
| Cancel | immediate (Zoho's recall) |

The core checks every envelope against this before sending, and the optional features go through the same calls as for every provider:

```php
$esign->details($ref)->pending();                                 // who hasn't signed yet
$esign->remind($ref, 'sara@example.sa');                          // see below
$esign->extendDeadline($ref, new DateTimeImmutable('+14 days'));  // a date: Zoho takes no time of day
$esign->cancel($ref);                                             // Zoho's recall
```

- **`remind()`**: Zoho's remind endpoint takes no recipient. It reminds everyone who still has to sign. The driver first checks that the email belongs to a signer who hasn't signed, and refuses otherwise. Nobody is added twice.
- **`extendDeadline()`** sends the date in UTC as Zoho's `expire_by` (`30 November 2026`). A date in the past is refused before calling Zoho.
- **Identity**: `email` means the signing link goes to the signer's inbox (`verify_recipient: false`). `sms_otp` sets `verification_type: SMS` and needs `Signer::$mobile` in international format (`+9665…`). It's split into `recipient_countrycode` (`+966`) and `recipient_phonenumber`. **Nafath is not supported**: Zoho Sign offers Nafath through emdha in its web app, but its API reference documents only EMAIL, SMS, WHATSAPP and OFFLINE verification. A Nafath, Absher, Wathq or UAE PASS signer is refused before any HTTP call.

## What this driver handles for you

1. **Two-step send, as Zoho documents it.** `POST /requests` uploads the files (multipart, built by the driver so any PSR-17 factory works) with the recipients. `POST /requests/{id}/submit` then adds the fields, which need the `document_id`s Zoho assigns on upload, and sends. If the submit fails, the draft is deleted rather than left behind.
2. **Tokens.** Access tokens live an hour. The driver caches each token until 5 minutes before it expires (in a PSR-16 cache if you pass one), retries once with a fresh token on a 401, and explains Zoho's "10 token requests per 10 minutes" throttle if you hit it. The refresh token goes in a form body, never in a URL.
3. **Errors.** Zoho's `{"code", "message", "status": "failure"}` becomes a `ProviderError` with the HTTP status and Zoho's code. Token errors carry only Zoho's short error name (`invalid_code`, `invalid_client`). Credentials never appear in a message.
4. **IDs.** Zoho's 17-digit IDs stay strings end to end, even where they arrive as JSON numbers.
5. **Checks before any call:** unsupported identity methods, a signer without fields, a field on a missing document or page 0, more than 10 files, an SMS signer without a usable mobile number.

## Assumptions to confirm on a live account

- **Fields in the submit body** use the grouped shape from Zoho's submit reference (`"fields": {"image_fields": [...]}`). Zoho's use-case samples also show a flat array. The grouped one is what the endpoint's reference page specifies.
- **`signing_order`** starts at 0, as in Zoho's create example. Orders are ranked from 0, and signers that share an order share a rank. Zoho doesn't document ties in a sequential request.
- **SMS phone format**: `recipient_countrycode` as `+966`. Zoho's basic-concepts page names this key, and its how-to shows `recipient_countrycode_iso` (`"IN"`) instead. Neither page gives the dial-code format.
- **Canada** (`ca`): Zoho lists the accounts server `accounts.zohocloud.ca`, and the Sign host is taken to be `sign.zohocloud.ca`. **`sg` and `ae`** follow Zoho Sign's `sign.zoho.<domain>` / `accounts.zoho.<domain>` rule, but Zoho Accounts' own data-centre list doesn't include them yet. Use `accounts_url` and `sign_url` if they differ.
- **Draft status**: a request this driver sent should never read `draft`. If it does, `status()` throws instead of guessing.
- HTTP status codes and error codes for an unknown request, a recall of a finished request, and a revoked token aren't documented. The driver only relies on `status`, `code` and the HTTP status being an error.

## Testing without real signers

Use the core's `fake` provider in your app's tests:

```php
$esign = new Esign(['default' => 'fake']);
$sent = $esign->send($envelope);
$esign->provider()->sign($sent->envelopeId);
```

The package's own tests run against `tests/Support/FakeZoho.php`, a PSR-18 fake built from the response shapes in Zoho's documentation, including Zoho's own HMAC example vector. They pass the core's `AssertsProviderConformance` check.

## Sources

Zoho Sign API documentation:

- API root endpoint and data centres: https://www.zoho.com/sign/api/api-endpoint.html
- Quick start (tokens, `Zoho-oauthtoken` header): https://www.zoho.com/sign/api/quick-start-with-zoho-sign-api.html
- OAuth scopes: https://www.zoho.com/sign/api/oauth.html
- Create document: https://www.zoho.com/sign/api/document-managment/create-document.html
- Send document for signature (submit): https://www.zoho.com/sign/api/document-managment/send-document-for-signature.html
- Update document: https://www.zoho.com/sign/api/document-managment/update-document.html
- Get details of a document: https://www.zoho.com/sign/api/document-managment/get-details-of-a-particular-document.html
- Recall document: https://www.zoho.com/sign/api/document-managment/recall-document.html
- Remind recipient: https://www.zoho.com/sign/api/document-managment/remind-recipient.html
- Extend document: https://www.zoho.com/sign/api/document-managment/extend-document.html
- Delete document: https://www.zoho.com/sign/api/document-managment/delete-document.html
- Download PDF (PDF or ZIP): https://www.zoho.com/sign/api/document-managment/download-pdf.html
- Download a particular PDF: https://www.zoho.com/sign/api/document-managment/download-particular-pdf.html
- Field types: https://www.zoho.com/sign/api/document-managment/retrieve-field-type.html
- Basic concepts (statuses, coordinate system, file limit): https://www.zoho.com/sign/api/getting-started-guide/basic-concepts/fields.html
- Recipient authentication modes: https://www.zoho.com/sign/api/how-tos/authentication-modes.html
- Sending a document for signature (use case): https://www.zoho.com/sign/api/use-cases/sending-a-document-for-signature.html
- Error codes: https://www.zoho.com/sign/api/error-codes.html
- Webhooks and payload: https://help.zoho.com/portal/en/kb/zoho-sign/admin-guide/webhooks/articles/webhooks-management
- Webhook HMAC verification: https://help.zoho.com/portal/en/kb/zoho-sign/admin-guide/webhooks/articles/securing-zoho-sign-webhooks-with-hmac-authentication

Zoho Accounts (OAuth):

- Refresh an access token: https://www.zoho.com/accounts/protocol/oauth/web-apps/access-token-expiry.html
- Data-centre accounts servers: https://www.zoho.com/accounts/protocol/oauth/multi-dc.html
- Token limits: https://www.zoho.com/accounts/protocol/oauth/token-limits.html
