<?php
declare(strict_types=1);

namespace Pjas\Domain;

final class ValidationException extends \RuntimeException
{
    /** @param array<int,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' / ', $errors));
    }
}
