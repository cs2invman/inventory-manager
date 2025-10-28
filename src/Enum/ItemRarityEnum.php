<?php

namespace App\Enum;

enum ItemRarityEnum: string
{
    case CONSUMER = 'Consumer Grade';
    case INDUSTRIAL = 'Industrial Grade';
    case MIL_SPEC = 'Mil-Spec Grade';
    case RESTRICTED = 'Restricted';
    case CLASSIFIED = 'Classified';
    case COVERT = 'Covert';
    case CONTRABAND = 'Contraband';
    case EXTRAORDINARY = 'Extraordinary';
    case REMARKABLE = 'Remarkable';
    case EXOTIC = 'Exotic';
    case HIGH_GRADE = 'High Grade';
    case BASE_GRADE = 'Base Grade';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match($this) {
            self::CONSUMER => '#B0C3D9',
            self::INDUSTRIAL => '#5E98D9',
            self::MIL_SPEC => '#4B69FF',
            self::RESTRICTED => '#8847FF',
            self::CLASSIFIED => '#D32CE6',
            self::COVERT => '#EB4B4B',
            self::CONTRABAND => '#E4AE39',
            self::EXTRAORDINARY => '#E4AE39',
            self::REMARKABLE => '#8650AC',
            self::EXOTIC => '#8650AC',
            self::HIGH_GRADE => '#4B69FF',
            self::BASE_GRADE => '#B0C3D9',
        };
    }

    public static function fromString(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }
        return null;
    }
}