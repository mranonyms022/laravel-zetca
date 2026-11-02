<?php

declare(strict_types=1);

/**
 * Enforces ARCHITECTURE 4.1: nothing under src/Core may depend on Laravel/Illuminate.
 * Exit code 1 lists every violation. Runs in CI on every matrix cell.
 */
$root = dirname(__DIR__) . '/src/Core';
$forbidden = '/\b(Illuminate|Laravel|Orchestra)\\\\|\b(app|config|env|resolve|event|now|logger|cache)\s*\(/';
$violations = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $tokens = token_get_all((string) file_get_contents($file->getPathname()));
    $code = '';
    foreach ($tokens as $token) {
        // Ignore comments and docblocks; only real code counts.
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    foreach (explode("\n", $code) as $i => $line) {
        if (preg_match($forbidden, $line) === 1 && ! str_contains($line, '->')) {
            $violations[] = sprintf('%s:%d  %s', substr($file->getPathname(), strlen(dirname($root, 2)) + 1), $i + 1, trim($line));
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Core boundary violations (src/Core must not use Laravel):\n  " . implode("\n  ", $violations) . "\n");
    exit(1);
}

echo "Core boundary OK\n";
