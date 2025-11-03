<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Custom constraint for validating Steam ID format.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class SteamId extends Constraint
{
    public string $message = 'Invalid Steam ID format. Must be exactly 17 digits, numeric only, and start with "7656".';
    public string $tooShortMessage = 'Steam ID must be exactly 17 digits long (currently {{ length }} digits).';
    public string $tooLongMessage = 'Steam ID must be exactly 17 digits long (currently {{ length }} digits).';
    public string $notNumericMessage = 'Steam ID must contain only numbers.';
    public string $invalidPrefixMessage = 'Steam ID must start with "7656" (SteamID64 format).';
}
