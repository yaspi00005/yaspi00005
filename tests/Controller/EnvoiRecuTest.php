<?php

namespace App\Tests\Controller;

use App\Entity\Colis;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\RecuPartage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EnvoiRecuTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
    }

    private function colis(): Colis
    {
        return static::getContainer()->get(ColisRepository::class)->findOneBy([]);
    }

    private function ouvrir(Colis $colis): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', '/colis/'.static::getContainer()->get(IdChiffreur::class)->encode($colis->getId()));
    }

    public function testLienWhatsappAvecLeRecu(): void
    {
        $colis = $this->colis();
        $crawler = $this->ouvrir($colis);
        $this->assertResponseIsSuccessful();

        $lien = $crawler->filter('.carte-envoi-recu a.btn-whatsapp')->attr('href');
        $this->assertStringStartsWith('https://wa.me/', $lien);
        $texte = rawurldecode(parse_url($lien, \PHP_URL_QUERY));
        $this->assertStringContainsString($colis->getNumeroSuivi(), $texte);
        $this->assertMatchesRegularExpression('#/suivi/'.$colis->getToken().'/recu-[a-f0-9]{20}\.pdf#', $texte);

        // Destinataire : suivi, mais ni reçu ni code de retrait.
        $destinataire = rawurldecode(parse_url($crawler->filter('.carte-envoi-recu a.btn-outline-success')->attr('href'), \PHP_URL_QUERY));
        $this->assertStringContainsString('/suivi/'.$colis->getToken(), $destinataire);
        $this->assertStringNotContainsString('recu-', $destinataire);
        $this->assertStringNotContainsString($colis->getCodeRetrait(), $destinataire);
    }

    public function testRecuPublicSigne(): void
    {
        $colis = $this->colis();
        $signature = static::getContainer()->get(RecuPartage::class)->signature($colis);
        $this->client->getCookieJar()->clear(); // déconnexion

        $this->client->request('GET', "/suivi/{$colis->getToken()}/recu-$signature.pdf");
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', "/suivi/{$colis->getToken()}/recu-".str_repeat('0', 20).'.pdf');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testEnvoiParEmail(): void
    {
        $colis = $this->colis();
        $colis->getClient()->setEmail(null);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->ouvrir($colis);
        $this->assertSelectorNotExists('form[action$="/recu/email"]');

        $colis->getClient()->setEmail('client@example.com');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->ouvrir($colis);
        $this->client->submit($crawler->filter('form[action$="/recu/email"]')->form());
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', 'client@example.com');
        $this->assertEmailAttachmentCount($email, 1);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'client@example.com');
    }
}
