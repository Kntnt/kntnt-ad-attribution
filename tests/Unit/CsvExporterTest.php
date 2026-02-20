<?php
/**
 * Unit tests for Csv_Exporter.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Csv_Exporter;
use Kntnt\Ad_Attribution\Plugin;
use Brain\Monkey\Functions;

/**
 * Exception to catch exit() calls inside export().
 */
class CsvExitException extends \RuntimeException {}

// ─── export() ───

describe('Csv_Exporter::export()', function () {

    /**
     * Returns a default per-click item object with sensible defaults.
     *
     * @param array $overrides Properties to override.
     *
     * @return object
     */
    function make_click(array $overrides = []): object {
        return (object) array_merge([
            'hash'                  => 'abc123',
            'target_post_id'        => 1,
            'utm_source'            => 'google',
            'utm_medium'            => 'cpc',
            'utm_campaign'          => 'summer',
            'utm_content'           => '',
            'utm_term'              => '',
            'utm_id'                => '',
            'utm_source_platform'   => '',
            'clicked_at'            => '2024-06-15 10:30:00',
            'fractional_conversion' => null,
            'converted_at'          => null,
        ], $overrides);
    }

    /**
     * Strips BOM, parses CSV output, and returns associative rows keyed by header names.
     *
     * @param string $output    Raw CSV output (may include BOM).
     * @param string $delimiter CSV field delimiter.
     *
     * @return array<int, array<string, string>>
     */
    function parse_csv_rows(string $output, string $delimiter = ','): array {
        $clean  = ltrim($output, "\xEF\xBB\xBF");
        $lines  = array_filter(explode("\n", $clean), fn ($l) => $l !== '');
        $header = str_getcsv(array_shift($lines), $delimiter);

        return array_map(
            fn ($line) => array_combine($header, str_getcsv($line, $delimiter)),
            $lines,
        );
    }

    /**
     * Runs export() in an output buffer, capturing headers and CSV output.
     * Uses Patchwork to intercept exit() and header().
     *
     * @param array  $items      Data rows.
     * @param string $date_start Start date.
     * @param string $date_end   End date.
     * @param string $locale_decimal Decimal point character for number_format_i18n.
     *
     * @return array{headers: string[], output: string}
     */
    function run_export(
        array $items,
        string $date_start = '1970-01-01',
        string $date_end = '9999-12-31',
        string $locale_decimal = '.',
    ): array {
        $captured_headers = [];

        // Capture header() calls.
        Functions\when('header')->alias(function (string $header) use (&$captured_headers) {
            $captured_headers[] = $header;
        });

        // Locale-aware number formatting.
        Functions\when('number_format_i18n')->alias(function (float $number, int $decimals = 0) use ($locale_decimal) {
            return number_format($number, $decimals, $locale_decimal, '');
        });

        Functions\when('sanitize_file_name')->returnArg(1);
        Functions\when('gmdate')->justReturn('2024-01-01');
        Functions\when('home_url')->alias(fn ($path) => 'https://example.com/' . ltrim($path, '/'));
        Functions\when('get_permalink')->alias(fn (int $id) => $id > 0 ? "https://example.com/page/{$id}" : false);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_url_prefix',
            fn () => 'ad',
        );

        $exporter = new Csv_Exporter();

        ob_start();
        try {
            $exporter->export($items, $date_start, $date_end);
        } catch (CsvExitException) {
            // Expected — exit was intercepted.
        }
        $output = ob_get_clean();

        return ['headers' => $captured_headers, 'output' => $output];
    }

    beforeEach(function () {
        // Intercept exit() so the test process doesn't terminate.
        \Patchwork\redefine('exit', function () {
            throw new CsvExitException('exit intercepted');
        });
    });

    it('sets correct Content-Type header', function () {
        $result = run_export([]);

        $content_types = array_filter(
            $result['headers'],
            fn ($h) => str_starts_with($h, 'Content-Type:'),
        );

        expect(array_values($content_types)[0])->toContain('text/csv; charset=UTF-8');
    });

    it('includes UTF-8 BOM', function () {
        $result = run_export([]);

        expect(str_starts_with($result['output'], "\xEF\xBB\xBF"))->toBeTrue();
    });

    it('uses semicolon delimiter for European locales', function () {
        $item = make_click(['fractional_conversion' => 1.0, 'converted_at' => '2024-06-15 11:00:00']);

        $result = run_export([$item], locale_decimal: ',');

        // With semicolon delimiter, fields should be separated by ;
        $lines = explode("\n", trim($result['output']));
        expect(count($lines))->toBeGreaterThanOrEqual(2);

        // Header row uses semicolons.
        expect($lines[0])->toContain(';');
    });

    it('uses comma delimiter for other locales', function () {
        $item = make_click(['fractional_conversion' => 1.0, 'converted_at' => '2024-06-15 11:00:00']);

        $result = run_export([$item], locale_decimal: '.');

        $lines = explode("\n", trim($result['output']));
        // With comma delimiter, header should have commas (from fputcsv).
        expect($lines[0])->toContain(',');
    });

    it('has correct number of columns (12)', function () {
        $result = run_export([], locale_decimal: '.');

        // Remove BOM and parse header.
        $clean = ltrim($result['output'], "\xEF\xBB\xBF");
        $lines = explode("\n", trim($clean));

        // Parse the header row.
        $header = str_getcsv($lines[0], ',');
        expect($header)->toHaveCount(12);
    });

    it('includes date range in filename when filtered', function () {
        $result = run_export([], '2024-01-01', '2024-12-31');

        $disposition = array_filter(
            $result['headers'],
            fn ($h) => str_starts_with($h, 'Content-Disposition:'),
        );

        $header = array_values($disposition)[0];
        expect($header)->toContain('2024-01-01');
        expect($header)->toContain('2024-12-31');
    });

    it('uses date-only filename when no filter', function () {
        $result = run_export([]);

        $disposition = array_filter(
            $result['headers'],
            fn ($h) => str_starts_with($h, 'Content-Disposition:'),
        );

        $header = array_values($disposition)[0];
        expect($header)->toContain('kntnt-ad-attribution-2024-01-01.csv');
    });

    it('shows deleted for missing target', function () {
        $item = make_click(['target_post_id' => 0]);

        // get_permalink returns false for post_id 0.
        $result = run_export([$item], locale_decimal: '.');

        expect($result['output'])->toContain('(deleted)');
    });

    it('includes Clicked At, Attribution and Converted At header names', function () {
        $result = run_export([]);

        $clean  = ltrim($result['output'], "\xEF\xBB\xBF");
        $header = str_getcsv(explode("\n", $clean)[0], ',');

        // New per-click column names are present.
        expect($header)->toContain('Clicked At');
        expect($header)->toContain('Attribution');
        expect($header)->toContain('Converted At');

        // Old aggregated column names are absent.
        expect($header)->not->toContain('Clicks');
        expect($header)->not->toContain('Conversions');
    });

    it('outputs clicked_at timestamp as-is', function () {
        $item   = make_click();
        $result = run_export([$item]);
        $rows   = parse_csv_rows($result['output']);

        expect($rows[0]['Clicked At'])->toBe('2024-06-15 10:30:00');
    });

    it('formats fractional_conversion with 4 decimals', function () {
        $item = make_click([
            'fractional_conversion' => 1.0,
            'converted_at'         => '2024-06-15 11:00:00',
        ]);

        $result = run_export([$item], locale_decimal: '.');
        $rows   = parse_csv_rows($result['output']);

        expect($rows[0]['Attribution'])->toBe('1.0000');
    });

    it('formats fractional_conversion with locale decimal separator', function () {
        $item = make_click([
            'fractional_conversion' => 0.5,
            'converted_at'         => '2024-06-15 11:00:00',
        ]);

        $result = run_export([$item], locale_decimal: ',');
        $rows   = parse_csv_rows($result['output'], ';');

        expect($rows[0]['Attribution'])->toBe('0,5000');
    });

    it('outputs empty attribution and converted_at when no conversion', function () {
        $item   = make_click();
        $result = run_export([$item]);
        $rows   = parse_csv_rows($result['output']);

        expect($rows[0]['Attribution'])->toBe('');
        expect($rows[0]['Converted At'])->toBe('');
    });

    it('outputs converted_at timestamp as-is', function () {
        $item = make_click([
            'fractional_conversion' => 0.25,
            'converted_at'         => '2024-06-15 11:00:00',
        ]);

        $result = run_export([$item], locale_decimal: '.');
        $rows   = parse_csv_rows($result['output']);

        expect($rows[0]['Converted At'])->toBe('2024-06-15 11:00:00');
    });

    it('produces one row per item with 12 fields each', function () {
        $items = [
            make_click([
                'fractional_conversion' => 1.0,
                'converted_at'         => '2024-06-15 11:00:00',
            ]),
            make_click([
                'hash'       => 'def456',
                'clicked_at' => '2024-06-16 09:00:00',
            ]),
        ];

        $result = run_export($items);
        $rows   = parse_csv_rows($result['output']);

        // Two data rows.
        expect($rows)->toHaveCount(2);

        // Each row has 12 fields.
        foreach ($rows as $row) {
            expect($row)->toHaveCount(12);
        }
    });

});
