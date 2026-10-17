<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;

interface MigrationInterface
{
    /** Apply this schema change. Recovery from interrupted DDL must be considered. */
    public function up(PDO $pdo): void;
}
