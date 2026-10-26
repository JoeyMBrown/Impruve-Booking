<?php

namespace App\Enums;

enum EquipmentCategory: string
{
    case Camera = 'camera';
    case Lighting = 'lighting';
    case Space = 'space';

    public function label(): string
    {
        return match ($this) {
            self::Camera => 'Camera',
            self::Lighting => 'Lighting',
            self::Space => 'Space',
        };
    }
}
