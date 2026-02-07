<?php

namespace App\Enums;

enum DataTier: int
{
    case Public = 0;
    case FreeAccount = 1;
    case Unlocked = 2;
    case Subscriber = 3;
    case Enterprise = 4;
    case Admin = 99;
}
