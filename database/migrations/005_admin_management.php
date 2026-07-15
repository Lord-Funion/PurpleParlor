<?php

declare(strict_types=1);

use App\Database\Database;
use App\Database\Schema;

return static function (Database $database): void {
    // Adds the local-only refund request workflow and its supporting index.
    Schema::install($database);
};
