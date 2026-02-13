# Coding Standards

## Baseline

WordPress coding standards and best practices apply as a starting point, with the exceptions listed below.

## Modern PHP (8.3)

The plugin requires PHP 8.3 and shall fully leverage the language's modern features:

- **Typed properties:** `public readonly string $hash;`
- **Union types and intersection types:** `string|false`, `Countable&Iterator`
- **Named arguments:** `str_contains( haystack: $ua, needle: 'bot' )`
- **Match expressions** instead of switch
- **Arrow functions** (`fn() =>`) for short callbacks
- **Readonly properties and classes** where applicable
- **Enums** for discrete value sets
- **Null-safe operator:** `$role?->add_cap( 'kntnt_ad_attr' )`
- **Array spread:** `[ ...$existing, $new ]`
- **First-class callable syntax:** `array_filter( $items, $this->isValid(...) )`
- **`str_contains()`, `str_starts_with()`, `str_ends_with()`** instead of `strpos` comparisons

## Literals and Syntax

- Array literals: `[1, 2, 3]` — never `array(1, 2, 3)`.
- Short closures: `fn( $x ) => $x * 2` where possible.
- Ternary: `$value ?: $default` and `$value ?? $fallback`.
- `declare( strict_types=1 )` in every PHP file.

## Code Style (Airbnb JS-inspired)

- **Predictable structure:** early returns, avoid deep nesting.
- **Small functions** with a single clear responsibility.
- **Descriptive names** — variable names should be self-documenting. Avoid abbreviations except well-established ones (`$url`, `$id`, `$db`).
- **Const over let, let over var** (PHP equivalent): prefer `readonly` and `const` over mutable state.
- **No magic strings/numbers** — use named constants.
- **Trailing commas** in multi-line arrays and parameter lists.

## WordPress Deviations

The following WordPress conventions are **not** followed:

- **Yoda conditions** (`if ( 42 === $value )`) — use natural order instead (`if ( $value === 42 )`). Strict types eliminate the risk of accidental assignment.
- **`array()` syntax** — use `[]`.
- **Global function prefixes** — the plugin uses namespaces (`Kntnt\Ad_Attribution`).
- **File naming convention** — classes are named `ClassName.php` (PSR-4), not `class-classname.php`.

## Documentation and Comments

### PHPDoc and JSDoc

Every file, class, trait, interface, enum, property, constant, method, and function shall have a PHPDoc (PHP) or JSDoc (JavaScript) block. Include:

- A concise summary line.
- `@param`, `@return`, `@throws` where applicable.
- `@since 1.0.0` for the initial release.

### Inline Code Comments

Organize code into logical blocks and write a comment above each block explaining its "job to do". Comments are written for experienced senior developers who need to quickly orient themselves in the code. Specifically:

- Explain **why**, not **what** — do not restate what is obvious from the code itself.
- Document architecture and design decisions where they are non-obvious.
- Use end-of-line comments sparingly — only where there is a real risk that a reader would miss a critical or subtle detail.
- Do **not** write comments aimed at juniors or beginners.

### Language Rules

- All identifier names (classes, traits, interfaces, enums, properties, methods, functions, variables, constants, etc.) shall be in **English**.
- All code comments (PHPDoc, JSDoc, inline, end-of-line) shall be in **English**.
- All documentation, including `CLAUDE.md` and all files in `docs/`, shall be in **English**.

## General

- All user-facing strings shall be translatable via `__()` / `_e()` / `esc_html__()`.
- All SQL queries via `$wpdb->prepare()`.
- All admin URLs via `admin_url()` / `wp_nonce_url()`.
- No direct access to `$_GET`, `$_POST`, `$_COOKIE` without sanitization.
