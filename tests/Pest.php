<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

pest()->beforeEach(function () {
    Monkey\setUp();

    // Stub common WordPress functions that most tests need.
    Functions\stubTranslationFunctions();  // __, _e, _n, esc_html__, etc.
    Functions\stubEscapeFunctions();       // esc_html, esc_attr, esc_url, etc.

    // Stub sanitize functions.
    Functions\when('sanitize_text_field')->returnArg(1);
    Functions\when('absint')->alias(fn ($val) => abs((int) $val));
    Functions\when('wp_unslash')->returnArg(1);

    // Stub DAY_IN_SECONDS, MINUTE_IN_SECONDS if not defined.
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
})->afterEach(function () {
    Monkey\tearDown();
});
