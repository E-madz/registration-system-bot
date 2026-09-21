<?php

namespace App\DataTransferObjects;

final class KeyboardButton
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
    ) {}
}
