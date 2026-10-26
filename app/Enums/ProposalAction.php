<?php

namespace App\Enums;

enum ProposalAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Cancel = 'cancel';
}
