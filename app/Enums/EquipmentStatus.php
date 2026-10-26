<?php

namespace App\Enums;

enum EquipmentStatus: string
{
    case Available = 'available';
    case OutOfService = 'out_of_service';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::OutOfService => 'Out of service',
            self::Retired => 'Retired',
        };
    }
}
