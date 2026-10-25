<?php

namespace App\Enums;

enum Scope: string
{
    case SearchRead = 'search:read';
    case KeysManage = 'keys:manage';
}
