# Mission-Critical Functions & Files

Files and functions that **cause revenue loss, broken redirects, or lost data** when they break in production.

---

## TIER 1: IMMEDIATE REVENUE IMPACT (Breaks = Lost Money)

### 1. Click Redirect — `tracking202/redirect/dl.php`
**The single most critical file.** Every paid click flows through this endpoint. If it breaks, clicks don't redirect and ad spend is wasted with zero conversions.

**Critical path (every step must succeed):**
1. Validate `t202id` parameter → load tracker from `202_trackers`
2. Allocate click ID from `202_clicks_counter`
3. Geo/IP/ISP lookups via `MysqlLocationRepository`
4. Keyword + variable lookups via `MysqlTrackingRepository`
5. `MysqlClickRepository::recordClick()` — atomic write across **9 tables** in a transaction
6. `rotateTrackerUrl()` — select destination URL
7. `replaceTrackerPlaceholders()` — replace `[[subid]]`, `[[c1]]`, etc. in outbound URL
8. Send `Location:` redirect header
9. `DataEngine::setDirtyHour()` — mark click for reporting aggregation

**Tables written (atomically):** `202_clicks`, `202_clicks_counter`, `202_clicks_variable`, `202_google`, `202_clicks_spy`, `202_clicks_advance`, `202_clicks_tracking`, `202_clicks_record`, `202_clicks_site`

**Failure modes:**
- Redirect URL empty → visitor sees blank page, click lost
- Transaction rollback → click not recorded, revenue untrackable
- `setDirtyHour()` fails → click exists in DB but invisible in reports
- Placeholder replacement fails → affiliate network doesn't receive SubID, conversion can't be attributed

**Key dependencies:**
- `202-config/connect2.php` (DB + memcache initialization for tracking hot path)
- `202-config/Click/MysqlClickRepository.php` (atomic click recording)
- `202-config/Click/ClickRecordBuilder.php` (builds typed click value object)
- `202-config/class-dataengine-slim.php` (`setDirtyHour()`)
- `202-config/Repository/LookupRepositoryFactory.php` (geo/device/tracking repos)

---

### 2. Conversion Pixel — `tracking202/static/px.php`
Records conversions from pixel fires. If broken, conversions are silently lost — clicks happen but no revenue is attributed.

**Critical path:**
1. Read `acip` parameter (campaign ID)
2. Look up click via cookie OR IP+user-agent match within 30 days
3. Call `p202ApplyConversionUpdate()` to set `click_lead=1` and update `click_payout`
4. Update both `202_clicks` AND `202_clicks_spy` (must stay in sync)

**Tables written:** `202_clicks`, `202_clicks_spy`

**Failure modes:**
- Cookie missing + IP lookup fails → conversion lost silently
- `202_clicks` updated but `202_clicks_spy` not → reports show no conversion despite it being recorded
- `error_log()` used instead of exceptions → failures are silent

---

### 3. Encrypted Postback Handler — `tracking202/static/cb202.php`
Handles server-to-server postbacks from affiliate networks (CJ, ShareASale, etc.). **Revenue recording happens here.**

**Critical path:**
1. Decrypt postback payload (OpenSSL AES-128-CBC)
2. Parse transaction type (TEST vs SALE)
3. Extract `click_id`, `payout`, `transaction_id`
4. Call `p202ApplyConversionUpdate()` with payout override
5. Optional Slack notification

**Tables written:** `202_clicks`, `202_clicks_spy`

**Failure modes:**
- Decryption fails → entire postback lost, no revenue recorded
- Invalid `click_id` → payout attributed to nothing
- Network sends duplicate postback → double-counted revenue if no dedup

---

### 4. Conversion Helper — `202-config/static-endpoint-helpers.php`
Contains `p202ApplyConversionUpdate()` used by both `px.php` and `cb202.php`.

**What it does:**
- Sets `click_lead = 1`, `click_filtered = 0`
- Updates `click_cpc` or `click_cpa` with payout value
- Updates BOTH `202_clicks` AND `202_clicks_spy`
- Calls `DataEngine::setDirtyHour()` for reporting

**Known risk:** Uses `error_log()` on failures — does NOT throw. Revenue updates can fail silently.

