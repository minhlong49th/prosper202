# What's New in Prosper202

Everything that shipped after v1.9.56 — the biggest update in Prosper202 history.

---

## Multi-Touch Attribution

Stop guessing which campaigns actually drive revenue. Prosper202 now tracks every touchpoint in the customer journey and lets you see exactly how your traffic sources work together to produce conversions.

- **Four attribution models** — Last Touch, Time Decay, Position-Based, and Assisted — so you can choose the lens that matches your business.
- **Model sandbox** — Compare models side-by-side before committing. See how revenue credit shifts when you switch from last-touch to time-decay, without changing your live data.
- **Attribution dashboard** — A dedicated view with KPI cards for Revenue, Conversions, Clicks, and ROI, filterable by campaign or landing page.
- **Snapshot exports** — Export attribution data as CSV or Excel on demand, with optional webhook callbacks so your downstream systems stay in sync.
- **Anomaly detection** — Automatically flags unusual attribution patterns so you catch issues before they cost you money.

---

## Full Remote Management: REST API v3 & CLI

Manage your entire Prosper202 installation from the command line or any HTTP client — no browser required.

### REST API v3
A modern, versioned API with 16+ endpoints covering campaigns, clicks, conversions, reports, attribution, users, and system health. Includes CORS support, security headers, machine-readable error codes, and explicit version negotiation via `X-P202-API-Version`.

### `p202` CLI
A purpose-built command-line tool with 20+ commands:

- **`p202 report:summary`** — Aggregate performance totals at a glance.
- **`p202 report:breakdown`** — Slice stats by campaign, country, landing page, browser, device, ISP, and 14+ other dimensions.
- **`p202 report:timeseries`** — Hourly or daily performance trends.
- **`p202 report:daypart` / `report:weekpart`** — Find your highest-converting hours and days.
- **`p202 attribution:model`** — Full CRUD for attribution models.
- **`p202 sync:*`** — Multi-profile synchronization across installations.

Output in JSON, CSV, or human-readable tables. Manage multiple Prosper202 instances with named profiles.

---

## Real-Time Spy Mode, Reimagined

The click spy view is now incremental. Instead of reloading your entire click history every few seconds, Prosper202 streams only new clicks to the top of the table.

- **Instant updates** — New clicks appear at the top without disrupting your view.
- **Read-only replica support** — Spy queries run against a read-only database connection, keeping your primary DB free for tracking.
- **Smart client-side management** — Caps the display at 200 rows and cancels in-flight requests to prevent pile-up during high-traffic bursts.

The result: monitor your campaigns in real time without taxing your server or your browser.

---

## Centralized Dashboard

Your Prosper202 homepage now pulls in curated content — alerts, community posts, upcoming meetups, and sponsor highlights — from the Tracking202 network.

- **Local caching** — Content syncs in the background and serves from your local database, so page loads stay fast.
- **Automatic synchronization** — A cron job keeps your dashboard fresh without manual intervention.
- **Resilient fetching** — Exponential-backoff retries and graceful fallbacks mean your dashboard never breaks when the upstream API is slow.

---

## Security Hardened

Multiple layers of security improvements protect your data and your installation.

- **Modern password hashing** — Passwords are now hashed with bcrypt via PHP's `password_hash()`. Legacy MD5 hashes are transparently upgraded on next login — no password resets required.
- **Installer lockdown** — The installer is disabled after setup to prevent privilege escalation on shared hosting.
- **Secure auto-update pipeline** — Package downloads and extractions are validated to prevent tampering.
- **API input hardening** — Attribution endpoints block `user_id` override attempts from JSON payloads.

---

## PHP 8.3 Compatibility

The entire codebase has been modernized for PHP 8.0–8.3 with Rector, including strict type safety, modern syntax, and elimination of deprecation warnings. If you've been waiting to upgrade your PHP version, Prosper202 is ready.

---

## Under the Hood

- **MySQL repository layer** — Database access is now organized through a clean repository pattern with type-safe prepared statements.
- **Centralized version management** — One source of truth for version info across the app.
- **Intercom removed** — No more third-party tracking scripts on your installation.
- **Comprehensive test coverage** — PHPUnit tests for core components.
- **Modular installer** — The setup flow has been rewritten for zero-friction onboarding.
- **Nginx + Apache support** — Deploy on whichever web server you prefer.
