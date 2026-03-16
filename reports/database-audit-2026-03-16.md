# Prosper202 Database & SQL Audit Report

**Date:** 2026-03-16
**Scope:** Full codebase database layer review -- schema, queries, security, transactions, performance
**Codebase:** Prosper202 PHP 8.3 affiliate tracking platform with REST API v3

---

## Executive Summary

This audit examined the entire Prosper202 database layer across 112 table definitions and 150+ SQL query sites in 175+ PHP files. The codebase exhibits a **split personality**: the V3 API layer follows modern security practices with prepared statements, checked executes, and proper transactions, while legacy code contains critical vulnerabilities including SQL injection vectors, unchecked operations, and missing error handling.

### Severity Counts

| Severity | Count | Category |
|----------|-------|----------|
| **Critical** | 14 | SQL injection vectors (string concatenation with user input) |
| **Critical** | 5 | Tables missing primary keys entirely |
| **Critical** | 50+ | Unchecked `$stmt->execute()` calls |
| **High** | 100+ | Unchecked `$db->query()` calls |
| **High** | 5+ | Unchecked transaction begin/commit |
| **High** | 8 | Tables with no indexes at all |
| **Medium** | 10+ | Unbounded SELECT queries (no LIMIT) |
| **Medium** | 3+ | N+1 query patterns |
| **Low** | 10+ | Missing `$result->close()` / resource leaks |

---

## 1. Schema Defects

### 1.1 Tables Missing Primary Keys

The following tables have **no PRIMARY KEY defined**, which prevents efficient row identification, breaks replication, and degrades InnoDB clustering:

| Table | Columns | Impact |
|-------|---------|--------|
| `202_version` | `version` (varchar(50)) | Single-row table but no PK; harmless in practice |
| `202_delayed_sqls` | `delayed_sql` (text), `delayed_time` (int) | No PK, no indexes at all. Cannot efficiently locate or deduplicate rows |
| `202_clicks` | `click_id` (bigint) | Has KEY on `click_id` but **not** PRIMARY KEY. This is a high-volume table -- lack of clustered PK degrades all access patterns |
| `202_clicks_spy` | `click_id` (bigint) | Same issue as `202_clicks` -- KEY but not PRIMARY KEY on the main identifier |
| `202_clicks_variable` | `click_id`, `variable_set_id` | No PK, only secondary KEYs. Should have composite PK `(click_id, variable_set_id)` |
| `202_last_ips` | `user_id`, `ip_id`, `time` | No PK, only composite KEY on `(user_id, ip_id)` |
| `202_charts` | `user_id`, `data`, `chart_time_range` | No PK, only KEY on `user_id` |
| `202_variable_sets` | `variable_set_id`, `variables` | No PK (AUTO_INCREMENT column but no PRIMARY KEY constraint) |
| `202_cronjobs` | `cronjob_type`, `cronjob_time` | No PK, only composite KEY |

**Schema definition files:**
- `/home/user/prosper202/202-config/Database/Tables/ClickTables.php`
- `/home/user/prosper202/202-config/Database/Tables/CoreTables.php`
- `/home/user/prosper202/202-config/Database/Tables/TrackingTables.php`
- `/home/user/prosper202/202-config/Database/Tables/MiscTables.php`

### 1.2 Tables With No Indexes At All

| Table | Purpose | Queries That Would Benefit |
|-------|---------|---------------------------|
| `202_version` | Version tracking | Rarely queried; low impact |
| `202_delayed_sqls` | Queued SQL statements | Queried by cronjob processor; needs index on `delayed_time` |
| `202_dataengine_job` | Processing queue | Queried with `WHERE processed = '0' AND processing != '1'`; needs index on `(processed, processing)` |
| `202_api_keys` | API key storage | Queried with `WHERE user_id = ?` and `WHERE api_key = ?`; needs indexes on both |
| `202_alerts` | Alert tracking | Has UNIQUE on `prosper_alert_id` but no additional indexes |

### 1.3 Missing Indexes on Hot Paths

