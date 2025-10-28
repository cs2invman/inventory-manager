<?php

namespace App\Enum;

enum WearCategoryEnum: string
{
    case FACTORY_NEW = 'FN';
    case MINIMAL_WEAR = 'MW';
    case FIELD_TESTED = 'FT';
    case WELL_WORN = 'WW';
    case BATTLE_SCARRED = 'BS';

    public function getFullName(): string
    {
        return match($this) {
            self::FACTORY_NEW => 'Factory New',
            self::MINIMAL_WEAR => 'Minimal Wear',
            self::FIELD_TESTED => 'Field-Tested',
            self::WELL_WORN => 'Well-Worn',
            self::BATTLE_SCARRED => 'Battle-Scarred',
        };
    }

    public function getFloatRange(): array
    {
        return match($this) {
            self::FACTORY_NEW => [0.00, 0.07],
            self::MINIMAL_WEAR => [0.07, 0.15],
            self::FIELD_TESTED => [0.15, 0.38],
            self::WELL_WORN => [0.38, 0.45],
            self::BATTLE_SCARRED => [0.45, 1.00],
        };
    }

    public static function fromFloat(float $floatValue): ?self
    {
        if ($floatValue >= 0.00 && $floatValue < 0.07) {
            return self::FACTORY_NEW;
        } elseif ($floatValue >= 0.07 && $floatValue < 0.15) {
            return self::MINIMAL_WEAR;
        } elseif ($floatValue >= 0.15 && $floatValue < 0.38) {
            return self::FIELD_TESTED;
        } elseif ($floatValue >= 0.38 && $floatValue < 0.45) {
            return self::WELL_WORN;
        } elseif ($floatValue >= 0.45 && $floatValue <= 1.00) {
            return self::BATTLE_SCARRED;
        }
        return null;
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