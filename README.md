# PesePay Direct for WHMCS — 0.2.0 release candidate

A separate WHMCS gateway (`pesepaydirect`) for PesePay's Direct API. Development is local only. Nothing has been uploaded to or activated on clientzone.azaire.co.zw, and no production client records, invoices or settings have been changed.

**Not cleared for production:** local tests pass, but the first real sandbox initiation returned HTTP 500 without a reference. That attempt remains unresolved and was not retried. Complete sandbox and isolated WHMCS staging validation before production deployment. Production deployment requires the owner's explicit approval.

## Administrator settings

Activate **PesePay Direct** in the staging WHMCS payment-gateway settings. An administrator enters all credentials in the WHMCS admin area under **Setup > Payment Gateways > Manage Existing Gateways > PesePay Direct**:

- Environment: Sandbox (default) or Production.
- Sandbox Integration Key and Sandbox Encryption Key.
- Production Integration Key and Production Encryption Key.
- Individual enable switches for supported wallet/currency combinations.

Each key field uses WHMCS's password field type. WHMCS manages credential storage and supplies the configured values to the gateway on the server. Keys are never included in checkout HTML, JavaScript or module logs. The encryption key must contain exactly 32 bytes. When the settings are saved, the module rejects an encryption key of the wrong length, keys containing spaces or line breaks, and a selected environment without both keys; WHMCS then shows the error and does not save. The **Gateway settings** tab of the reports addon shows whether each key is set and valid, without displaying it. Do not configure WHMCS gateway currency conversion: this module uses the invoice's currency and current outstanding balance directly.

Sandbox payments deliberately do **not** create WHMCS ledger entries or mark real invoices paid. Production and sandbox attempts are separated. Retain the corresponding keys while attempts remain unsettled; changing credentials can prevent reconciliation of earlier payments.

## Transactions & reports addon

`modules/addons/pesepayreports` adds **Addons > PesePay Transactions & Reports** to the WHMCS admin area. Activate it under **System Settings > Addon Modules** and grant access only to the admin roles that should see payment data.

- **Transactions:** every attempt made through PesePay Direct, filtered by environment, outcome (verified success, failed/declined, pending, needs attention), dates (UTC), wallet, currency, invoice and reference. Each attempt has a detail page linking to its invoice and client.
- **Reports:** totals per invoice currency, success rates, daily activity and a wallet/status breakdown. Currencies are never added together. Sandbox and production are always reported separately.
- **Needs attention / Reconciliation:** stale pending payments, uncertain statuses and attempts without a provider reference, across both environments.
- **Gateway settings:** key status per environment, the active environment and the enabled wallets, with a link to the gateway settings page.
- **CSV export** (optional, on by default): up to 5,000 filtered attempts, without keys, phone numbers or provider payloads. Spreadsheet formula prefixes are neutralised.
- **Check status with PesePay** (optional, off by default): a read-only, verified status lookup for one attempt. It never records a payment or changes the attempt.

The addon reads the gateway's `mod_pesepaydirect_attempts` table only. It covers payments made through this module, not older Payzw history. Activation and deactivation never delete records.

## Payment methods

- EcoCash: USD and ZiG; phone approval, including instructions from PesePay's current catalogue.
- O'mari: USD and ZiG; phone number followed by a six-digit OTP, submitted once from the server.
- PayGo: ZiG; displays the documented QR image and payment code.
- InnBucks: USD; adapter is present but **unverified and disabled by default**. PesePay's InnBucks page does not document its response metadata keys. Confirm a genuine response and adjust the adapter before enabling it. The current renderer understands the documented `qrCode` and `qrCodeUrl` metadata used by PayGo; do not assume InnBucks returns these fields.

Only EcoCash USD is enabled by default. Production methods are filtered against PesePay's live catalogue and amount limits. Card methods are excluded even if the catalogue says they do not require redirects: the Direct API documentation says cards require Hosted or Embedded checkout. The sandbox supports only EcoCash USD among these wallet methods.

ZiG is mapped explicitly: EcoCash and PayGo use `ZiG`, while O'mari uses `ZWG`. No exchange-rate conversion is performed. Unsupported currencies are rejected.

## Runtime requirements and current limits

- Target: WHMCS 8+ with PHP 8.1+, cURL, OpenSSL and PDO MySQL. PHP 8.3 and MySQL 8.0 were used locally. Exact compatibility with the installed WHMCS release is still a staging test, not an established result.
- HTTPS WHMCS System URL, outbound HTTPS to PesePay and a publicly reachable callback endpoint on the staging installation.
- MySQL advisory locks (`GET_LOCK`) and permission to create the module's InnoDB table. An authenticated owner request creates the table when it is absent; the CLI installer can create it beforehand.
- **Account owners only in this candidate.** Delegated users are rejected until their invoice-permission adapter is validated against the installed WHMCS version. Guest invoice payments are not supported.
- No recurring capture, automatic refunds, subscription cancellation or split-settlement applications. These capabilities are not advertised by the module. Handle any refunds in the provider portal and reconcile WHMCS separately.
- No automatic charge retry for an ambiguous network/API result. An attempt without a reference needs merchant-panel/support reconciliation. Do not delete it or change it to failed merely to enable another charge.
- Amounts are represented as integer minor units and must have at most two meaningful decimal places.