| Table | Missing Index | Query Pattern | File |
|-------|--------------|---------------|------|
| `202_clicks` | PRIMARY KEY (`click_id`) | Every click lookup, JOIN from 5+ related tables | `ClicksController.php`, `MysqlClickRepository.php` |
| `202_clicks` | Composite on `(user_id, click_time)` | Time-range filtered click listing | `ClicksController.php:64-80` |
| `202_api_keys` | KEY (`api_key`) | API key authentication lookup | `UsersController.php:216` |
| `202_api_keys` | KEY (`user_id`) | List keys per user | `UsersController.php:216` |
| `202_dataengine_job` | KEY (`processed`, `processing`) | Cronjob queue polling | `process_dataengine_job.php:18` |
| `202_locations_country` | KEY (`country_code`) | Country lookup by code during click recording | `MysqlLocationRepository.php` |
| `202_locations_region` | KEY (`region_name`, `main_country_id`) | Region lookup during click recording | `MysqlLocationRepository.php` |
| `202_locations_isp` | KEY (`isp_name`) | ISP lookup during click recording | `MysqlLocationRepository.php` |
| `202_browsers` | KEY (`browser_name`) | Browser lookup during click recording | `MysqlLocationRepository.php` |
| `202_platforms` | KEY (`platform_name`) | Platform lookup during click recording | `MysqlLocationRepository.php` |
| `202_device_models` | KEY (`device_name`) | Device lookup during click recording | `MysqlLocationRepository.php` |
| `202_rotator_rules` | KEY (`rotator_id`) | Rule lookup for rotator display | `MysqlRotatorRepository.php:50`, `rotator.php:187` |
| `202_rotator_rules_criteria` | KEY (`rule_id`) | Criteria lookup per rule | `MysqlRotatorRepository.php:71` |
| `202_rotator_rules_redirects` | KEY (`rule_id`) | Redirect lookup per rule | `MysqlRotatorRepository.php:81` |
| `202_conversion_logs` | Composite on `(user_id, deleted, conv_time)` | Conversion listing with time-range filter | `ConversionsController.php:44-57` |
| `202_clicks_tracking` | Already has PK on `click_id` but no index on `c1_id`..`c4_id` | JOINs from data engine queries | `class-dataengine.php` |

### 1.4 Schema Observations

- **Total tables**: 112 definitions (68 registered in TableRegistry, rest in legacy/migration code)
- **Engine**: InnoDB throughout (except `202_rotations` which uses MEMORY)
- **Charset**: utf8mb4 / utf8mb4_general_ci (except Bot202 Facebook Pixel tables using utf8mb4_bin)
- **Largest tables by column count**: `202_dataengine` (43 columns), `202_dirty_hours` (33 columns)
- **Foreign keys**: Only present on `202_role_permission` and `202_user_role`; all other relationships are implicit

---

## 2. SQL Injection Vulnerabilities

### 2.1 Critical: Direct User Input in SQL via String Concatenation

Every instance below builds SQL using string concatenation with variables derived from user input (`$mysql[]` superglobal, `$_GET`, `$_POST`, or function parameters). Even where `real_escape_string()` is applied, the pattern is fragile and error-prone.

#### File: `/home/user/prosper202/202-pass-reset.php`

**Line 13** -- SELECT with concatenated user input:
```php
$user_sql = "SELECT * FROM 202_users WHERE user_pass_key='" . $mysql['user_pass_key'] . "'";
```

**Lines 62-64** -- UPDATE with concatenated user input:
```php
$user_sql = "UPDATE 202_users SET user_pass='" . $mysql['user_pass'] . "', user_pass_time='0' WHERE user_id='" . $mysql['user_id'] . "'";
```

#### File: `/home/user/prosper202/202-lost-pass.php`

**Line 15** -- SELECT with concatenated user input:
```php
$user_sql = "SELECT user_id FROM 202_users WHERE user_name='" . $mysql['user_name'] . "' AND user_email='" . $mysql['user_email'] . "'";
```

**Lines 37-40** -- UPDATE with concatenated user input.

#### File: `/home/user/prosper202/api-key-required.php`

**Line 45** -- UPDATE with concatenated input:
```php
$db->query("UPDATE 202_users SET p202_customer_api_key = '".$mysql['p202_customer_api_key']."' WHERE user_id = '".$mysql['user_id']."'");
```

#### File: `/home/user/prosper202/202-cronjobs/index.php`

