<?php

namespace App\Service\Sms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi via l'API Orange SMS (https://developer.orange.com/apis/sms).
 * Le jeton OAuth est mis en cache jusqu'à son expiration. La vérification TLS reste active.
 */
final class OrangeSmsSender implements SmsSenderInterface
{
    private const BASE = 'https://api.orange.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire('%env(ORANGE_SMS_CLIENT_ID)%')] private readonly string $clientId,
        #[Autowire('%env(ORANGE_SMS_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(ORANGE_SMS_SENDER)%')] private readonly string $sender,
        #[Autowire('%env(ORANGE_SMS_SENDER_NAME)%')] private readonly string $senderName,
    ) {
    }

    public function send(string $telephone, string $message): void
    {
        if ('' === $this->clientId || '' === $this->clientSecret) {
            throw new \RuntimeException('Identifiants Orange SMS non configurés.');
        }

        $sender = $this->sender;
        $payload = ['outboundSMSMessageRequest' => [
            'address' => 'tel:'.$telephone,
            'senderAddress' => $sender,
            'outboundSMSTextMessage' => ['message' => $message],
        ]];
        if ('' !== $this->senderName) {
            $payload['outboundSMSMessageRequest']['senderName'] = $this->senderName;
        }

        $response = $this->httpClient->request('POST', self::BASE.'/smsmessaging/v1/outbound/'.rawurlencode($sender).'/requests', [
            'auth_bearer' => $this->token(),
            'json' => $payload,
        ]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('Orange SMS a répondu '.$response->getStatusCode().' : '.$response->getContent(false));
        }
    }

    private function token(): string
    {
        return $this->cache->get('orange_sms_token', function (ItemInterface $item): string {
            $response = $this->httpClient->request('POST', self::BASE.'/oauth/v3/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => ['grant_type' => 'client_credentials'],
            ]);
            $data = $response->toArray();
            $item->expiresAfter(max(60, (int) ($data['expires_in'] ?? 3600) - 60));

            return $data['access_token'];
        });
    }
}
