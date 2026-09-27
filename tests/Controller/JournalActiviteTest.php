<?php

namespace App\Tests\Controller;

use App\Entity\Journal;
use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\JournalRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Journal d'activité : qui a ajouté, modifié, supprimé quoi. */
final class JournalActiviteTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function connecter(string $login): void
    {
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));
    }

    private function hid(int $id): string
    {
        return static::getContainer()->get(IdChiffreur::class)->encode($id);
    }

    private function dernier(): Journal
    {
        return static::getContainer()->get(JournalRepository::class)->findOneBy([], ['id' => 'DESC']);
    }

    public function testModificationAvecAvantApres(): void
    {
        $this->connecter('ramassage');
        $fiche = static::getContainer()->get(ClientRepository::class)->findOneBy([]);
        $ancienTel = $fiche->getTelephone();
        $crawler = $this->client->request('GET', '/clients/'.$this->hid($fiche->getId()).'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['client[telephone]' => '0799887766', 'client[ville]' => 'Nanterre']));
        $this->assertResponseRedirects();

        $j = $this->dernier();
        $this->assertSame(Journal::MODIFICATION, $j->getAction());
        $this->assertSame('Client', $j->getEntite());
        $this->assertSame($fiche->getId(), $j->getClientId());
        $this->assertStringContainsString('Moussa TRAORÉ (ramassage)', $j->getUtilisateurNom());
        $this->assertSame([$ancienTel, '0799887766'], $j->getChangements()['Téléphone']);
        $this->assertArrayNotHasKey('updatedAt', $j->getChangements());
    }

    public function testSuppressionEtAjout(): void
    {
        $this->connecter('admin');
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy(['statut' => 'en_preparation', 'montantPaye' => 0]);
        $numero = $colis->getNumeroSuivi();
        $id = $colis->getId();
        $crawler = $this->client->request('GET', '/colis/'.$this->hid($id));
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());

        $suppression = static::getContainer()->get(JournalRepository::class)->findOneBy(['action' => Journal::SUPPRESSION, 'entite' => 'Colis', 'entiteId' => $id]);
        $this->assertNotNull($suppression, 'La suppression du colis est journalisée.');
        $this->assertSame('Colis '.$numero, $suppression->getLibelle());
        $this->assertSame($id, $suppression->getColisId());
        $this->assertArrayHasKey('Destinataire', $suppression->getChangements());
        $this->assertNotNull(static::getContainer()->get(JournalRepository::class)->findOneBy(['action' => Journal::SUPPRESSION, 'entite' => 'Article', 'colisId' => $id]));
    }

    public function testConnexionsJournalisees(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => 'mauvais']));
        $this->assertSame(Journal::ECHEC_CONNEXION, $this->dernier()->getAction());
        $this->assertStringContainsString('« admin »', $this->dernier()->getLibelle());

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => 'demo1234']));
        $this->assertSame(Journal::CONNEXION, $this->dernier()->getAction());
        $this->assertSame('admin', $this->dernier()->getUtilisateur()?->getUsername());
    }

    public function testPagesReserveesAuxAdministrateurs(): void
    {
        $this->connecter('ramassage');
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([]);
        $this->client->request('GET', '/admin/journal');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/colis/'.$this->hid($colis->getId()));
        $this->assertSelectorTextNotContains('body', 'Historique des modifications');

        $this->connecter('admin');
        $this->client->request('GET', '/admin/journal', ['action' => 'creation']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.table-journal');
        $this->client->request('GET', '/colis/'.$this->hid($colis->getId()));
        $this->assertSelectorTextContains('body', 'Historique des modifications');
    }
}
