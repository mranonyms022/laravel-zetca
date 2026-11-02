<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Validation;

/** Field-level errors and warnings, each tied to a ZATCA rule ID where one applies. */
final class ValidationResult
{
    /** @var list<array{field: string, code: string, message: string}> */
    private array $errors = [];

    /** @var list<array{field: string, code: string, message: string}> */
    private array $warnings = [];

    public function error(string $field, string $code, string $message): self
    {
        $this->errors[] = ['field' => $field, 'code' => $code, 'message' => $message];

        return $this;
    }

    public function warning(string $field, string $code, string $message): self
    {
        $this->warnings[] = ['field' => $field, 'code' => $code, 'message' => $message];

        return $this;
    }

    public function merge(self $other): self
    {
        array_push($this->errors, ...$other->errors);
        array_push($this->warnings, ...$other->warnings);

        return $this;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasError(string $code): bool
    {
        foreach ($this->errors as $e) {
            if ($e['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /** @throws InvoiceValidationException */
    public function throwIfInvalid(): void
    {
        if (! $this->isValid()) {
            throw InvoiceValidationException::fromResult($this);
        }
    }
}