## Staging installation

1. Create a licensed, isolated WHMCS staging installation with synthetic clients and invoices. Disable outbound customer email and provisioning there. Do not clone live client data just to test this gateway.
2. Extract only the `modules/` directory from the release ZIP into that staging installation. This adds a new module and does not replace Payzw.
3. Configure the sandbox credentials in the administrator payment-gateway settings, leaving the environment on Sandbox. Restrict gateway visibility during setup.
4. Install the table if desired:

   ```sh
   php /path/to/staging-whmcs/modules/gateways/pesepaydirect/reconcile.php --install
   ```

5. Schedule reconciliation every minute using the staging host's PHP executable:

   ```cron
   * * * * * /usr/bin/php /path/to/staging-whmcs/modules/gateways/pesepaydirect/reconcile.php >> /path/outside/public_html/pesepay-reconcile.log 2>&1
   ```

   Keep the log outside the web root. The worker prevents overlapping runs, processes up to 20 due attempts per run and stops starting additional work after approximately 50 seconds. A single pending request may extend that time by its HTTP timeout. Monitor deferred attempts and queue size as volume grows.

6. Verify callback authorization headers reach PHP. Callbacks are queued durably and acknowledged; the worker or authenticated checkout polling then verifies the result with PesePay. The browser need not stay open.
7. Exercise sandbox success and failure, refresh/reload, duplicate clicks, missed callbacks, repeated callbacks, session expiry and an interrupted network request. A sandbox success should display confirmation without changing the WHMCS invoice ledger.
8. Use an isolated test merchant/application and obtain explicit authorization for any later small-value production-provider transactions needed to test the other wallets. Do not activate production customer checkout as part of this step.

## Payment recording and recovery

A charge request is saved before the HTTP call. Repeated submissions reuse the saved request or active attempt. The callback body is only a notification: its status, amount and URLs are not used to credit invoices. The server fetches status from a fixed PesePay API host and verifies reference, original callback URL, amount and currency. Only a verified `SUCCESS` can record a production payment.

Invoice advisory locks serialize initiation and reconciliation. The WHMCS ledger is checked for the gateway transaction ID before adding payment, so a retry can recover when the ledger write succeeded but the module's completion update failed. This prevents duplicate accounting entries through this module; it does not claim that arbitrary third-party WHMCS provisioning hooks are atomic across process failures.

`PARTIALLY_PAID` remains pending. Unknown statuses remain unsettled. `FAILED`, `TIME_OUT`, `ERROR` and `SERVICE_UNAVAILABLE` block a new charge pending reconciliation. Clear final declines permit a customer-initiated new attempt. Pending and uncertain attempts continue to be checked; clearly declined attempts are not repeatedly polled unless another callback queues them.

Support logs contain attempt IDs and fixed event messages, not phone numbers, keys, OTPs or raw provider payloads. Query `mod_pesepaydirect_attempts` through authorized administration to investigate a specific invoice. Never manually set `credited=1` to resolve an unknown payment.

## Verification

Local checks completed:

- 11 PHP tests for the reports addon and key validation: outcome classification, per-currency totals, environment separation, CSV formula neutralisation, output escaping, CSRF and option gating, and key status shown without values.
- 25 PHP tests with a separate MySQL database, including concurrent workers, duplicate callbacks, interrupted settlement, request idempotency, amount/currency/reference mismatch, OTP timeout, sandbox isolation, encryption interoperability and failed provider responses.
- Authentication/CSRF/output checks for guests, account owners, delegated users and escaped checkout markup.
- 5 DOM-based JavaScript tests for double clicks, lost-response recovery, sandbox messaging, unsafe metadata and single-use OTP submission.
- PHP and JavaScript syntax checks.

These use a mocked PesePay transport and WHMCS boundary adapters. They do not substitute for staging WHMCS tests. A real sandbox call was attempted separately and returned HTTP 500. No successful real sandbox payment or callback delivery has been established.

Development test commands (only against an isolated database named `pesepay_test`):

```sh
PESEPAY_TEST_DSN='mysql:unix_socket=/path/to/test-mysql.sock;dbname=pesepay_test' php tests/run.php
php tests/auth.php
PESEPAY_TEST_DSN='mysql:unix_socket=/path/to/test-mysql.sock;dbname=pesepay_test' php tests/reports.php
npm ci --ignore-scripts
npm run test:ui
```

The PHP tests truncate their module/test tables. Never point them at a WHMCS database. Node and jsdom are development dependencies only, not production requirements.

## References

Reviewed on 22 November 2026:

- https://developers.pesepay.com/payments/direct-api/
- https://developers.pesepay.com/api/make-payment/
- https://developers.pesepay.com/api/check-payment-status/
- https://developers.pesepay.com/security/encryption/
- https://developers.pesepay.com/webhooks/result-callback/
- https://developers.pesepay.com/webhooks/verifying-callbacks/
- https://developers.pesepay.com/payment-methods/omari/
- https://developers.pesepay.com/payment-methods/paygo/
- https://developers.pesepay.com/testing/
- https://developers.whmcs.com/payment-gateways/callbacks/
- https://developers.whmcs.com/advanced/authentication/
