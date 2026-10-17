<?php

declare(strict_types=1);

namespace TeamCodeHub\Core;

interface ModuleInterface
{
    public function id(): string;

    public function registerRoutes(Router $router): void;

    /** @return array<string, string|int|bool|null> */
    public function health(): array;
}
