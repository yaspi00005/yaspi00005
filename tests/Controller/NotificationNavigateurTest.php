<?php

namespace App\Tests\Controller;

use App\Entity\PushAbonnement;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationNavigateurTest extends WebTestCase
{
    public function testAbonnementEtDesabonnementDuNavigateur(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $crawler = $client->request('GET', '/profil');
        $token = $crawler->filter('body')->attr('data-push-token');
        $abonnement = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'BExemple', 'auth' => 'secret']];
        $entetes = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token];

        // Sans jeton : refusé.
        $client->request('POST', '/notifications/abonnement', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($abonnement));
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/notifications/abonnement', [], [], $entetes, json_encode($abonnement));
        $this->assertResponseIsSuccessful();
        // Deuxième envoi du même navigateur : pas de doublon.
        $client->request('POST', '/notifications/abonnement', [], [], $entetes, json_encode($abonnement));
        $repo = static::getContainer()->get('doctrine')->getRepository(PushAbonnement::class);
        $this->assertSame(1, $repo->count(['empreinte' => PushAbonnement::empreinte($abonnement['endpoint'])]));

        $client->request('POST', '/notifications/desabonnement', [], [], $entetes, json_encode(['endpoint' => $abonnement['endpoint']]));
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $repo->count(['empreinte' => PushAbonnement::empreinte($abonnement['endpoint'])]));
    }

    public function testEndpointNonHttpsRefuse(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $token = $client->request('GET', '/profil')->filter('body')->attr('data-push-token');
        $client->request('POST', '/notifications/abonnement', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['endpoint' => 'http://pirate.local/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']]));
        $this->assertResponseStatusCodeSame(400);
    }

    /** SSRF : seuls les services de notification des navigateurs sont acceptés. */
    public function testEndpointHorsServicesDeNotificationRefuse(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $token = $client->request('GET', '/profil')->filter('body')->attr('data-push-token');
        foreach (['https://127.0.0.1/admin', 'https://intranet.local/x', 'https://fcm.googleapis.com.pirate.net/x', 'https://fcm.googleapis.com:8443/x', 'https://user@fcm.googleapis.com/x'] as $endpoint) {
            $client->request('POST', '/notifications/abonnement', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => 'a', 'auth' => 'b']]));
            $this->assertResponseStatusCodeSame(400, $endpoint);
        }
        foreach (['https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $endpoint) {
            $this->assertTrue(\App\Controller\NotificationNavigateurController::endpointAutorise($endpoint), $endpoint);
        }
    }
}
