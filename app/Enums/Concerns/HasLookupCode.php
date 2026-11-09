<?php

namespace App\Enums\Concerns;

/**
 * Maps an int-backed enum (whose values are lookup-table IDs) to the
 * upper-case codes stored in the lookup table and used by the API.
 */
trait HasLookupCode
{
    /**
     * The lookup-table code, e.g. "PENDING".
     */
    public function code(): string
    {
        return strtoupper($this->name);
    }

    /**
     * Resolve a case from its lookup-table code.
     */
    public static function fromCode(string $code): self
    {
        foreach (self::cases() as $case) {
            if ($case->code() === $code) {
                return $case;
            }
        }

        throw new \ValueError("[{$code}] is not a valid code for ".self::class);
    }

    /**
     * Every code, for validation rules.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(fn (self $case): string => $case->code(), self::cases());
    }
}
