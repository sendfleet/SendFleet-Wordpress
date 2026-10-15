<div align="center">

# Send<span>Fleet</span> for WordPress

**The official SendFleet plugin. Every email WordPress sends goes through the SendFleet API — your own Amazon SES (BYOC) or SendFleet's managed SES.**

Paste an API key. That is the setup.

</div>

---

- **Users:** see [`readme.txt`](readme.txt) — it is the WordPress.org listing.
- **API contract:** [docs.sendfleet.net](https://docs.sendfleet.net) (`POST /v1/send`, `GET /v1/me`).
- **Brand:** [brand.sendfleet.net](https://brand.sendfleet.net). Icons and banners in `.wordpress-org/` are generated from the brand vectors by `bin/generate-assets.mjs`.

## Contents

- [How it works](#how-it-works)
- [Repository layout](#repository-layout)
- [Configuration](#configuration)
- [Hooks for developers](#hooks-for-developers)
- [WP-CLI](#wp-cli)
- [Development](#development)
- [Testing](#testing)
- [Releasing](#releasing)

## How it works

```
wp_mail()
  └─ pre_wp_mail filter ─────────────── Mailer            (skips when not connected or routing is off)
       └─ Message_Builder                (header parsing, wp_mail_* filters, phpmailer_init — exactly as core)
            └─ Envelope                  (verified sender, Reply-To, smoke-test rules, attachments, one entry per To/Cc/Bcc)
                 └─ Dispatcher           (one message per ≤50 recipients, one log row per recipient)
                      ├─ Transport ─── POST /v1/send   (circuit breakers, short inline retries)
                      ├─ Queue ─────── WP-Cron         (rate limits, 502/500 retries, background sending)
                      ├─ Fallback ──── PHPMailer       (optional)
                      └─ Log ───────── wp_sendfleet_log
Webhooks ── POST /wp-json/sendfleet/v1/webhook ── HMAC verified ── Log status + Suppressions
Account ─── GET /v1/me (cached) ── plan, usage, verified domains, smoke-test detection
```

Key decisions:

| Decision | Why |
|---|---|
| `pre_wp_mail` instead of redefining `wp_mail()` | No conflict with plugins that only filter mail; WordPress keeps owning the function. |
| Rebuild the message with a PHPMailer subclass and fire `phpmailer_init` | Plugins that customise PHPMailer (WooCommerce's AltBody, BCC-everything plugins) keep working. The object is read back and sent through the API instead of `mail()`. |
| One request per email, To + Cc + Bcc together | The API sends one message to up to 50 recipients (SES's limit); longer lists are split. Each recipient still gets its own log row, because SendFleet counts, logs and reports every recipient separately. A queued email's first row carries the payload and its other rows follow it. If the API answers that several recipients are not available on the account yet, the plugin sends each recipient alone for 12 hours. |
| Sender replaced only when its domain cannot send | The API matches the sender's domain exactly; a non-verified sender would be a guaranteed 403. The original sender becomes Reply-To. |
| Free plan smoke test handled locally | A Shared domain on the Free plan only reaches the account address. Other recipients are skipped without an API call; a foreign Reply-To is moved into the body (contact forms), the sender name is stripped of `< > @ , ; "`. |
| Retry classes from the API's documented strategy | 429 (rate limit) → wait for the window; 502 `queue_error` / connection failures → backoff, 5 attempts; 500 → one retry; timeouts → **never** retried (the email may have been accepted). |
| Circuit breakers | A rejected key stops API calls for 10 minutes (SendFleet blocks an IP after 10 failed authentications a minute); quota and suspension stop calls until they can have changed. |
| Log without bodies | Bodies exist only in the queue payload while an email waits, and are deleted the moment it reaches a final state. |
| Secrets encrypted at rest | libsodium secretbox (bundled `sodium_compat` fallback), key from the site salts or `SENDFLEET_ENCRYPTION_KEY`. |

## Repository layout

```
sendfleet.php                 Plugin header, constants, autoloader, hooks
uninstall.php                 Removes options, tables and cron events (multisite-aware)
readme.txt                    WordPress.org listing
includes/
  class-plugin.php            Wiring
  class-mailer.php            pre_wp_mail integration, source detection
  class-message-builder.php   Core-identical message assembly
  class-php-mailer.php        PHPMailer subclass (exposes queued IDN recipients)
  class-envelope.php          Sending rules → per-recipient request bodies
  class-dispatcher.php        Per-recipient outcome, log rows
  class-transport.php         One API call, breakers, inline retries
  class-queue.php             WP-Cron queue, maintenance
  class-fallback.php          PHPMailer fallback
  class-api-client.php        HTTP client (X-API-Key, no redirects)
  class-api-response.php      Response classification (error codes, retry classes)
  class-account.php           /v1/me snapshot, plans, domain routes, default sender
  class-attachments.php       Attachment allow-list and limits
  class-html-to-text.php      Plain-text alternative
  class-mime-parser.php       Hand-built multipart bodies
  class-log.php               Log + queue table
  class-suppressions.php      Local suppression list
  class-webhooks.php          REST receiver
  class-settings.php          Options + wp-config constants
  class-crypto.php            Secret encryption
  class-state.php             Breakers and diagnostics
  class-status.php            Health issues (notices, Site Health)
  class-site-health.php       Site Health test + debug info
  class-privacy.php           Exporter, eraser, policy text
  class-test-email.php        Test email
  class-cli.php               WP-CLI commands
  admin/                      Menu, screens, list table
  views/                      Screen templates
assets/                       Admin CSS/JS, the mark
languages/sendfleet.pot       Translation template
tests/mock-api/server.js      Mock SendFleet API (no dependencies)
tests/e2e/                    Scenario suite, runner, test mu-plugin
bin/build.sh                  Builds dist/sendfleet-<version>.zip
bin/generate-assets.mjs       Builds .wordpress-org/ icons and banners
.wordpress-org/               Directory assets (icons, banners, screenshots)
```

## Configuration

Everything is in **SendFleet → Settings**. Constants in `wp-config.php` override the stored values:

| Constant | Purpose |
|---|---|
| `SENDFLEET_API_KEY` | API key; keeps it out of the database. |
| `SENDFLEET_FROM_EMAIL` | Default sender. |
| `SENDFLEET_FROM_NAME` | Default sender name. |
| `SENDFLEET_WEBHOOK_SECRET` | Webhook signing secret. |
| `SENDFLEET_ENCRYPTION_KEY` | Key material for encrypting stored secrets instead of the salts. |
| `SENDFLEET_API_URL` | API origin. **Testing only** (the e2e suite points it at the mock). |

## Hooks for developers

| Hook | Type | Use |
|---|---|---|
| `sendfleet_should_route` | filter `(bool $route, array $atts)` | Return `false` to let WordPress send a particular email itself. |
| `sendfleet_from_email` | filter `(string $from, string $original)` | Choose the sender (must be on a verified domain). |
| `sendfleet_from_name` | filter `(string $name, string $original)` | Choose the sender name. |
| `sendfleet_envelope` | filter `(Envelope $envelope, array $message)` | Adjust the prepared message before anything is sent. |
| `sendfleet_request` | filter `(array $request, Envelope $envelope)` | Final `POST /v1/send` body per message. |
| `sendfleet_http_args` | filter `(array $args, string $path)` | `wp_remote_request()` arguments (proxies, timeouts). |
| `sendfleet_api_url` | filter `(string $url)` | API origin. |
| `sendfleet_dispatched` | action `(array $results, Envelope $envelope)` | Per-recipient outcomes of one `wp_mail()` call. |
| `sendfleet_webhook` | action `(string $event, array $payload)` | A verified webhook was processed. |

Core's `wp_mail_succeeded` and `wp_mail_failed` fire as usual; failures carry the per-recipient results under `sendfleet` in the error data.

## WP-CLI

```bash
wp sendfleet status [--refresh] [--format=json]
wp sendfleet connect <key>
wp sendfleet test [<to>] [--from=<address>]
wp sendfleet queue [list|run]
wp sendfleet refresh
wp sendfleet purge-log [--older-than=<days>] [--yes]
```

## Development

Requirements: PHP 7.4+, Composer, Docker (for the test environment), Node (mock API, asset generation).

```bash
composer install          # PHPCS + WordPress Coding Standards + PHPCompatibilityWP
composer lint             # vendor/bin/phpcs
composer lint:fix         # vendor/bin/phpcbf

docker compose up -d      # WordPress on :8088, Mailpit on :8025, mock API inside the network
bash tests/e2e/run.sh     # install, activate, run every scenario
bash tests/e2e/run.sh down  # tear down containers and volumes
```

Log in at http://localhost:8088/wp-admin (admin / admin). Test keys accepted by the mock (`<prefix>.` followed by 32 × `a`):

| Prefix | Account |
|---|---|
| `free0001` | Free plan, Shared domain only → smoke-test mode (`Owner@example.com`) |
| `byoc0001` | Free plan with a BYOC domain (`byoc.example.com`) and a Shared smoke domain |
| `paid0001` | BYOC plan |
| `mngd0001` | Managed Growth (`mail.example.com`), webhooks |
| `quota001` | Free plan, quota used up |
| `unvr0001` | Account email not verified |
| `susp0001` | Sending suspended |

Special recipients on the mock: `fail502@…`, `flaky502@…`, `fail500@…`, `throttle@…`, `slow@…`.

## Testing

| Layer | Command | What it proves |
|---|---|---|
| Syntax | `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;` | Parses on PHP 7.4 – 8.4 (CI matrix). |
| Standards | `vendor/bin/phpcs` | WordPress Coding Standards, PHP 7.4 compatibility, i18n text domain, escaping, prepared SQL. |
| Directory rules | `wp plugin check sendfleet` on `build/sendfleet` | The official WordPress.org Plugin Check. |
| End to end | `bash tests/e2e/run.sh` | 110 checks through the real `wp_mail()`: routing, sender selection, smoke-test rules, Cc/Bcc, HTML→text, multipart, attachments, `phpmailer_init` compatibility, retries and the queue, background sending, timeouts, fallback (to Mailpit), circuit breakers, rate-limit bursts, webhooks and suppressions, Site Health, privacy tools, maintenance. |

## Releasing

1. Bump the version in **three** places: the `Version:` header and `SENDFLEET_VERSION` in `sendfleet.php`, and `Stable tag:` in `readme.txt`. Add a changelog entry. (`bin/build.sh` refuses to build if they differ.)
2. Regenerate the translation template if strings changed:
   `wp i18n make-pot . languages/sendfleet.pot --slug=sendfleet --exclude=vendor,tests,build,dist,bin,node_modules`
3. Commit, then tag with the bare version and push: `git tag 1.0.1 && git push origin main 1.0.1`.
4. `.github/workflows/release.yml` runs CI's lint and end-to-end checks and, only if they pass, builds the zip, deploys it to WordPress.org SVN (trunk + tag + assets) and attaches it to a GitHub release. It needs the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets.

License: GPL-2.0-or-later.
