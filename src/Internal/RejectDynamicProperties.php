<?php

declare(strict_types=1);

namespace WhollyCrypto\Internal;

/** @internal Preserve readonly-class behavior on PHP 8.1. */
trait RejectDynamicProperties
{
    public function __set(string $name, #[\SensitiveParameter] mixed $value): void
    {
        throw new \Error('Cannot add or change an inaccessible property on an immutable SDK value.');
    }
}
