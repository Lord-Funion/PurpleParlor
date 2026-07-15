<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

$generated = str_replace("\r\n", "\n", App\Database\Schema::sql('mysql'));
$stored = str_replace("\r\n", "\n", (string) file_get_contents($root . '/database/schema.sql'));
if (!hash_equals($generated, $stored)) {
    fwrite(STDERR, "Schema parity check failed. Regenerate database/schema.sql from App\\Database\\Schema.\n");
    exit(1);
}
fwrite(STDOUT, "Schema parity check passed.\n");
