<?php

namespace App\Service\Sms;

interface SmsSenderInterface
{
    /** @throws \RuntimeException si l'envoi échoue */
    public function send(string $telephone, string $message): void;
}
