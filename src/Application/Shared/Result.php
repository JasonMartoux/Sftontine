<?php

declare(strict_types=1);

namespace App\Application\Shared;

/**
 * @template-covariant T
 * @template-covariant E of \BackedEnum
 */
final readonly class Result
{
    private function __construct(
        public bool $isSuccess,
        private mixed $value,
        private mixed $error,
    ) {
    }

    /**
     * @template TValue
     *
     * @param TValue $value
     *
     * @return self<TValue, never>
     */
    public static function success(mixed $value = null): self
    {
        // PHPStan cannot verify that the shared private constructor's phantom `error`
        // side is `never` from a plain `null` argument — this is a known limitation of
        // phantom-typed Either/Result generics, not an actual type hole.
        // @phpstan-ignore return.type
        return new self(true, $value, null);
    }

    /**
     * @template TError of \BackedEnum
     *
     * @param TError $error
     *
     * @return self<never, TError>
     */
    public static function failure(\BackedEnum $error): self
    {
        // @phpstan-ignore return.type
        return new self(false, null, $error);
    }

    /**
     * @return T
     */
    public function value(): mixed
    {
        if (!$this->isSuccess) {
            throw new \LogicException('Cannot access the value of a failed result.');
        }

        /** @var T $value */
        $value = $this->value;

        return $value;
    }

    /**
     * @return E
     */
    public function error(): \BackedEnum
    {
        if ($this->isSuccess) {
            throw new \LogicException('Cannot access the error of a successful result.');
        }

        /** @var E $error */
        $error = $this->error;

        return $error;
    }
}
