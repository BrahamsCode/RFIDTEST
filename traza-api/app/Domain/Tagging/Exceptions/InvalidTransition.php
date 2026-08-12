<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Exceptions;

use App\Enums\TagState;
use DomainException;

final class InvalidTransition extends DomainException
{
    public static function between(TagState $from, TagState $to, ?string $context = null): self
    {
        $message = "Transición no permitida: {$from->value} → {$to->value}";

        return new self($context === null ? "{$message}." : "{$message} ({$context}).");
    }
}
