<?php

namespace App\Service\Sms;

use Psr\Log\LoggerInterface;

/** Transport par défaut : n'envoie rien, écrit le SMS dans les logs. */
final class LogSmsSender implements SmsSenderInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(string $telephone, string $message): void
    {
        $this->logger->info('SMS (non envoyé, transport "log") à {tel} : {msg}', ['tel' => $telephone, 'msg' => $message]);
    }
}
