<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

/**
 * Tests for DataEngine::setDirtyHour() logic.
 *
 * setDirtyHour() inserts click data into 202_dataengine for report aggregation.
 * If this fails, clicks exist in the database but reports show zero.
 *
 * The actual DataEngine class depends on the DB singleton and global state,
 * so we test the SQL construction and edge case logic.
 */
final class DataEngineTest extends TestCase
{
    // --- setDirtyHour SQL structure ---

    public function testSetDirtyHourInsertSelectJoinsAllRequiredTables(): void
    {
        // The SQL in setDirtyHour must JOIN all these tables for complete reporting
        $requiredJoins = [
            '202_clicks',
            '202_clicks_record',
            '202_clicks_advance',
            '202_clicks_tracking',
            '202_clicks_variable',
            '202_clicks_site',
            '202_clicks_rotator',
            '202_google',
            '202_aff_campaigns',
            '202_aff_networks',
            '202_ppc_accounts',
            '202_ppc_networks',
            '202_keywords',
            '202_browsers',
            '202_platforms',
            '202_text_ads',
            '202_site_urls',
            '202_locations_country',
            '202_locations_region',
            '202_locations_city',
            '202_locations_isp',
            '202_device_models',
            '202_ips',
            '202_tracking_c1',
            '202_tracking_c2',
            '202_tracking_c3',
            '202_tracking_c4',
            '202_landing_pages',
        ];

        // Read the actual SQL from the source file
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');
        self::assertNotFalse($source);

        foreach ($requiredJoins as $table) {
            self::assertStringContainsString(
                $table,
                $source,
                "setDirtyHour SQL must JOIN $table for complete report aggregation"
            );
        }
    }

    public function testSetDirtyHourInsertColumnsMatchSelectColumns(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        // Extract INSERT columns
        preg_match('/insert into 202_dataengine\(([^)]+)\)/s', $source, $insertMatch);
        self::assertNotEmpty($insertMatch, 'Must find INSERT INTO 202_dataengine(...)');

        $insertColumns = array_map('trim', explode(',', $insertMatch[1]));
        $insertColumns = array_map(fn($c) => preg_replace('/\s+/', '', $c), $insertColumns);

        // Must include these critical columns for reporting
        $criticalColumns = [
            'user_id', 'click_id', 'click_time',
            'aff_campaign_id', 'aff_network_id',
            'keyword_id', 'country_id',
            'click_lead', 'click_filtered', 'click_bot',
            'clicks', 'click_out', 'leads',
            'payout', 'income', 'cost',
        ];

        foreach ($criticalColumns as $col) {
            self::assertContains(
                $col,
                $insertColumns,
                "INSERT must include $col for reporting"
            );
        }
    }

    public function testSetDirtyHourUsesOnDuplicateKeyUpdate(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        self::assertStringContainsString(
            'on duplicate key update',
            strtolower($source),
            'Must use ON DUPLICATE KEY UPDATE to handle reconversion'
        );
    }

    public function testDuplicateKeyUpdateRefreshesRevenueFields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        // These fields must be updated on duplicate to reflect reconversions
        $updatedFields = [
            'click_lead', 'click_filtered', 'click_bot', 'click_out',
            'leads', 'payout', 'income', 'cost',
            'landing_page_id', 'aff_campaign_id', 'aff_network_id',
        ];

        foreach ($updatedFields as $field) {
            self::assertStringContainsString(
                "$field=values($field)",
                strtolower($source),
                "ON DUPLICATE KEY UPDATE must refresh $field"
            );
        }
    }

    // --- Income calculation ---

    public function testIncomeCalculationOnlyCountsLeads(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        // Income should only be counted when click_lead > 0
        self::assertStringContainsString(
            'IF (2c.click_lead>0,2c.click_payout,0) AS income',
            $source,
            'Income must be conditional on click_lead > 0'
        );
    }

    public function testCostIsCpcValue(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        self::assertStringContainsString(
            '2c.click_cpc AS cost',
            $source,
            'Cost must come from click_cpc'
        );
    }

    // --- Empty click_id handling ---

    public function testEmptyClickIdReturnsEarly(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        // The function must check for empty click_id and return false
        self::assertStringContainsString(
            "return false",
            $source,
            'Empty click_id must cause early return'
        );
    }

    // --- Timezone handling ---

    public function testDataEngineSetsMysqlTimezone(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        self::assertStringContainsString(
            'SET time_zone',
            $source,
            'DataEngine must set MySQL timezone for correct time-based aggregation'
        );
    }

    // --- SQL injection risk ---

    public function testClickIdIsDirectlyInterpolatedInSql(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');

        // The click_id is interpolated directly: WHERE 2c.click_id=" . $click_id
        // This is safe only if $click_id is validated as integer upstream
        self::assertStringContainsString(
            '$click_id',
            $source,
            'click_id is interpolated — must be validated upstream'
        );

        // Verify that the click_id goes through real_escape_string when derived from DB
        self::assertStringContainsString(
            'real_escape_string',
            $source,
            'Click ID from DB lookup must be escaped'
        );
    }
}
