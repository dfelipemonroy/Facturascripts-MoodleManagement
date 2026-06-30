<?php

/**
 * PHP-CS-Fixer configuration for MoodleManagement plugin.
 *
 * FacturaScripts 2025.81 · PHP 8.0+
 * Conforms with V2.0-ACTION-PLAN F0.4.
 *
 * Usage (from plugin root):
 *   vendor/bin/php-cs-fixer fix --dry-run --diff
 *   vendor/bin/php-cs-fixer fix
 *
 * Rule baseline: PSR-12 + short array syntax + single quotes.
 *
 * @since 2.0
 */

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/Controller')
    ->in(__DIR__ . '/Model')
    ->in(__DIR__ . '/Lib')
    ->in(__DIR__ . '/Worker')
    ->in(__DIR__ . '/Extension')
    ->in(__DIR__ . '/Test')
    ->append([
        __DIR__ . '/Cron.php',
        __DIR__ . '/Init.php',
    ])
    ->exclude([
        'Dinamic',
        'vendor',
        'node_modules',
        'Assets/JS/vendor',
        'docs',
        '.docs-dev',
        'Test/Fixtures',
    ])
    ->notPath('#/chart(\.min)?\.js#');

$config = new PhpCsFixer\Config();

return $config
    ->setRiskyAllowed(true)
    ->setRules([
        // Baseline — PSR-12
        '@PSR12' => true,
        '@PSR12:risky' => true,

        // Arrays
        'array_syntax' => ['syntax' => 'short'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,

        // Strings — single quotes unless interpolation
        'single_quote' => true,

        // Imports
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // Whitespace / blank lines
        'no_extra_blank_lines' => [
            'tokens' => ['extra', 'throw', 'use', 'use_trait', 'curly_brace_block', 'parenthesis_brace_block', 'square_brace_block'],
        ],
        'no_whitespace_in_blank_line' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,

        // Functions
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'return_type_declaration' => ['space_before' => 'none'],

        // Type hints — nullable ? in front
        'nullable_type_declaration_for_default_null_value' => true,

        // Control structures
        'yoda_style' => false,

        // PHPDoc
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_indent' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_package' => false,
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,

        // Concat
        'concat_space' => ['spacing' => 'one'],

        // Binary operators
        'binary_operator_spaces' => ['default' => 'single_space'],

        // Risky but desirable
        'declare_strict_types' => false, // Do NOT enable blindly across FS plugin
        'ternary_to_null_coalescing' => true,
        'void_return' => true,
    ])
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
