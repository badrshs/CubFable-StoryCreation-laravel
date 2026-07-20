<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Paddle = 'paddle';
}
