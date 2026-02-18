<?php
/**
 * Test bootstrap.
 *
 * Loads the Composer autoloader, initializes Patchwork early and registers
 * a custom code manipulation that strips `final` from plugin classes.
 * This ensures both final-class mocking (Mockery) and internal function
 * interception (Brain Monkey/Patchwork) work in the same test run.
 *
 * @package Tests
 * @since   1.0.0
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Initialize Patchwork early (normally deferred to Brain Monkey's first setUp() call).
// This activates Patchwork's stream wrapper before any plugin classes are autoloaded,
// ensuring all classes go through Patchwork's source transformation pipeline.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

// Register a custom code manipulation that strips 'final' from plugin classes.
// Patchwork applies this alongside its built-in transformations (call interception,
// internal function redefinition, etc.) in a single pass through the stream wrapper.
// This replaces BypassFinals, which required a separate stream wrapper that couldn't
// coexist with Patchwork's.
$classesDir = realpath(dirname(__DIR__) . '/classes') . DIRECTORY_SEPARATOR;
\Patchwork\CodeManipulation\register(function (\Patchwork\CodeManipulation\Source $s) use ($classesDir) {
    if (!isset($s->file) || !str_starts_with($s->file, $classesDir)) {
        return;
    }
    foreach ($s->all(T_FINAL) as $offset) {
        $next = $s->skip(\Patchwork\CodeManipulation\Source::junk(), $offset);
        if ($s->is(T_CLASS, $next)) {
            $s->splice('', $offset, 1);
        }
    }
});
