# PesePay Direct for WHMCS

A WHMCS payment gateway for [PesePay](https://pesepay.com)'s Direct API, plus an admin reports addon. Clients pay an invoice from their mobile wallet without leaving WHMCS. The invoice is marked paid only after the payment is verified with PesePay.

**Version:** 0.3.0 release candidate.

**Status:** tested end to end on a UAT copy of WHMCS 9.0.7 in **Test mode**. The client pays, PesePay confirms, the invoice is marked Paid and the page reloads. **Not yet tested with live keys.** Do one small real payment per wallet before relying on it (see [Going live](#going-live)).

## What's included

| Path | Purpose |
|---|---|
| `modules/gateways/pesepaydirect.php` | Gateway settings and the checkout box on the invoice page |
| `modules/gateways/pesepaydirect/` | Checkout endpoint, PesePay API client, payment logic, background job, checkout script and styles |
| `modules/gateways/callback/pesepaydirect.php` | Receives PesePay's result callbacks |
| `modules/addons/pesepayreports/` | **Addons > PesePay Transactions & Reports** in WHMCS admin |
| `tests/`, `tools/` | Development tests and a sandbox smoke script. Not needed on the server |

## Requirements

- WHMCS 8 or later (tested on 9.0.7), PHP 8.1 or later with cURL, OpenSSL and PDO MySQL, and MySQL 5.7+/8.0 or MariaDB.
- The WHMCS **System URL** must use **https://**. PesePay's callbacks must be able to reach `https://<your-whmcs>/modules/gateways/callback/pesepaydirect.php`.
- Outbound HTTPS from the server to `api.pesepay.com` (live) and `api.test.sandbox.pesepay.com` (Test mode).
- A cron job that runs every minute (see step 6 below).
- PesePay keys: the **integration key** and **encryption key** (32 characters) from your application in the PesePay Merchant Control Panel. The keys on the panel's home page are **test keys** and only work in Test mode.

## Installation

These steps fit a DirectAdmin- or cPanel-hosted WHMCS such as clientzone.azaire.co.zw. Adjust the paths for your server.

### 1. Back up

Take a backup of the WHMCS files and database first, for example with DirectAdmin's backup tool. Installing only adds files and one database table, but a backup makes rollback trivial.

### 2. Upload the files

Download the release ZIP (or this repository) and upload **only the `modules/` folder** into the WHMCS root, merging with the existing `modules/` folder. Nothing existing is replaced. These are the new files:

```
modules/gateways/pesepaydirect.php
modules/gateways/pesepaydirect/          (whole folder)
modules/gateways/callback/pesepaydirect.php
modules/addons/pesepayreports/           (whole folder)
```

Files should be readable by the web server user, for example 644 for files and 755 for folders.

### 3. Activate the gateway

1. In WHMCS admin, go to **Setup > Payment Gateways** (WHMCS 9: **System Settings > Payment Gateways**), open **All Payment Gateways** and click **PesePay Direct**.
2. If it isn't listed, which can happen on WHMCS 9 for custom gateways, activate it from the server instead. Use SSH, or a one-off DirectAdmin/cPanel cron job:

   ```sh
   php /path/to/whmcs/modules/gateways/pesepaydirect/reconcile.php --activate
   ```

   It then appears under **Manage Existing Gateways**.

### 4. Enter the keys

Under **Manage Existing Gateways > PesePay Direct**:

| Setting | What to enter |
|---|---|
| **Test mode** | Leave **off** on the live site. Turn it on only on a test/UAT copy (see [Test mode](#test-mode)). |
| **Integration key** | The **live** integration key from your PesePay application, or the test key when Test mode is on. |
| **Encryption key** | The matching 32-character encryption key. |
| **Wallet switches** | Start with **EcoCash USD** only. Enable the others after a successful small payment with each (see [Going live](#going-live)). |

Click **Save Changes**. If a key is the wrong length or contains spaces, WHMCS shows an error and doesn't save. Key values are stored encrypted by WHMCS and are never shown on any page, written to logs or sent to the browser.

> Enter the keys straight after activating. Until they're set, a client who chooses PesePay sees an error instead of the payment form.

### 5. Create the database table

The module creates its table (`mod_pesepaydirect_attempts`) automatically the first time a client opens the payment box. To create it in advance:

```sh
php /path/to/whmcs/modules/gateways/pesepaydirect/reconcile.php --install
```

### 6. Schedule the background check (cron, every minute)

This job confirms payments even if the client closes the page or a PesePay callback is lost. In DirectAdmin, go to **Advanced Features > Cron Jobs** (cPanel: **Cron Jobs**) and add:

```cron
* * * * * /usr/local/bin/php /path/to/whmcs/modules/gateways/pesepaydirect/reconcile.php >> /home/USER/pesepay-reconcile.log 2>&1
```

Use the same PHP binary as your WHMCS cron, for example `/usr/local/bin/php` or `/usr/bin/php`, and keep the log **outside** `public_html`. The job never runs twice at once, handles up to 20 payments per run and stops starting new work after about 50 seconds. It is separate from the main WHMCS cron, which stays as it is.

### 7. Activate the reports addon

1. Go to **System Settings > Addon Modules**, find **PesePay Transactions & Reports** and click **Activate**.
2. Click **Configure**, tick the admin roles that may see payment data (for example Full Administrator only), and choose whether to allow **CSV exports** and the read-only **Check status with PesePay** button.
3. Open it under **Addons > PesePay Transactions & Reports**.

### 8. Test, then switch to live

Do a small real payment as described in [Going live](#going-live) before telling clients about the new payment option.

## Test mode

Test mode sends payments to PesePay's sandbox using **test keys**. No real money moves, but **invoices are marked Paid** (transaction IDs start with `PesePay-TEST-`), so the whole flow can be checked on a test copy of WHMCS.

- Only **EcoCash USD** exists in the sandbox. Use `0777777777` for a successful payment and `0770000000` for a failed one.
- Clients see a "TEST MODE — no real money will be taken" notice. The reports addon keeps test payments under its **Test** view, never in live totals, and warns while Test mode is on.
- **Never enable Test mode on the live site.** Anyone paying would get their invoice marked paid without being charged.
- Keys belong to a mode. A payment made in one mode can only be checked again after switching back to that mode with its keys.

## Going live

1. Test mode **off**, live keys entered, only **EcoCash USD** enabled.
2. Create a small invoice (for example USD 1.00) for your own client account, set its payment method to **PesePay Direct**, and pay it with your own EcoCash number.
3. Check that the invoice turns **Paid** with a `PesePay-…` transaction, and that the payment appears under **Addons > PesePay Transactions & Reports** (Live view).
4. Repeat with a small payment for each other wallet before enabling it: O'mari, PayGo and ZiG currencies. These can't be tested in the sandbox. **InnBucks** stays disabled until a real InnBucks response has been checked, because PesePay doesn't document its payment-code fields.
5. Refund test payments, if needed, in the PesePay merchant panel. The module doesn't do refunds.

## How the checkout works for clients

On an unpaid invoice whose payment method is PesePay Direct, the client sees a **Pay with PesePay** box showing the **amount due**:

1. If several wallets are enabled, they pick one from tappable tiles. With only one, it says "Paying with EcoCash".
2. They enter their mobile number and press **Pay USD …**.
3. They approve the prompt on their phone (O'mari: enter the SMS code; PayGo: scan or use the payment code).
4. A spinner shows while the page checks the payment automatically. There is no "check status" button.
5. Once PesePay confirms the payment, the invoice is marked paid, the page reloads and shows the invoice as **Paid**.

Other outcomes:

- **Declined or failed:** "Payment was not completed… You can start another payment." Nothing was charged and the invoice stays unpaid.
- **Rejected by PesePay**, for example because of wrong keys: "Nothing was charged. You can try again."
- **No confirmation from PesePay** after about two minutes: the client is told not to pay again and to contact support. See [Troubleshooting](#troubleshooting).

Only the **account owner** of the invoice can pay it. Delegated users and guest payments are not supported in this version.

### Manual payments (bank transfer and others)

PesePay is one gateway among others. WHMCS's own **Payment Method** dropdown on the invoice switches between PesePay and manual methods such as bank transfer, and the PesePay box points clients to it. Manual payments recorded in admin work as usual. The PesePay box always charges only the **remaining balance**. If an invoice is somehow paid both manually and through PesePay, WHMCS records both and the excess becomes client credit. Check **Needs attention** in the reports addon before recording a manual payment on an invoice with a pending PesePay payment.

## Reports addon

**Addons > PesePay Transactions & Reports**, visible only to the admin roles you allow:

- **Live / Test switch:** test payments are never mixed into live figures.
- **Transactions:** every payment attempt with a status badge (Paid, Failed, Rejected, Pending, Needs attention), filtered by outcome, dates (UTC), wallet, currency, invoice or PesePay reference. Click an attempt for its details, links to the invoice and client, and the optional read-only status check.
- **Reports:** amount collected and success rate per currency (currencies are never added together), a daily collections chart (also available as a table), and attempts and outcomes by wallet.
- **Needs attention:** stale pending payments, uncertain statuses and attempts PesePay never acknowledged.
- **Reconciliation:** unresolved payments across all dates, for live and test separately.
- **Gateway settings:** whether Test mode is on, whether each key is set and valid (never the key itself), and which wallets are enabled.
- **CSV export** of up to 5,000 filtered attempts. Never includes keys or phone numbers.

Clients never see any of this. The addon has no client-area page, and its files return nothing when opened directly.

## Troubleshooting

PesePay's reason for every failure is written to **Billing > Gateway Log** (gateway "PesePay Direct"), with phone numbers masked.

| Gateway log says | Meaning and fix |
|---|---|
| `HTTP 404: Integration key record was not found` | The key isn't valid for this mode. Usually test keys are being used with Test mode off. Enter the application's live keys, or turn Test mode on for test keys. |
| `Payment request rejected by PesePay` | PesePay refused the request, so no payment was created. The reason follows. The client can try again. |
| `Initiation requires reconciliation` | PesePay's reply couldn't be confirmed. The background job keeps checking. If the attempt has no PesePay reference, see below. |
| `Reconciliation deferred` | The background job couldn't check a payment, for example because of missing keys or a mode switch. It retries automatically. |

**An invoice is stuck with "contact support".** PesePay never returned a reference for the last attempt, so a new payment is blocked to avoid charging twice. Check the PesePay merchant panel for that invoice. If no payment was taken, open the attempt under **Addons > PesePay Transactions & Reports > Needs attention**, tick "I checked the PesePay merchant panel…" and click **Clear attempt**. The client can then pay again. Never mark an unknown payment as paid by hand.

**Payments stay pending.** Check that the cron job runs (its log file should update every minute) and that the server can reach `api.pesepay.com` over HTTPS.

## Payment recording and safety

- A payment request is saved before PesePay is called, and repeated clicks reuse it, so double clicks and page reloads can't charge twice.
- Callbacks only trigger a check. The module then asks PesePay for the status itself and verifies the reference, amount, currency and callback address before recording anything. Only a verified `SUCCESS` marks an invoice paid.
- Per-invoice locks stop two processes from recording the same payment. WHMCS's ledger is checked for the transaction ID first, so a payment is never recorded twice.
- If PesePay's answer is ambiguous (timeout, server error, no reference), the module does **not** retry the charge. The background job keeps checking, and an administrator can clear an attempt PesePay never acknowledged.
- PesePay's sandbox can return an empty `referenceNumber`. The reference is then taken from its `pollUrl`, but only when that URL is on PesePay's own API host, and the URL itself is never fetched.
- Logs contain attempt IDs, fixed event messages and PesePay's sanitized error text. They never contain keys, OTPs, phone numbers or raw payloads.
- No refunds, recurring charges or card payments. Cards need PesePay's hosted or embedded checkout, which this module doesn't use.

## Payment methods

| Wallet | Currencies | Client action | Default |
|---|---|---|---|
| EcoCash | USD, ZiG | Approve on phone | USD on |
| O'mari | USD, ZiG | Phone number, then 6-digit SMS code | off |
| PayGo | ZiG | Scan QR / use payment code | off |
| InnBucks | USD | Payment code | off — unverified |

Live wallets are also filtered against PesePay's current catalogue and amount limits. ZiG invoices are sent as `ZiG` for EcoCash and PayGo, and as `ZWG` for O'mari. No exchange-rate conversion is done, and the invoice's own currency and balance are always used. Don't set up WHMCS currency conversion for this gateway.

## Development

Tests use mocked PesePay and WHMCS boundaries and an isolated MySQL database. **Never point them at a WHMCS database**, because they truncate their tables.

```sh
export PESEPAY_TEST_DSN='mysql:unix_socket=/path/to/test-mysql.sock;dbname=pesepay_test'
php tests/run.php        # 30 gateway tests
php tests/reports.php    # 13 reports addon tests
php tests/auth.php       # login, CSRF, escaping, settings
npm ci --ignore-scripts && npm run test:ui   # 13 checkout UI tests (jsdom)
```

To build a release ZIP, archive the `modules/` folder and this README. Node and jsdom are only used for tests.

## References

- PesePay: [Direct API](https://developers.pesepay.com/payments/direct-api/), [Make Payment](https://developers.pesepay.com/api/make-payment/), [Check Payment Status](https://developers.pesepay.com/api/check-payment-status/), [Encryption](https://developers.pesepay.com/security/encryption/), [Result callback](https://developers.pesepay.com/webhooks/result-callback/), [Verifying callbacks](https://developers.pesepay.com/webhooks/verifying-callbacks/), [Testing](https://developers.pesepay.com/testing/)
- PesePay's official PHP SDK, [`codevirtus/pesepay`](https://github.com/codevirtus/pesepay-php) 1.0.3, used as a reference for headers and request fields
- WHMCS: [Payment gateways](https://developers.whmcs.com/payment-gateways/), [Addon modules](https://developers.whmcs.com/addon-modules/)