**Line 119** -- SELECT with concatenation:
```php
$check_sql = "SELECT * FROM 202_cronjobs WHERE cronjob_type='" . $mysql['cronjob_type'] . "' AND cronjob_time='" . $mysql['cronjob_time'] . "'";
```

**Line 134** -- INSERT with concatenation.

**Line 143** -- DELETE with bare variable (no escaping at all):
```php
$click_sql = "DELETE FROM 202_clicks_spy WHERE click_time < $from";
```

**Line 147** -- DELETE with bare variable:
```php
$last_ip_sql = "DELETE FROM 202_last_ips WHERE time < $from";
```

#### File: `/home/user/prosper202/202-cronjobs/process_dataengine_job.php`

**Line 28** -- UPDATE with concatenation:
```php
$sql = "UPDATE 202_dataengine_job SET processing = '1' WHERE time_from ='" . $mysql['click_time_from'] . "' AND time_to = '" . $mysql['click_time_to'] . "'";
```

**Line 96** -- Same pattern.

#### File: `/home/user/prosper202/tracking202/ajax/generate_tracking_link.php`

**Line 74** -- SELECT with concatenated variable:
```php
$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$input_landing_page_id."'";
```

**Lines 101-127** -- Multiple concatenated SELECT with JOINs.

#### File: `/home/user/prosper202/api/v1/functions.php`

**Lines 128, 139, 173, 179, 191, 202, 236, 242, 253** -- Multiple instances of unescaped string concatenation in SQL queries throughout the V1 API.

#### File: `/home/user/prosper202/202-account/user-management.php`

**Line 24**:
```php
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
```

**Line 228**:
```php
$user_sql_edit = "SELECT user_fname,user_lname,user_name,user_id,user_email,user_time_register,user_active,role_id FROM 202_users LEFT JOIN 202_user_role USING (user_id) WHERE user_id=" . $mysql['user_id'];
```

### 2.2 Medium: Column Name Injection

#### File: `/home/user/prosper202/202-account/api-integrations.php`

**Line 52** -- Column name interpolated from variable:
```php
$sql = "UPDATE `202_users_pref` SET `{$field_name}` = '{$escaped_value}' WHERE `user_id` = '{$escaped_user_id}'";
```
While values are escaped, `$field_name` is interpolated directly, allowing column injection if the field name comes from user input.

### 2.3 Injection Vulnerability Summary

| Severity | File | Lines | Type |
|----------|------|-------|------|
| CRITICAL | `202-pass-reset.php` | 13, 62-64 | Auth-adjacent concatenation |
| CRITICAL | `202-lost-pass.php` | 15, 37-40 | Auth-adjacent concatenation |
| CRITICAL | `api-key-required.php` | 45 | API key UPDATE with concat |
| CRITICAL | `202-cronjobs/index.php` | 119, 134, 143, 147 | Bare variables in DELETE |
| CRITICAL | `202-cronjobs/process_dataengine_job.php` | 28, 96 | UPDATE with concat |
| CRITICAL | `tracking202/ajax/generate_tracking_link.php` | 74, 101-127 | SELECT with concat |
| CRITICAL | `api/v1/functions.php` | 128-253 (9 sites) | Multiple concat patterns |
| CRITICAL | `202-account/user-management.php` | 24, 228, 266 | SELECT/UPDATE with concat |
| MEDIUM | `202-account/api-integrations.php` | 52, 127 | Column name interpolation |

**Total: ~30 SQL injection sites across 9 files.**

---

## 3. Unchecked Execute/Query Calls

### 3.1 Unchecked `$stmt->execute()` Calls

Every `execute()` can return `false` without throwing. Unchecked calls mean the code proceeds as if the operation succeeded.

#### Inside Transactions (HIGH risk -- partial commits)

| File | Lines | Count | Context |
|------|-------|-------|---------|
| `/home/user/prosper202/202-config/DashboardDataManager.class.php` | 120, 198, 233, 247, 260, 284 | 5 of 6 | Only line 158 is checked |
| `/home/user/prosper202/202-config/Attribution/AttributionIntegrationService.php` | 85, 105, 124, 160 | 4 | Attribution data writes |
| `/home/user/prosper202/202-cronjobs/attribution-rebuild.php` | 53, 65, 72 | 3 | Attribution rebuild in batch |
| `/home/user/prosper202/202-cronjobs/backfill-conversion-journeys.php` | 29, 106 | 2 | Conversion backfill |

