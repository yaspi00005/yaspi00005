<?php

namespace App\Tests\Controller;

use App\Entity\Colis;
use App\Enum\Destination;
use App\Enum\StatutColis;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Périmètre de chaque rôle : l'agent de ramassage ne voit que ses colis, le représentant les colis arrivés
 * dans son pays (avec leurs paiements), l'administrateur tout.
 */
final class DroitsRolesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function connecte(string $login): void
    {
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));
    }

    private function hid(Colis $c): string
    {
        return static::getContainer()->get(IdChiffreur::class)->encode($c->getId());
    }

    /** @param callable(Colis): bool $critere */
    private function colis(callable $critere): Colis
    {
        foreach (static::getContainer()->get(ColisRepository::class)->findBy([], ['id' => 'ASC']) as $c) {
            if ($critere($c)) {
                return $c;
            }
        }
        self::fail('Aucun colis de démonstration ne correspond.');
    }

    public function testAgentDeRamassageNeVoitQueSesColis(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);
        $autre = $this->colis(fn (Colis $c) => StatutColis::EnPreparation === $c->getStatut());
        $autre->setAgent($admin);
        $em->flush();
        $sien = $this->colis(fn (Colis $c) => 'ramassage' === $c->getAgent()?->getUsername());

        $this->connecte('ramassage');
        $this->client->request('GET', '/colis', ['tous' => 1]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('table', $autre->getNumeroSuivi());

        $this->client->request('GET', '/colis/'.$this->hid($autre));
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/colis/'.$this->hid($sien));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[action$="/encaisser"]');

        foreach (['/expeditions', '/paiements', '/encaissements'] as $page) {
            $this->client->request('GET', $page);
            $this->assertResponseStatusCodeSame(403, $page);
        }

        $crawler = $this->client->request('GET', '/');
        $this->assertSelectorTextContains('body', 'Mes performances');
        $this->assertSelectorTextNotContains('body', 'Performance des agents');
        $this->assertCount(0, $crawler->filter('a[href="/paiements"]'));
    }

    public function testRepresentantVoitLesColisArrivesDeSonPaysEtEncaisse(): void
    {
        $arrive = $this->colis(fn (Colis $c) => StatutColis::Arrive === $c->getStatut() && Destination::Mali === $c->getExpedition()?->getDestination());
        $enRoute = $this->colis(fn (Colis $c) => StatutColis::EnPreparation === $c->getStatut() && Destination::Mali === $c->getExpedition()?->getDestination());
        $senegal = $this->colis(fn (Colis $c) => Destination::Senegal === $c->getExpedition()?->getDestination());

        $this->connecte('bamako');
        $this->client->request('GET', '/colis', ['tous' => 1]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('table', $enRoute->getNumeroSuivi());
        $this->assertSelectorTextNotContains('table', $senegal->getNumeroSuivi());

        // Recherché par son numéro de suivi, un colis de son pays pas encore arrivé s'affiche (consultation seule).
        $this->client->request('GET', '/colis', ['q' => strtolower($enRoute->getNumeroSuivi())]);
        $this->assertSelectorTextContains('table', $enRoute->getNumeroSuivi());
        $this->client->request('GET', '/colis/'.$this->hid($enRoute));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[action$="/encaisser"]');
        $this->assertSelectorNotExists('form[action$="/remettre"]');
        $this->client->request('POST', '/colis/'.$this->hid($enRoute).'/encaisser');
        $this->assertResponseStatusCodeSame(403);
        // Une recherche partielle ne suffit pas, et les colis d'un autre pays restent invisibles.
        $this->client->request('GET', '/colis', ['q' => substr($enRoute->getNumeroSuivi(), 0, -1)]);
        $this->assertSelectorTextNotContains('table', $enRoute->getNumeroSuivi());
        $this->client->request('GET', '/colis', ['q' => $senegal->getNumeroSuivi()]);
        $this->assertSelectorTextNotContains('table', $senegal->getNumeroSuivi());
        $this->client->request('GET', '/colis/'.$this->hid($senegal));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/colis/'.$this->hid($arrive));
        $this->assertResponseIsSuccessful();
        // Reçu PDF téléchargeable par le représentant : montants en FCFA, sans le code de retrait.
        $this->client->request('GET', '/colis/'.$this->hid($arrive).'/recu.pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $html = static::getContainer()->get('twig')->render('pdf/recu.html.twig', [
            'colis' => $arrive, 'qr' => '', 'photos' => [], 'lien' => 'https://exemple.test', 'bonusGagne' => 0, 'fcfa' => true, 'masquerCode' => true,
        ]);
        $this->assertStringContainsString('FCFA', $html);
        $this->assertStringNotContainsString((string) $arrive->getCodeRetrait(), $html);
        $this->client->request('GET', '/encaissements', ['du' => '2000-01-01', 'export' => 'pdf']);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->client->request('GET', '/colis/'.$this->hid($arrive));

        // Montants en euros avec leur équivalent en francs CFA (1 € = 655,957 FCFA).
        $this->assertSelectorTextContains('.montant-fcfa', 'FCFA');
        $fcfa = number_format(round($arrive->getFrais() / 100 * 655.957), 0, ',', "\u{202F}");
        $this->assertStringContainsString($fcfa."\u{00A0}FCFA", (string) $this->client->getResponse()->getContent());
        if (!$arrive->isSolde()) {
            $this->assertSelectorExists('form[action$="/encaisser"]');
        }

        // Ni expéditions ni paiements globaux : sa propre vue « Mes encaissements ».
        foreach (['/expeditions', '/paiements'] as $page) {
            $this->client->request('GET', $page);
            $this->assertResponseStatusCodeSame(403, $page);
        }
        $this->client->request('GET', '/encaissements', ['du' => '2000-01-01']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', "Encaissé aujourd'hui");
        $this->assertSelectorTextContains('table', 'Orange Money', 'Les encaissements du représentant sont listés.');
        $this->assertSelectorTextNotContains('table', $enRoute->getNumeroSuivi());

        $this->client->request('GET', '/colis/nouveau');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Remis aux destinataires');
        $this->assertSelectorTextContains('body', 'Mes encaissements ce mois-ci');
        $this->assertSelectorTextNotContains('body', 'Expéditions récentes');
    }

    public function testAdministrateurVoitToutEtSesPerformances(): void
    {
        $this->connecte('admin');
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Mes performances');
        $this->assertSelectorTextContains('body', 'Performance des agents');
        $this->assertSelectorTextContains('body', 'Moussa TRAORÉ');

        // Guichet agence : montants aussi en francs CFA, même pour l'administrateur.
        $this->client->request('GET', '/agence');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'FCFA');

        $enRoute = $this->colis(fn (Colis $c) => StatutColis::EnPreparation === $c->getStatut() && !$c->isSolde());
        $this->client->request('GET', '/colis/'.$this->hid($enRoute));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action$="/encaisser"]');
        $this->assertSelectorNotExists('.montant-fcfa');
    }
}
