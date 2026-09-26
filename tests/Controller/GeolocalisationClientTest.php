<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\Geocodage;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeolocalisationClientTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
    }

    public function testLocalisationDeLAdresse(): void
    {
        $appels = [];
        static::getContainer()->set(Geocodage::class, new Geocodage(
            new MockHttpClient(function (string $methode, string $url) use (&$appels) {
                $appels[] = $url;

                return new MockResponse(json_encode(['features' => [[
                    'geometry' => ['coordinates' => [2.3522, 48.8566]],
                    'properties' => ['label' => '10 Rue de Rivoli 75004 Paris', 'score' => 0.92, 'type' => 'housenumber'],
                ]]]));
            }),
            new ArrayAdapter(),
            new NullLogger(),
        ));

        $this->client->request('GET', '/clients/geocoder.json', ['adresse' => '10 rue de Rivoli', 'cp' => '75004', 'ville' => 'Paris', 'pays' => 'France']);
        $this->assertResponseIsSuccessful();
        $position = json_decode((string) $this->client->getResponse()->getContent(), true)['position'];
        $this->assertSame(48.8566, $position['lat']);
        $this->assertSame(2.3522, $position['lng']);
        $this->assertSame('adresse', $position['precision']);
        $this->assertStringContainsString('api-adresse.data.gouv.fr', $appels[0]);
        $this->assertStringContainsString('postcode=75004', $appels[0]);

        // Sans code postal ni ville : pas d'appel.
        $this->client->request('GET', '/clients/geocoder.json', ['adresse' => 'quelque part']);
        $this->assertSame(['position' => null], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertCount(1, $appels);
    }

    public function testPositionEnregistreeAvecLaFiche(): void
    {
        $crawler = $this->client->request('GET', '/clients/nouveau');
        $this->assertSelectorExists('.js-geo .js-geo-gps');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'client[prenom]' => 'Awa', 'client[nom]' => 'Geo', 'client[telephone]' => '0611223344',
            'client[pays]' => 'France', 'client[codePostal]' => '75004', 'client[ville]' => 'Paris',
            'client[latitude]' => '48.8566123', 'client[longitude]' => '2.3522456', 'client[positionSource]' => 'gps',
        ]));
        $this->assertResponseRedirects();

        $client = static::getContainer()->get(ClientRepository::class)->findOneBy(['nom' => 'GEO']);
        $this->assertTrue($client->hasPosition());
        $this->assertSame(48.8566123, $client->getLatitude());
        $this->assertSame(Client::POSITION_GPS, $client->getPositionSource());
        $this->assertNotNull($client->getPositionMajLe());

        // La fiche propose l'itinéraire vers la position GPS et la carte.
        $this->client->followRedirect();
        $this->assertSelectorExists('a[href="https://www.google.com/maps/dir/?api=1&destination=48.8566123,2.3522456"]');
        $this->assertSelectorExists('iframe.geo-carte[data-src*="openstreetmap.org"]');
    }

    public function testPositionIncompleteOuInvalideRefusee(): void
    {
        $crawler = $this->client->request('GET', '/clients/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'client[prenom]' => 'Moitie', 'client[nom]' => 'Position', 'client[telephone]' => '0611223355',
            'client[latitude]' => '48.85', 'client[longitude]' => '',
        ]));
        $this->assertResponseRedirects();
        $client = static::getContainer()->get(ClientRepository::class)->findOneBy(['nom' => 'POSITION']);
        $this->assertFalse($client->hasPosition());
        $this->assertNull($client->getLatitude());

        $crawler = $this->client->request('GET', '/clients/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'client[prenom]' => 'Hors', 'client[nom]' => 'Carte', 'client[telephone]' => '0611223366',
            'client[latitude]' => '123', 'client[longitude]' => '2',
        ]));
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Latitude invalide.');
    }

    public function testJeSuisChezLeClient(): void
    {
        $client = static::getContainer()->get(ClientRepository::class)->findOneBy([]);
        $id = static::getContainer()->get(IdChiffreur::class)->encode($client->getId());
        $crawler = $this->client->request('GET', "/clients/$id");
        $fiche = $crawler->filter('.js-geo-fiche');
        $this->assertCount(1, $fiche);
        $url = $fiche->attr('data-url');
        $token = $fiche->attr('data-token');

        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'mauvais'], content: '{"lat":12.6,"lng":-8}');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"lat":"abc","lng":2}');
        $this->assertResponseStatusCodeSame(422);

        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"lat":43.6045,"lng":1.444}');
        $this->assertResponseIsSuccessful();
        $client = static::getContainer()->get(ClientRepository::class)->find($client->getId());
        $this->assertSame(43.6045, $client->getLatitude());
        $this->assertSame(Client::POSITION_GPS, $client->getPositionSource());

        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"effacer":true}');
        $this->assertResponseIsSuccessful();
        $this->assertFalse(static::getContainer()->get(ClientRepository::class)->find($client->getId())->hasPosition());
    }
}
