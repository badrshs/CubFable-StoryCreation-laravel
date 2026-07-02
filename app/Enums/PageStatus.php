<?php

namespace App\Enums;

enum PageStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Complete = 'complete';
    case Failed = 'failed';
}