#### Standalone (MEDIUM risk -- silent failures)

| File | Lines | Count | Context |
|------|-------|-------|---------|
| `/home/user/prosper202/202-login.php` | 122, 141 | 2 | Login logging, user update |
| `/home/user/prosper202/202-Mobile/202-login.php` | 55, 69 | 2 | Mobile login logging |
| `/home/user/prosper202/api/v2/app.php` | 167 | 1 | V2 API click recording |
| `/home/user/prosper202/202-account/account.php` | 511, 531 | 2 | Password verification/update |

**Total: 50+ unchecked execute() calls across legacy code.**

### 3.2 Unchecked `$db->query()` Calls

| File | Lines | Count | Risk |
|------|-------|-------|------|
| `/home/user/prosper202/202-cronjobs/index.php` | 120, 135, 144, 147, 154, 158 | 6 | Unchecked deletions and REPLACE |
| `/home/user/prosper202/202-cronjobs/process_dataengine_job.php` | 29, 96 | 2 | Unchecked state flag changes (duplicate execution risk) |
| `/home/user/prosper202/202-config/class-dataengine.php` | 2393, 2409, 2425, 2445 | 4 | Multiple unchecked UPDATE/DELETE |
| `/home/user/prosper202/202-config/connect2.php` | 2652, 2986, 3060, 3076, 3103, 3137 | 6 | Various unchecked queries |
| `/home/user/prosper202/202-config/sessions.php` | 49, 64, 73, 81 | 4 | Session read/write/delete/gc -- silent data loss |
| `/home/user/prosper202/202-account/api-integrations.php` | 53, 128, 143, 159, 264, 267, 279, 281, 298, 300, 308, 316 | 12 | API integration CRUD |
| `/home/user/prosper202/202-config/functions-upgrade.php` | multiple | 10+ | Schema migration queries |

**Total: 100+ unchecked query() calls.**

### 3.3 Checked Calls (Good Patterns)

Some files do check return values correctly:

- `/home/user/prosper202/202-config/static-endpoint-helpers.php:77,86` -- `if (!$db->query($updateClicksSql))`
- `/home/user/prosper202/tracking202/visitors/download/index.php:66` -- `$db->query($click_sql) or record_mysql_error($click_sql)`
- `/home/user/prosper202/202-config/DashboardDataManager.class.php:158` -- `if (!$stmt->execute()) { throw new Exception(...); }`

---

## 4. Transaction Safety Issues

### 4.1 Unchecked `begin_transaction()` and `commit()`

#### File: `/home/user/prosper202/tracking202/ajax/generate_tracking_link.php`

- **Line 138**: `$db->begin_transaction();` -- NOT CHECKED for failure
- **Lines 151-156**: Query failure returns false, but rollback is conditional on `$tracker_result === false`
- **Line 180**: `$db->commit();` -- NOT CHECKED
- **Risk**: If `commit()` fails, operation appears successful but data is inconsistent

#### File: `/home/user/prosper202/202-config/DashboardDataManager.class.php`

- **Line 110**: `self::$db->begin_transaction();` -- NOT CHECKED
- **Line 158**: `if (!$stmt->execute())` -- Good, checked
- **Line 164**: `self::$db->commit();` -- NOT CHECKED
- **Risk**: If `begin_transaction()` fails, all operations run outside a transaction (auto-commit); partial failures go unnoticed

### 4.2 Partial Commit Risks

In files with multiple unchecked `execute()` calls inside transactions, a failure mid-sequence means:
1. Some operations succeed, some fail
2. The transaction commits (since the failure was not detected)
3. Database is left in an inconsistent state

**Affected files:**
- `/home/user/prosper202/202-config/Attribution/AttributionIntegrationService.php` -- 4 unchecked executes in attribution workflow
- `/home/user/prosper202/202-cronjobs/attribution-rebuild.php` -- 3 unchecked executes in rebuild loop
- `/home/user/prosper202/202-config/DashboardDataManager.class.php` -- 5 of 6 executes unchecked

### 4.3 Good Transaction Pattern (V3 API)

The V3 API layer and the `Connection` class implement proper transaction handling:

