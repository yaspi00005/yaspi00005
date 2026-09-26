<?php

namespace App\Service\Sms;

final class SmsSenderFactory
{
    public function __construct(
        private readonly LogSmsSender $log,
        private readonly OrangeSmsSender $orange,
    ) {
    }

    public function create(string $transport): SmsSenderInterface
    {
        return match ($transport) {
            'orange' => $this->orange,
            default => $this->log,
        };
    }
}
