<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP's zip extension is required to create the archive.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "Project root could not be resolved.\n");
    exit(1);
}

$deployment = $root . DIRECTORY_SEPARATOR . 'deployment';
if (!is_dir($deployment) && !mkdir($deployment, 0755, true) && !is_dir($deployment)) {
    fwrite(STDERR, "Deployment directory could not be created.\n");
    exit(1);
}

$stamp = gmdate('Ymd-His');
$zipPath = $deployment . DIRECTORY_SEPARATOR . "purple-parlor-godaddy-{$stamp}.zip";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Deployment archive could not be opened.\n");
    exit(1);
}

$excludedRoots = ['.git', 'deployment'];
$excludedFiles = ['.env', 'config/app.php', 'storage/installed.lock', 'phpunit.xml', 'Thumbs.db', '.DS_Store'];
$excludedPrefixes = [
    'storage/backups/',
    'storage/cache/',
    'storage/logs/',
    'storage/sessions/',
    'storage/temporary/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $absolute = $file->getPathname();
    $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
    $first = explode('/', $relative, 2)[0];
    $privateEnvironmentArtifact = $relative === '.env'
        || (str_starts_with($relative, '.env.') && $relative !== '.env.example');
    if (in_array($first, $excludedRoots, true) || in_array($relative, $excludedFiles, true) || $privateEnvironmentArtifact) {
        continue;
    }
    $skip = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relative, $prefix) && !str_ends_with($relative, '.gitkeep')) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }
    if (!$zip->addFile($absolute, 'purple-parlor/' . $relative)) {
        $zip->close();
        @unlink($zipPath);
        fwrite(STDERR, "Could not add {$relative}; no archive was retained.\n");
        exit(1);
    }
}

if (!$zip->close()) {
    @unlink($zipPath);
    fwrite(STDERR, "Deployment archive could not be finalized; no archive was retained.\n");
    exit(1);
}
$checksum = hash_file('sha256', $zipPath);
if ($checksum === false) {
    @unlink($zipPath);
    fwrite(STDERR, "Archive checksum could not be calculated.\n");
    exit(1);
}
$checksumPath = $zipPath . '.sha256';
$checksumBytes = file_put_contents($checksumPath, $checksum . '  ' . basename($zipPath) . PHP_EOL, LOCK_EX);
if ($checksumBytes === false) {
    @unlink($zipPath);
    @unlink($checksumPath);
    fwrite(STDERR, "Archive checksum file could not be written; no archive was retained.\n");
    exit(1);
}

fwrite(STDOUT, $zipPath . PHP_EOL . 'SHA-256: ' . $checksum . PHP_EOL);
