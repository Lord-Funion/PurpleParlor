<?php

declare(strict_types=1);

use App\Database\Database;
use App\Database\Schema;

return static function (Database $database): void {
    // Idempotently adds account-control tables introduced after the initial schema.
    Schema::install($database);
};
