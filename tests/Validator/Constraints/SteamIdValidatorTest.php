<?php

namespace App\Tests\Validator\Constraints;

use App\Validator\Constraints\SteamId;
use App\Validator\Constraints\SteamIdValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Unit tests for SteamIdValidator.
 */
class SteamIdValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): SteamIdValidator
    {
        return new SteamIdValidator();
    }

    /**
     * Test that null is valid (allows optional field).
     */
    public function testNullIsValid(): void
    {
        $this->validator->validate(null, new SteamId());

        $this->assertNoViolation();
    }

    /**
     * Test that empty string is valid (allows optional field).
     */
    public function testEmptyStringIsValid(): void
    {
        $this->validator->validate('', new SteamId());

        $this->assertNoViolation();
    }

    /**
     * Test valid Steam IDs.
     *
     * @dataProvider validSteamIdProvider
     */
    public function testValidSteamIds(string $steamId): void
    {
        $this->validator->validate($steamId, new SteamId());

        $this->assertNoViolation();
    }

    /**
     * Test Steam ID that is too short.
     */
    public function testSteamIdTooShort(): void
    {
        $constraint = new SteamId();
        $this->validator->validate('7656119801234567', $constraint);

        $this->buildViolation($constraint->tooShortMessage)
            ->setParameter('{{ length }}', '16')
            ->assertRaised();
    }

    /**
     * Test Steam ID that is too long.
     */
    public function testSteamIdTooLong(): void
    {
        $constraint = new SteamId();
        $this->validator->validate('765611980123456789', $constraint);

        $this->buildViolation($constraint->tooLongMessage)
            ->setParameter('{{ length }}', '18')
            ->assertRaised();
    }

    /**
     * Test Steam ID with non-numeric characters.
     */
    public function testSteamIdNotNumeric(): void
    {
        $constraint = new SteamId();
        $this->validator->validate('7656119801234567a', $constraint);

        $this->buildViolation($constraint->notNumericMessage)
            ->assertRaised();
    }

    /**
     * Test Steam ID with invalid prefix.
     */
    public function testSteamIdInvalidPrefix(): void
    {
        $constraint = new SteamId();
        $this->validator->validate('86561198012345678', $constraint);

        $this->buildViolation($constraint->invalidPrefixMessage)
            ->assertRaised();
    }

    /**
     * Test Steam ID with spaces.
     */
    public function testSteamIdWithSpaces(): void
    {
        $constraint = new SteamId();
        $this->validator->validate('76561198 12345678', $constraint);

        $this->buildViolation($constraint->notNumericMessage)
            ->assertRaised();
    }

    /**
     * Provide valid Steam IDs for testing.
     */
    public static function validSteamIdProvider(): array
    {
        return [
            'standard SteamID64' => ['76561198012345678'],
            'another valid ID' => ['76561199999999999'],
            'minimum valid ID' => ['76561198000000000'],
        ];
    }
}