---

### 5. API Conversion Endpoint — `api/V3/Controllers/ConversionsController.php`
`POST /api/v3/conversions` — external systems create conversions via API.

**Calls:** `MysqlConversionRepository::create()` which uses `FOR UPDATE` lock + transaction.

**Tables written:** `202_conversion_logs`, `202_clicks`

**Failure modes:**
- Race condition without lock → double conversion
- User ownership validation fails → conversion rejected silently

---

## TIER 2: DATA INTEGRITY (Breaks = Corrupt Reports / Stale Data)

### 6. Atomic Click Recording — `202-config/Click/MysqlClickRepository.php`
**Method:** `recordClick(ClickRecord $click): int`

Writes to 9 tables in a single transaction. If any write fails, the entire click is rolled back. This is the most complex write operation in the system.

**Risk:** Partial writes if transaction isolation is broken. Every `execute()` is checked and throws on failure.

---

### 7. Data Engine Aggregation — `202-config/class-dataengine-slim.php`
**Method:** `setDirtyHour($click_id)`

Inserts click data into `202_dataengine` for hourly report aggregation. Called after every click recording in `dl.php`.

**If broken:** Clicks exist in the database but reports show zero. Users think tracking is broken.

---

### 8. DataEngine Job Processor — `202-cronjobs/process_dataengine_job.php`
Orchestrates parallel cURL requests to `dej.php` workers for hourly aggregation windows.

**Tables:** `202_dataengine_job` (sets `processing=1` then `processed=1`)

**Known risks:**
- Unchecked `$db->query()` return values (lines 19, 29, 97)
- Silent cURL failures → jobs stuck in `processing=1` forever, blocking all future aggregation
- No rollback if batch fails midway

---

### 9. DataEngine Job Worker — `202-cronjobs/dej.php`
Individual worker that processes one hourly aggregation window.

**If broken:** Reports don't update. Revenue/cost data becomes stale.

---

### 10. Conversion Repository — `202-config/Conversion/MysqlConversionRepository.php`
**Method:** `create(int $userId, array $data): int`

Uses `FOR UPDATE` row lock + transaction to prevent race conditions on conversion recording.

**Tables:** `202_conversion_logs`, `202_clicks`

---

### 11. Landing Page Tracking — `tracking202/static/record_simple.php` & `record_adv.php`
Records landing page impressions and click-throughs. Uses the same `MysqlClickRepository::recordClick()` path as `dl.php`.

**If broken:** Landing page stats missing, split-test data corrupted, revenue misattributed.

---

### 12. Cloaked Redirect — `tracking202/redirect/cl.php`
Second-hop redirect for cloaked campaigns. Looks up `click_id_public` from `202_clicks_record`, fetches redirect URL from `202_clicks_site`.

**If broken:** Cloaked clicks never reach the offer page → zero conversions on cloaked campaigns.

---

## TIER 3: INFRASTRUCTURE (Breaks = System-Wide Failure)

### 13. Tracking Endpoint Init — `202-config/connect2.php`
Initializes DB + memcache for the redirect hot path (`dl.php`). Designed for minimal overhead. Has graceful fallback to cached redirects if DB is down.

**Critical functions:**
- `rotateTrackerUrl()` — round-robin across 5 campaign URLs
- `replaceTrackerPlaceholders()` — token replacement in outbound URLs
- `setClickIdCookie()` — 30-day tracking cookie for pixel attribution
- `getGeoData()` / `getIspData()` — MaxMind GeoIP2 lookups
- Filter class — bot detection, duplicate IP, internal IP checks

**If broken:** `dl.php` cannot initialize → all click tracking stops.

---

### 14. Main App Init — `202-config/connect.php`
Global initialization: DB singleton, sessions, timezone, IP detection, installation check.

**Initialization order (must not be reordered):**
1. `version.php` — dies if missing
2. PHP ini settings
3. `202-config.php` — DB credentials; redirects to setup if missing
4. `DB::getInstance()` → write connection
5. Read-only connection (optional, logs warning if unavailable)
6. Table installation check
7. Upgrade check

**If broken:** Entire application fails to load.

---

