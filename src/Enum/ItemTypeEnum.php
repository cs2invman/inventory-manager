<?php

namespace App\Enum;

enum ItemTypeEnum: string
{
    case SKIN = 'Skin';
    case CASE = 'Case';
    case STICKER = 'Sticker';
    case GRAFFITI = 'Graffiti';
    case AGENT = 'Agent';
    case PATCH = 'Patch';
    case MUSIC_KIT = 'Music Kit';
    case COLLECTIBLE = 'Collectible';
    case TOOL = 'Tool';
    case KEY = 'Key';
    case PASS = 'Pass';
    case GIFT = 'Gift';
    case TAG = 'Tag';

    public function getLabel(): string
    {
        return $this->value;
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