**File: `/home/user/prosper202/202-config/Database/Connection.php:230-258`**
- Checked `begin_transaction()` with error handling
- Checked `commit()` with rollback on failure
- Exception-based rollback in catch block
- This is the correct pattern to replicate across the codebase

---

## 5. Query Performance Issues

### 5.1 N+1 Query Patterns

#### File: `/home/user/prosper202/tracking202/setup/rotator.php:187-198`

```php
while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
    // ...
    $rule_sql = "SELECT * FROM `202_rotator_rules` WHERE `rotator_id`='" . $row['id'] . "'";
    $rule_result = $db->query($rule_sql);
    // ... more queries inside
}
```

**Impact**: For N rotators, executes 1 + N queries. Also contains SQL injection (concatenated `$row['id']`).

**Contrast with good pattern in V3** (`/home/user/prosper202/api/V3/Controllers/RotatorsController.php:54-88`):
```php
$placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
// Uses IN($placeholders) -- batch load instead of N+1
```

### 5.2 Unbounded SELECT Queries (No LIMIT)

| File | Line | Query | Risk |
|------|------|-------|------|
| `202-account/administration.php` | 63 | `SELECT * FROM 202_cronjob_logs` | Full table scan, unbounded |
| `202-account/administration.php` | 24 | `SELECT last_execution_time FROM 202_cronjob_logs` | Unbounded |
| `202-cronjobs/process_dataengine_job.php` | 18 | `SELECT * FROM 202_dataengine_job WHERE processed = '0' AND processing != '1'` | Could load thousands of pending jobs |
| `202-config/class-dataengine.php` | 1713 | `SELECT user_account_currency FROM 202_users_pref WHERE user_id = '...'` | Missing LIMIT 1 (user_id is unique, but should be explicit) |
| `202-config/functions-upgrade.php` | multiple | `SELECT * FROM 202_rotator_rules`, `SELECT * FROM 202_cronjob_logs`, `SELECT * FROM 202_variable_sets` | Full table loads during migration |
| `tracking202/setup/rotator.php` | 181 | `SELECT * FROM 202_rotators WHERE user_id='...' ORDER BY name ASC` | No LIMIT; loads all user rotators |
| `202-config/class-dataengine.php` | 2066, 2208, 2223, 2393, 2409, 2425 | Multiple `SELECT *` without LIMIT | Various data engine queries |

### 5.3 Full Table Scans Due to Missing Indexes

| Query Pattern | Table | Missing Index |
|---------------|-------|---------------|
| `WHERE processed = '0' AND processing != '1'` | `202_dataengine_job` | No indexes at all |
| `WHERE api_key = ?` | `202_api_keys` | No indexes at all |
| `WHERE browser_name = ?` | `202_browsers` | Only PK on `browser_id` |
| `WHERE platform_name = ?` | `202_platforms` | Only PK on `platform_id` |
| `WHERE device_name = ?` | `202_device_models` | Only PK on `device_id` |
| `WHERE country_code = ?` | `202_locations_country` | Only PK on `country_id` |
| `WHERE isp_name = ?` | `202_locations_isp` | Only PK on `isp_id` |
| `WHERE rotator_id = ?` | `202_rotator_rules` | Only PK on `id` |
| `WHERE rule_id IN (...)` | `202_rotator_rules_criteria` | Only PK on `id` |
| `WHERE rule_id IN (...)` | `202_rotator_rules_redirects` | Only PK on `id` |

These lookups occur on every click recording (location/browser/platform) and on every rotator load, making them hot paths.

### 5.4 Expensive JOINs

The click detail query in `/home/user/prosper202/api/V3/Controllers/ClicksController.php:106-127` performs **8 LEFT JOINs** in a single query:

```sql
FROM 202_clicks c
LEFT JOIN 202_clicks_record cr ON c.click_id = cr.click_id
LEFT JOIN 202_clicks_advance ca ON c.click_id = ca.click_id
LEFT JOIN 202_clicks_tracking ct ON c.click_id = ct.click_id
LEFT JOIN 202_locations_country lc ON ca.country_id = lc.country_id
LEFT JOIN 202_locations_region lr ON ca.region_id = lr.region_id
LEFT JOIN 202_locations_city lci ON ca.city_id = lci.city_id
LEFT JOIN 202_locations_isp li ON ca.isp_id = li.isp_id
LEFT JOIN 202_platforms p ON ca.platform_id = p.platform_id
LEFT JOIN 202_browsers b ON ca.browser_id = b.browser_id
```

