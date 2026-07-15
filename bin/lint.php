<?php

declare(strict_types=1);

$root = realpath(dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "Project root could not be resolved.\n");
    exit(1);
}

$excludedRoots = ['.git', 'deployment', 'vendor'];
$checked = 0;
$failures = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (in_array(explode('/', $relative, 2)[0], $excludedRoots, true)) {
        continue;
    }
    ++$checked;
    try {
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            throw new RuntimeException('File could not be read.');
        }
        token_get_all($source, TOKEN_PARSE);
    } catch (Throwable $error) {
        $failures[] = $relative . ': ' . $error->getMessage();
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}
fwrite(STDOUT, "PHP syntax: {$checked} checked, " . count($failures) . " failed.\n");
exit($failures === [] ? 0 : 1);