### 15. Database Connection Wrapper — `202-config/Database/Connection.php`
Safe wrapper around mysqli with enforced error checking.

**Critical methods:**
- `bind()` — validates type string length matches value count
- `execute()` — THROWS on failure (prevents silent failures per CLAUDE.md pattern #1)
- `transaction()` — ensures rollback on exception

**If broken:** All repository operations lose their safety guarantees.

---

### 16. Lookup Repositories
- `202-config/Repository/Mysql/MysqlLocationRepository.php` — atomic find-or-create for country, city, region, ISP, IP
- `202-config/Repository/Mysql/MysqlTrackingRepository.php` — atomic find-or-create for keywords, C1-C4, UTM values, variable sets
- `202-config/Repository/LookupRepositoryFactory.php` — factory with memcache layer

All use `INSERT...ON DUPLICATE KEY UPDATE` pattern. If broken, clicks record with `*_id = 0` → reporting filters/groups fail.

---

### 17. API Authentication — `api/V3/Auth.php`
Stateless Bearer token auth from `202_api_keys`. Loads user roles from `202_user_role` + `202_roles`.

**If broken:** All API requests rejected (401) or worse — over-authorized if scope parsing fails (defaults to `['*']`).

---

### 18. API Router — `api/v3/index.php`
HTTP request router + auth dispatcher for all REST v3 endpoints.

**If broken:** All API consumers (external integrations, postback systems) lose access.

---

## TIER 4: SCHEDULED JOBS (Breaks = Delayed / Missing Data)

| File | Purpose | Failure Impact |
|------|---------|----------------|
| `202-cronjobs/attribution-rebuild.php` | Hourly attribution recalculation | Attribution data stale |
| `202-cronjobs/attribution-export.php` | CSV/TSV export + webhooks | Exports incomplete |
| `202-cronjobs/backfill-conversion-journeys.php` | Multi-touch journey backfill | Journey records incomplete |
| `202-cronjobs/purge-disabled-journeys.php` | Clean up disabled attribution scopes | Orphaned data accumulates |
| `202-cronjobs/sync-worker.php` | External sync operations | Sync data partial |
| `202-cronjobs/sync-dashboard-data.php` | Dashboard content cache | Dashboard shows stale data |
| `202-cronjobs/daily-email.php` | Daily campaign digest email | Email not sent; has unchecked queries |
| `202-cronjobs/dni.php` | Network offer sync | Offer cache stale; has unchecked queries |
| `202-cronjobs/health.php` | Health check endpoint | Wrong cron status displayed |

---

## CRITICAL PATH SUMMARY

```
Ad Click → dl.php → connect2.php → MysqlClickRepository::recordClick()
                                  → rotateTrackerUrl()
                                  → replaceTrackerPlaceholders()
                                  → redirect (Location header)
                                  → DataEngine::setDirtyHour()

Conversion → px.php / cb202.php → p202ApplyConversionUpdate()
                                 → 202_clicks + 202_clicks_spy update
                                 → DataEngine::setDirtyHour()

API Conv  → POST /api/v3/conversions → MysqlConversionRepository::create()
                                      → 202_conversion_logs + 202_clicks

Reports   → process_dataengine_job.php → dej.php workers
                                        → DataEngine::getSummary()
```

## FILES THAT MUST NEVER BREAK (Ranked by Cost of Failure)

1. **`tracking202/redirect/dl.php`** — Every click. Every dollar of ad spend.
2. **`tracking202/static/cb202.php`** — Every server postback. Revenue recording.
3. **`tracking202/static/px.php`** — Every pixel conversion.
4. **`202-config/static-endpoint-helpers.php`** — Conversion update logic shared by #2 and #3.
5. **`202-config/Click/MysqlClickRepository.php`** — Atomic click writes (9 tables).
6. **`202-config/connect2.php`** — Init + URL rotation + placeholder replacement.
7. **`202-config/class-dataengine-slim.php`** — Reporting aggregation trigger.
8. **`202-config/Database/Connection.php`** — Safe DB operations for all repos.
9. **`202-config/connect.php`** — App-wide initialization.
10. **`tracking202/redirect/cl.php`** — Cloaked campaign redirects.
