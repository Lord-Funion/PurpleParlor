<?php

declare(strict_types=1);

namespace PurpleParlor\Games\DTO;

final class ValidationResult implements \JsonSerializable
{
    /** @param list<array{field:string,code:string,message:string}> $errors */
    private function __construct(private readonly array $errors)
    {
    }

    public static function valid(): self
    {
        return new self([]);
    }

    public static function invalid(string $field, string $code, string $message): self
    {
        return new self([['field' => $field, 'code' => $code, 'message' => $message]]);
    }

    /** @param list<array{field:string,code:string,message:string}> $errors */
    public static function fromErrors(array $errors): self
    {
        return new self($errors);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array{field:string,code:string,message:string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function jsonSerialize(): array
    {
        return ['valid' => $this->isValid(), 'errors' => $this->errors];
    }
}
