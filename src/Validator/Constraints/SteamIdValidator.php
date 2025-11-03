<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validator for SteamId constraint.
 *
 * Validates that a Steam ID follows the SteamID64 format:
 * - Exactly 17 digits
 * - Numeric only
 * - Starts with "7656"
 */
class SteamIdValidator extends ConstraintValidator
{
    private const STEAM_ID_LENGTH = 17;
    private const STEAM_ID_PREFIX = '7656';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SteamId) {
            throw new UnexpectedTypeException($constraint, SteamId::class);
        }

        // Null and empty strings are valid (allows optional field)
        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $length = strlen($value);

        // Check length
        if ($length !== self::STEAM_ID_LENGTH) {
            if ($length < self::STEAM_ID_LENGTH) {
                $this->context->buildViolation($constraint->tooShortMessage)
                    ->setParameter('{{ length }}', (string) $length)
                    ->addViolation();
            } else {
                $this->context->buildViolation($constraint->tooLongMessage)
                    ->setParameter('{{ length }}', (string) $length)
                    ->addViolation();
            }
            return;
        }

        // Check if numeric
        if (!ctype_digit($value)) {
            $this->context->buildViolation($constraint->notNumericMessage)
                ->addViolation();
            return;
        }

        // Check prefix
        if (!str_starts_with($value, self::STEAM_ID_PREFIX)) {
            $this->context->buildViolation($constraint->invalidPrefixMessage)
                ->addViolation();
            return;
        }
    }
}
