<?php

namespace App\Enums;

enum BookingSource: string
{
    case Manual = 'manual';
    case Chat = 'chat';
}
