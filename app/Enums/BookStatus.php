<?php

namespace App\Enums;

enum BookStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Generating = 'generating';
    case Complete = 'complete';
    case Failed = 'failed';
}
