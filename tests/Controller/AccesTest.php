<?php

namespace App\Tests\Controller;

use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Enum\Destination;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccesTest extends WebTestCase
{
    private function connecte(string $login): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));

        return $client;
    }

    public function testAnonymeRedirigeVersLaConnexion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/colis');
        $this->assertResponseRedirects('/connexion');
    }

    public function testConnexionParFormulaire(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => 'demo1234']));
        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertSelectorTextContains('.dashboard_bar', 'Tableau de bord');
    }

    public function testMauvaisMotDePasse(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => 'faux']));
        $client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }

    /** @return iterable<array{string, string, int}> */
    public static function matrice(): iterable
    {
        foreach (['/', '/colis', '/colis/nouveau', '/expeditions', '/clients', '/ramassages', '/paiements', '/agence', '/admin/utilisateurs', '/admin/marchandises', '/admin/notifications', '/profil'] as $url) {
            yield "admin $url" => ['admin', $url, 200];
        }
        yield 'ramassage peut saisir' => ['ramassage', '/colis/nouveau', 200];
        yield 'ramassage voit les clients' => ['ramassage', '/clients', 200];
        yield 'ramassage pas de guichet' => ['ramassage', '/agence', 403];
        yield 'ramassage pas d\'admin' => ['ramassage', '/admin/utilisateurs', 403];
        yield 'représentant guichet' => ['bamako', '/agence', 200];
        yield 'représentant pas de saisie' => ['bamako', '/colis/nouveau', 403];
        yield 'représentant pas de clients' => ['bamako', '/clients', 403];
        yield 'représentant pas de création d\'expédition' => ['bamako', '/expeditions/nouvelle', 403];
    }

    #[DataProvider('matrice')]
    public function testDroitsParRole(string $login, string $url, int $attendu): void
    {
        $this->connecte($login)->request('GET', $url);
        $this->assertResponseStatusCodeSame($attendu);
    }

    public function testRepresentantLimiteASonPays(): void
    {
        $client = $this->connecte('bamako');
        $expeditions = static::getContainer()->get(ExpeditionRepository::class);

        $client->request('GET', '/expeditions/'.$expeditions->findOneBy(['destination' => Destination::Senegal])->getId());
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/expeditions/'.$expeditions->findOneBy(['destination' => Destination::Mali])->getId());
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/colis');
        $this->assertStringNotContainsString('Sénégal</small>', (string) $client->getResponse()->getContent());
    }
}