This is acceptable for single-click lookups (`WHERE c.click_id = ? LIMIT 1`) but the list query at lines 64-80 performs 5 LEFT JOINs with `ORDER BY c.click_time DESC LIMIT ? OFFSET ?`, which can be expensive without proper covering indexes.

---

## 6. Positive Findings

### 6.1 V3 API Layer -- Strong Patterns

The V3 API controllers and supporting repositories consistently demonstrate good practices:

- **100% prepared statements** in all V3 controllers (`UsersController.php`, `ClicksController.php`, `ConversionsController.php`, `ReportsController.php`, `TrackersController.php`, `RotatorsController.php`)
- **Parameterized dynamic queries** in `MysqlCrudRepository.php` -- builds SQL dynamically but always uses `?` placeholders and `bind_param()`
- **Proper batch loading** in `RotatorsController.php:54-88` -- uses `IN()` with dynamic placeholders instead of N+1 loops
- **Transaction wrapper** in `Connection.php:230-258` with checked begin/commit/rollback
- **Soft delete pattern** consistently used (`deleted = 1` / `*_deleted = 1`) instead of hard deletes
- **User scoping** -- all queries include `WHERE user_id = ?` to prevent cross-user data access
- **LIMIT/OFFSET pagination** in all list endpoints

### 6.2 PHPStan Enforcement

- `/home/user/prosper202/202-config/PHPStan/Rules/ForbidDirectMysqliStmtCallRule.php` exists and forbids direct `$stmt->execute()` / `$stmt->bind_param()` in favor of `Connection::execute()` and `Connection::bind()`
- **Limitation**: Only enforced in V3 API layer; legacy code is not covered

### 6.3 Schema Design Strengths

- Well-indexed click tables with composite indexes for common query patterns (`overview_index`, `overview_index2`)
- Attribution system has proper UNIQUE constraints and composite indexes for scope queries
- Sync system has proper UUID uniqueness, status-based indexes, and foreign key-like structure
- `202_dataengine` has a comprehensive composite index (`dataenginejob`) covering 15 columns for the data engine rebuild query

### 6.4 Type String Accuracy

`bind_param()` type strings are generally correct across the codebase. No significant mismatches were found. Dynamic type string construction in `api/v2/app.php:166` is acceptable.

---

## 7. Prioritized Remediation Plan

### CRITICAL -- Fix Immediately

#### C1. SQL Injection: Convert all string-concatenated queries to prepared statements

**Files requiring immediate attention (14 injection sites):**

| Priority | File | Est. Effort |
|----------|------|-------------|
| 1 | `202-pass-reset.php` | 30 min |
| 2 | `202-lost-pass.php` | 30 min |
| 3 | `api-key-required.php` | 15 min |
| 4 | `202-cronjobs/index.php` | 1 hr |
| 5 | `202-cronjobs/process_dataengine_job.php` | 30 min |
| 6 | `tracking202/ajax/generate_tracking_link.php` | 2 hr |
| 7 | `api/v1/functions.php` | 2 hr |
| 8 | `202-account/user-management.php` | 1 hr |
| 9 | `202-account/api-integrations.php` | 1 hr |

**Action**: Replace every `$db->query("..." . $var . "...")` with `$db->prepare("... ? ...")` + `bind_param()`. Use `Connection::execute()` wrapper where possible.

#### C2. Add Primary Keys to high-volume tables

| Table | Recommended PK |
|-------|---------------|
| `202_clicks` | `PRIMARY KEY (click_id)` (promote existing KEY) |
| `202_clicks_spy` | `PRIMARY KEY (click_id)` (promote existing KEY) |
| `202_clicks_variable` | `PRIMARY KEY (click_id, variable_set_id)` |
| `202_cronjobs` | `PRIMARY KEY (cronjob_type, cronjob_time)` or add `id` AUTO_INCREMENT |
| `202_delayed_sqls` | Add `id` INT AUTO_INCREMENT PRIMARY KEY |

### HIGH -- Fix Soon

#### H1. Check all `execute()` return values inside transactions

