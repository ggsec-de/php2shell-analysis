<?php
// SPDX-License-Identifier: MIT
// Copyright (c) 2026 GG ADVANCED IT Security UG
//
/*
 * unserialize-sink-check -- does request data reach unserialize() on this host?
 *
 * Written while triaging whether the var_destroy re-entry UAF (GH-24373) is reachable on a
 * WordPress site. It answers the only question that matters for exposure: where does the
 * application call unserialize(), and which of those files also read a request superglobal?
 * A call site fed from the database is not a sink; a call site fed from $_GET is.
 *
 * READ-ONLY. It writes nothing, executes nothing, opens no network connection and has no
 * shell access. It prints PHP settings, the plugin and theme inventory with versions,
 * and the unserialize() call sites it can see.
 *
 * Use it only on a server you own or operate.
 *
 * ---------------------------------------------------------------------------------
 * USAGE
 *
 * Preferred -- CLI, no exposure, nothing to clean up:
 *
 *     php unserialize-sink-check.php                 # auto-detect the site below the cwd
 *     php unserialize-sink-check.php --path=/var/www/html
 *
 * Fallback -- through the web, if you have no shell:
 *
 *     set $TOKEN below to something random, upload under a random name next to
 *     wp-config.php, open it as ...?k=<token>, and delete the file afterwards.
 *
 *     The script refuses to run over HTTP while $TOKEN is still the placeholder,
 *     because an unedited copy in a webroot publishes your plugin inventory to anyone
 *     who guesses the filename.
 *
 * EXIT CODES (CLI only)
 *
 *     0   scanned, no file both calls unserialize() and reads a superglobal
 *     2   scanned, at least one candidate found -- read the list, it is a heuristic
 *     1   usage error, or the placeholder was not filled in
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS DOES NOT PROVE
 *
 * The pairing test is per-file. It will not see a path where a.php reads $_POST and
 * hands the value to a function in b.php that unserializes it. Absence of candidates
 * means "no obvious sink", not "audited clean". Presence of candidates means "read this
 * file", not "vulnerable here".
 */

declare(strict_types=1);

const TOOL_VERSION = '1.0.0';
const TOKEN_PLACEHOLDER = 'CHANGE-ME-before-upload';

/*
 * Set this to any random string before uploading. Leave it as the placeholder to use
 * the script from the command line, where no token is needed.
 */
$TOKEN = TOKEN_PLACEHOLDER;

$isCli = PHP_SAPI === 'cli';

$fail = static function (string $message, int $code): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
    } else {
        http_response_code($code === 0 ? 200 : 404);
        echo $message, "\n";
    }
    exit($code);
};

if (!$isCli) {
    if ($TOKEN === TOKEN_PLACEHOLDER) {
        $fail('This copy still holds the placeholder token. Set $TOKEN to a random string before uploading.', 1);
    }
    if (!isset($_GET['k']) || !hash_equals($TOKEN, (string) $_GET['k'])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function row(string $label, $value): void
{
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    } elseif (is_array($value)) {
        $value = $value === [] ? '(none)' : implode(', ', $value);
    }
    printf("%-34s %s\n", $label, (string) $value);
}

function section(string $title): void
{
    echo "\n== ", $title, " ", str_repeat('=', max(0, 58 - strlen($title))), "\n";
}

function header_value(string $file, string $field): ?string
{
    $source = @file_get_contents($file, false, null, 0, 8192);
    if ($source === false) {
        return null;
    }
    if (preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':[\s]*(.+)$/mi', $source, $m)) {
        return trim($m[1]);
    }
    return null;
}

/* ------------------------------------------------------------------ args --- */

$scanRoot = null;
if ($isCli) {
    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '--path=') === 0) {
            $scanRoot = rtrim(substr($argument, 7), '/\\');
            if (!is_dir($scanRoot)) {
                $fail('Not a directory: ' . $scanRoot, 1);
            }
        } elseif ($argument === '--help' || $argument === '-h') {
            echo 'unserialize-sink-check ', TOOL_VERSION, "\n\n";
            echo "  php unserialize-sink-check.php [--path=DIR]\n\n";
            echo "  Read-only. Reports PHP settings, plugins and themes with versions, and\n";
            echo "  every unserialize() call site, flagging files that also read a superglobal.\n";
            exit(0);
        } else {
            $fail('Unknown argument: ' . $argument . "\nTry --help.", 1);
        }
    }
}

section('unserialize-sink-check ' . TOOL_VERSION);
row('mode', $isCli ? 'cli (no token needed)' : 'http');

/* ----------------------------------------------------------------- build --- */

section('PHP build');
row('PHP_VERSION', PHP_VERSION);
row('PHP_SAPI', PHP_SAPI);
row('PHP_OS / machine', PHP_OS . ' / ' . php_uname('m'));
row('thread safety', defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'ZTS' : 'NTS');
row('int size', PHP_INT_SIZE * 8 . '-bit');
row('unserialize() available', function_exists('unserialize'));
row('loaded ini file', php_ini_loaded_file() ?: '(none)');

section('Limits and session settings');
foreach ([
    'memory_limit',
    'post_max_size',
    'max_execution_time',
    'max_input_vars',
    'session.serialize_handler',
    'session.upload_progress.enabled',
] as $key) {
    row($key, ini_get($key));
}
row('open_basedir', ini_get('open_basedir') ?: '(none)');
row('disable_functions', array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));

/* ------------------------------------------------------------- discovery --- */

section('Target');

$siteRoot = null;
$start = $scanRoot ?? __DIR__;
$probe = $start;
for ($depth = 0; $depth < 5; $depth++) {
    if (is_file($probe . '/wp-config.php')) {
        $siteRoot = $probe;
        break;
    }
    $parent = dirname($probe);
    if ($parent === $probe) {
        break;
    }
    $probe = $parent;
}

