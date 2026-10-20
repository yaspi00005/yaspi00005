<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Cloche « À traiter » de l'en-tête et récapitulatif affiché une fois après la connexion. */
final class ATraiterTest extends WebTestCase
{
    public function testRecapitulatifUneFoisApresLaConnexionPuisCloche(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => 'demo1234']));
        $client->followRedirect();
        // Données de démonstration : il y a toujours quelque chose à traiter (appels, colis arrivés…).
        $this->assertSelectorExists('#modal-a-traiter');
        $this->assertSelectorTextContains('#modal-a-traiter', 'voici ce qui vous attend');

        // Page suivante : plus de fenêtre, mais la cloche garde la liste.
        $client->request('GET', '/colis');
        $this->assertSelectorNotExists('#modal-a-traiter');
        $this->assertSelectorExists('.a-traiter-menu .dropdown-item');
        $this->assertSelectorExists('.a-traiter-badge');
    }

    public function testLeRepresentantNeVoitQueSesColisARemettre(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'bamako']));
        $client->request('GET', '/agence');
        $this->assertResponseIsSuccessful();
        $menu = $client->getCrawler()->filter('.a-traiter-menu')->text();
        $this->assertStringNotContainsString('Appel', $menu, 'Pas de registre des appels pour le représentant');
        $this->assertStringNotContainsString('ramassage en ligne', $menu);
    }
}