**Files requiring attention:**

1. `/home/user/prosper202/202-config/DashboardDataManager.class.php` -- 5 unchecked executes
2. `/home/user/prosper202/202-config/Attribution/AttributionIntegrationService.php` -- 4 unchecked executes
3. `/home/user/prosper202/202-cronjobs/attribution-rebuild.php` -- 3 unchecked executes
4. `/home/user/prosper202/202-cronjobs/backfill-conversion-journeys.php` -- 2 unchecked executes

**Pattern to apply:**
```php
if (!$stmt->execute()) {
    $stmt->close();
    throw new \RuntimeException('Execute failed: ' . $stmt->error);
}
```

#### H2. Check `begin_transaction()` and `commit()` return values

**Files:**
- `tracking202/ajax/generate_tracking_link.php:138,180`
- `202-config/DashboardDataManager.class.php:110,164`

#### H3. Fix N+1 query in rotator setup

**File:** `tracking202/setup/rotator.php:187-198`
**Action:** Collect all rotator IDs, then batch-load rules with `WHERE rotator_id IN (?, ?, ...)` (following the pattern in `RotatorsController.php:54-88`).

### MEDIUM -- Fix in Next Sprint

#### M1. Add missing indexes on lookup tables

```sql
-- Location lookups (hot path: every click recording)
ALTER TABLE 202_browsers ADD KEY browser_name (browser_name);
ALTER TABLE 202_platforms ADD KEY platform_name (platform_name);
ALTER TABLE 202_device_models ADD KEY device_name (device_name);
ALTER TABLE 202_locations_country ADD KEY country_code (country_code);
ALTER TABLE 202_locations_isp ADD KEY isp_name (isp_name);

-- Rotator lookups
ALTER TABLE 202_rotator_rules ADD KEY rotator_id (rotator_id);
ALTER TABLE 202_rotator_rules_criteria ADD KEY rule_id (rule_id);
ALTER TABLE 202_rotator_rules_redirects ADD KEY rule_id (rule_id);

-- API keys
ALTER TABLE 202_api_keys ADD KEY user_id (user_id);
ALTER TABLE 202_api_keys ADD KEY api_key (api_key(191));

-- Processing queue
ALTER TABLE 202_dataengine_job ADD KEY processing_status (processed, processing);
```

#### M2. Add LIMIT to unbounded queries

**Files to update:**
- `202-account/administration.php:24,63`
- `202-cronjobs/process_dataengine_job.php:18`
- `202-config/class-dataengine.php:1713,2066,2208,2223`
- `tracking202/setup/rotator.php:181`

#### M3. Check all standalone `execute()` return values

**Files:**
- `202-login.php:122,141`
- `202-Mobile/202-login.php:55,69`
- `api/v2/app.php:167`
- `202-account/account.php:511,531`

#### M4. Check all `$db->query()` return values

Focus on write operations first (UPDATE, DELETE, INSERT), then reads. Priority files:
- `202-config/sessions.php:49,64,73,81` (session data loss)
- `202-cronjobs/process_dataengine_job.php:29,96` (duplicate execution risk)
- `202-cronjobs/index.php:120-158` (unchecked deletions)

### LOW -- Code Quality

#### L1. Extend PHPStan rule enforcement to entire codebase

The `ForbidDirectMysqliStmtCallRule` currently only covers V3 API. Extend it to all PHP files to catch direct `execute()` calls.

#### L2. Add `$result->close()` / `$stmt->close()` consistently

**Files with resource leaks:**
- `202-config/sessions.php:49,64,73,81`
- Legacy query code throughout `connect2.php`, `class-dataengine.php`

#### L3. Standardize on `Connection` class for all database access

Migrate legacy `$db->query()` and `$db->prepare()` calls to use the `Connection` wrapper class, which provides:
- Checked execute
- Checked bind
- Automatic resource cleanup
- Transaction safety

#### L4. Add composite index for click listing performance

```sql
ALTER TABLE 202_clicks ADD KEY user_time_filtered (user_id, click_filtered, click_time);
```

This would benefit the paginated click listing query in `ClicksController.php:64-80`.

---

## Appendix A: Schema Definition File Locations

