<?php

namespace App\Tests\Controller;

use App\Repository\ColisRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Site vitrine : servi sur sotrama.net, l'espace de gestion reste sur les autres domaines. */
final class VitrineTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_HOST' => 'sotrama.net']);
    }

    /** Bandeau « vie privée », pages légales, et aucune ressource tierce (polices hébergées sur le site). */
    public function testViePriveeEtPagesLegales(): void
    {
        $this->client->request('GET', '/');
        $this->assertSelectorExists('#bandeau-cookies [data-cookies-choix="accepte"]');
        $this->assertSelectorExists('#bandeau-cookies [data-cookies-choix="refuse"]');
        $this->assertSelectorExists('#bandeau-cookies a[href="/confidentialite"]');
        $this->assertSelectorExists('#bandeau-cookies a[href="/conditions-utilisation"]');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);

        foreach (['/confidentialite' => 'Politique de confidentialité', '/conditions-utilisation' => "Conditions d'utilisation", '/mentions-legales' => 'Mentions légales'] as $url => $titre) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $this->assertSelectorTextContains('h1', $titre);
        }
        $this->client->request('GET', '/confidentialite');
        $this->assertSelectorTextContains('#cookies', 'Cookies');
        $this->assertSelectorTextContains('body', 'SOTRAMA_SESSION');
        $this->assertSelectorTextContains('body', 'CNIL');

        // Le bandeau n'existe que sur le site vitrine, pas dans l'application.
        $this->client->request('GET', '/connexion', server: ['HTTP_HOST' => 'app.sotrama.net']);
        $this->assertSelectorNotExists('#bandeau-cookies');
    }

    public function testAccueilPublic(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bamako');
        $this->assertSelectorExists('a[href^="https://wa.me/33612336572"]');
        $this->assertSelectorExists('a[href="tel:+33650768300"]');
        $this->assertSelectorExists('a[href="https://www.tiktok.com/@sotrama1"]');
        $this->assertSelectorExists('form[action="/suivi"] input[name="numero"]');
        $this->assertSelectorExists('script[type="application/ld+json"]');
        // Pas de lien vers l'espace équipe sur le site public (l'adresse de l'application reste connue de l'équipe).
        $this->assertSelectorNotExists('a[href="https://app.sotrama.net/connexion"]');

        // www. aussi
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'www.sotrama.net']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bamako');
    }

    public function testEspaceDeGestionInchangeSurSonDomaine(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'app.sotrama.net']);
        $this->assertResponseRedirects('/connexion');
        $this->client->request('GET', '/suivi', server: ['HTTP_HOST' => 'app.sotrama.net']);
        $this->assertSelectorNotExists('.carte-suivi');
    }

    public function testPagesDeGestionRenvoyeesVersLApplication(): void
    {
        $this->client->request('GET', '/colis?statut=arrive');
        $this->assertResponseRedirects('https://app.sotrama.net/colis?statut=arrive');
        $this->client->request('GET', '/connexion');
        $this->assertResponseRedirects('https://app.sotrama.net/connexion');
    }

    public function testSuiviDUnColis(): void
    {
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([]);
        $telephone = $colis->getDestinataireTelephone();

        $crawler = $this->client->request('GET', '/suivi');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Voir où est mon colis')->form();

        $this->client->submit($form, ['numero' => $colis->getNumeroSuivi(), 'telephone' => '0000000000']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.formulaire-erreur', 'Aucun colis');

        // Numéro en minuscules et indicatif international : accepté.
        $this->client->submit($form, ['numero' => strtolower($colis->getNumeroSuivi()), 'telephone' => '+223 '.$telephone]);
        $this->assertResponseRedirects('/suivi/'.$colis->getToken());
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', $colis->getNumeroSuivi());
        $this->assertSelectorExists('.progression li[aria-current="step"]');
        $this->assertSelectorTextNotContains('body', $colis->getCodeRetrait());
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        $this->client->request('GET', '/suivi/'.str_repeat('a', 40));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRobotsSitemapEtMentions(): void
    {
        $this->client->request('GET', '/robots.txt');
        $this->assertStringContainsString('Sitemap: http://sotrama.net/sitemap.xml', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('<loc>http://sotrama.net/suivi</loc>', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mentions-legales');
        $this->assertSelectorTextContains('h1', 'Mentions légales');
    }
}