if ($scanRoot !== null) {
    $contentRoot = $scanRoot;
} elseif ($siteRoot !== null && is_dir($siteRoot . '/wp-content')) {
    $contentRoot = $siteRoot . '/wp-content';
} elseif (is_dir(__DIR__ . '/wp-content')) {
    $contentRoot = __DIR__ . '/wp-content';
} else {
    $contentRoot = $start;
}

row('WordPress root', $siteRoot ?? 'not detected');
row('scanning', $contentRoot);

/* -------------------------------------------------------- plugin/theme --- */

if ($siteRoot !== null && basename($contentRoot) === 'wp-content') {
    section('Installed plugins');
    $pluginDir = $contentRoot . '/plugins';
    $found = 0;
    foreach ((array) @scandir($pluginDir) as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        $path = $pluginDir . '/' . $entry;
        if (is_file($path) && substr($entry, -4) === '.php') {
            $name = header_value($path, 'Plugin Name');
            if ($name !== null) {
                $found++;
                printf("  %-34s %-10s %s\n", basename($entry, '.php'), header_value($path, 'Version') ?? '?', $name);
            }
            continue;
        }
        if (!is_dir($path)) {
            continue;
        }
        foreach ((array) @scandir($path) as $inner) {
            if (!is_string($inner) || substr($inner, -4) !== '.php') {
                continue;
            }
            $name = header_value($path . '/' . $inner, 'Plugin Name');
            if ($name === null) {
                continue;
            }
            $found++;
            printf("  %-34s %-10s %s\n", $entry, header_value($path . '/' . $inner, 'Version') ?? '?', $name);
            break;
        }
    }
    row('plugins with headers', $found);

    section('Installed themes');
    $themeDir = $contentRoot . '/themes';
    $found = 0;
    foreach ((array) @scandir($themeDir) as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..' || !is_dir($themeDir . '/' . $entry)) {
            continue;
        }
        $style = $themeDir . '/' . $entry . '/style.css';
        if (!is_file($style)) {
            continue;
        }
        $name = header_value($style, 'Theme Name');
        if ($name === null) {
            continue;
        }
        $found++;
        printf("  %-34s %-10s %s\n", $entry, header_value($style, 'Version') ?? '?', $name);
    }
    row('themes with headers', $found);
}

/* ---------------------------------------------------------- unserialize --- */

section('unserialize() call sites');

$patterns = [
    'unserialize(' => '/\bunserialize\s*\(/i',
    'maybe_unserialize(' => '/\bmaybe_unserialize\s*\(/i',
    'from decoded input' => '/unserialize\s*\(\s*(?:base64_decode|stripslashes|urldecode|gzinflate|gzuncompress|hex2bin)/i',
];
$superglobal = '/\$_(?:GET|POST|COOKIE|REQUEST|FILES)\b/';

$counts = array_fill_keys(array_keys($patterns), 0);
$counts['files also reading superglobals'] = 0;
$hits = [];
$exposed = [];
$files = 0;
$skipped = 0;
$maxHits = 120;
$maxExposed = 40;

$limit = (int) ini_get('max_execution_time');
$budget = $limit > 0 ? max(5, $limit - 5) : 30;
$budget = min($budget, 45);
$started = microtime(true);
$complete = true;

$directory = new RecursiveDirectoryIterator($contentRoot, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    static function (SplFileInfo $current): bool {
        return !$current->isLink();
    }
);
$iterator = new RecursiveIteratorIterator($filter);

foreach ($iterator as $file) {
    if (microtime(true) - $started > $budget) {
        $complete = false;
        break;
    }
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $files++;
    $source = @file_get_contents($file->getPathname(), false, null, 0, 512 * 1024);
    if ($source === false) {
        $skipped++;
        continue;
    }
    $relative = str_replace($contentRoot, '', $file->getPathname());
    $hasUnserialize = false;
    foreach ($patterns as $name => $regex) {
        if (!preg_match_all($regex, $source, $m)) {
            continue;
        }
        $hasUnserialize = true;
        $counts[$name] += count($m[0]);
        if (count($hits) < $maxHits) {
            $hits[] = $relative . '  [' . $name . ']';
        }
    }
    if ($hasUnserialize && preg_match($superglobal, $source)) {
        $counts['files also reading superglobals']++;
        if (count($exposed) < $maxExposed) {
            $exposed[] = $relative;
        }
    }
}

row('php files scanned', $files);
row('unreadable, skipped', $skipped);
row('scan completed', $complete ? 'yes' : 'NO - budget ' . $budget . 's reached');
foreach ($counts as $name => $count) {
    row($name, $count);
}

echo "\n-- candidates: unserialize() AND a superglobal in the same file --\n";
if ($exposed === []) {
    echo "   none\n";
} else {
    echo '   ', implode("\n   ", $exposed), "\n";
    if (count($exposed) >= $maxExposed) {
        echo '   (truncated at ', $maxExposed, ")\n";
    }
    echo "\n   Co-occurrence is a heuristic, not a finding. Open each file and check whether\n";
    echo "   the value handed to unserialize() is the one taken from the request.\n";
}

echo "\n-- every call site --\n";
if ($hits === []) {
    echo "   none\n";
} else {
    echo '   ', implode("\n   ", array_unique($hits)), "\n";
    if (count($hits) >= $maxHits) {
        echo '   (truncated at ', $maxHits, ")\n";
    }
}

echo "\n", $isCli ? '' : 'Done. Delete this file now.', "\n";

if ($isCli) {
    exit($exposed === [] ? 0 : 2);
}