| File | Tables Defined |
|------|---------------|
| `/home/user/prosper202/202-config/Database/Tables/CoreTables.php` | version, sessions, cronjobs, cronjob_logs, mysql_errors, delayed_sqls, alerts, offers, filters, user_data_feedback |
| `/home/user/prosper202/202-config/Database/Tables/UserTables.php` | users, users_pref, users_log, roles, permissions, role_permission, user_role, api_keys, auth_keys |
| `/home/user/prosper202/202-config/Database/Tables/ClickTables.php` | clicks, clicks_advance, clicks_counter, clicks_record, clicks_site, clicks_spy, clicks_tracking, clicks_variable, clicks_rotator, clicks_total |
| `/home/user/prosper202/202-config/Database/Tables/TrackingTables.php` | tracking_c1-c4, trackers, cpa_trackers, keywords, google, bing, facebook, utm_*, custom_variables, ppc_network_variables, variable_sets* |
| `/home/user/prosper202/202-config/Database/Tables/CampaignTables.php` | aff_campaigns, aff_networks, ppc_accounts, ppc_networks, ppc_account_pixels, landing_pages, text_ads |
| `/home/user/prosper202/202-config/Database/Tables/AttributionTables.php` | attribution_models, _snapshots, _touchpoints, _settings, _audit, _exports, conversion_logs, conversion_touchpoints |
| `/home/user/prosper202/202-config/Database/Tables/RotatorTables.php` | rotators, rotator_rules, rotator_rules_criteria, rotator_rules_redirects, rotations |
| `/home/user/prosper202/202-config/Database/Tables/AdNetworkTables.php` | ad_network_feeds, _ads, _titles, _bodies, ad_feed_*_tokens |
| `/home/user/prosper202/202-config/Database/Tables/MiscTables.php` | ips, ips_v6, last_ips, locations_*, browsers, platforms, device_*, pixel_types, site_domains, site_urls, dataengine, dataengine_job, dirty_hours, sort_breakdowns, charts, export_*, dni_networks, bot202_facebook_pixel_* |
| `/home/user/prosper202/202-config/Database/Tables/SyncTables.php` | sync_jobs, sync_job_events, sync_job_items, change_log, deleted_log, sync_audit |
| `/home/user/prosper202/202-config/Database/Schema/TableRegistry.php` | Central registry (68 registered tables) |

## Appendix B: Files with SQL Queries (Vulnerable Files Highlighted)

**Vulnerable (string concatenation):**
- `/home/user/prosper202/202-pass-reset.php`
- `/home/user/prosper202/202-lost-pass.php`
- `/home/user/prosper202/api-key-required.php`
- `/home/user/prosper202/202-cronjobs/index.php`
- `/home/user/prosper202/202-cronjobs/process_dataengine_job.php`
- `/home/user/prosper202/tracking202/ajax/generate_tracking_link.php`
- `/home/user/prosper202/tracking202/setup/rotator.php`
- `/home/user/prosper202/api/v1/functions.php`
- `/home/user/prosper202/202-account/user-management.php`
- `/home/user/prosper202/202-account/api-integrations.php`
- `/home/user/prosper202/202-config/class-dataengine.php`
- `/home/user/prosper202/202-config/connect2.php`
- `/home/user/prosper202/202-config/sessions.php`

**Safe (prepared statements):**
- `/home/user/prosper202/api/V3/Controllers/UsersController.php`
- `/home/user/prosper202/api/V3/Controllers/ClicksController.php`
- `/home/user/prosper202/api/V3/Controllers/ConversionsController.php`
- `/home/user/prosper202/api/V3/Controllers/ReportsController.php`
- `/home/user/prosper202/api/V3/Controllers/TrackersController.php`
- `/home/user/prosper202/api/V3/Controllers/RotatorsController.php`
- `/home/user/prosper202/202-config/Attribution/Repository/Mysql/MysqlModelRepository.php`
- `/home/user/prosper202/202-config/Click/MysqlClickRepository.php`
- `/home/user/prosper202/202-config/Crud/MysqlCrudRepository.php`
- `/home/user/prosper202/202-config/Rotator/MysqlRotatorRepository.php`
- `/home/user/prosper202/202-config/Repository/Mysql/MysqlLocationRepository.php`
- `/home/user/prosper202/202-config/Database/Connection.php`
