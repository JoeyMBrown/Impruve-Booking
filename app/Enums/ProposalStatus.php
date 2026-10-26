<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Dismissed = 'dismissed';
    case Expired = 'expired';
    case Failed = 'failed';
}
