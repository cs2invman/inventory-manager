<?php

namespace App\Enum;

enum PriceSourceEnum: string
{
    case STEAM_API = 'steam_api';
    case STEAM_SCRAPE = 'steam_scrape';
    case THIRD_PARTY = 'third_party';
    case MANUAL = 'manual';
    case CSGOFLOAT = 'csgofloat';
    case BUFF163 = 'buff163';
    case SKINPORT = 'skinport';

    public function getLabel(): string
    {
        return match($this) {
            self::STEAM_API => 'Steam API',
            self::STEAM_SCRAPE => 'Steam Scrape',
            self::THIRD_PARTY => 'Third Party',
            self::MANUAL => 'Manual Entry',
            self::CSGOFLOAT => 'CSGOFloat',
            self::BUFF163 => 'Buff163',
            self::SKINPORT => 'Skinport',
        };
    }

    public function isReliable(): bool
    {
        return match($this) {
            self::STEAM_API, self::CSGOFLOAT, self::BUFF163, self::SKINPORT => true,
            self::STEAM_SCRAPE, self::THIRD_PARTY, self::MANUAL => false,
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