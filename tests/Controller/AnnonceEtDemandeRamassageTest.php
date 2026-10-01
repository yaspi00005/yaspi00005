<?php

namespace App\Tests\Controller;

use App\Entity\Expedition;
use App\Enum\StatutExpedition;
use App\Enum\StatutRamassage;
use App\Repository\ClientRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\RamassageRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Prochains départs annoncés sur sotrama.net et demandes de ramassage des visiteurs. */
final class AnnonceEtDemandeRamassageTest extends WebTestCase
{
    private const TELEPHONE = '07 98 76 54 32';
    /** Espace de gestion : URL absolue, sinon le navigateur de test reste sur le domaine de la vitrine. */
    private const APP = 'http://localhost';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        // Données créées par le test retirées : les autres tests partagent la même base.
        $db = static::getContainer()->get(Connection::class);
        $ids = $db->fetchFirstColumn("SELECT id FROM client WHERE telephone = ?", [self::TELEPHONE]);
        if ($ids) {
            $db->executeStatement('DELETE FROM ramassage WHERE client_id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM client WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $db->executeStatement('UPDATE expedition SET publiee_sur_site = 0, annonce = NULL, date_limite_depot = NULL');
        parent::tearDown();
    }

    public function testLAdministrateurPublieUnDepartSurLeSite(): void
    {
        $expedition = $this->departEnPreparation();
        $id = static::getContainer()->get(IdChiffreur::class)->encode($expedition->getId());

        // Rien d'annoncé tant que l'administrateur n'a pas publié.
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertSelectorNotExists('#departs');

        $this->connecter(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $crawler = $this->client->request('GET', self::APP.'/expeditions/'.$id.'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), [
            'expedition[annonce]' => 'Places limitées, réservez vite !',
            'expedition[dateLimiteDepot]' => $expedition->getDateDepart()->modify('-2 days')->format('Y-m-d'),
        ]);
        $crawler = $this->client->request('GET', self::APP.'/expeditions/'.$id);
        $this->client->submit($crawler->selectButton('Publier sur le site')->form());
        $this->client->followRedirect();
        $this->assertSelectorExists('button:contains("Retirer")');

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertSelectorTextContains('#departs', $expedition->getDestination()->ville());
        $this->assertSelectorTextContains('#departs .depart-annonce', 'Places limitées');
        $this->assertSelectorTextContains('#departs .depart-limite', 'Dépôt des colis');
        $this->assertSelectorExists('#departs a[href^="/ramassage?depart="]');

        // L'agent de ramassage ne peut pas publier.
        $this->connecter(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $this->client->request('POST', self::APP.'/expeditions/'.$id.'/publier');
        $this->assertResponseStatusCodeSame(403);

        // Retirée : n'apparaît plus.
        $this->connecter(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $crawler = $this->client->request('GET', self::APP.'/expeditions/'.$id);
        $this->client->submit($crawler->filter('form[action$="/publier"]')->form());
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertSelectorNotExists('#departs');
    }

    public function testUnVisiteurDemandeUnRamassageEtLEquipeLeValide(): void
    {
        $expedition = $this->departEnPreparation();
        $expedition->setPublieeSurSite(true);
        static::getContainer()->get('doctrine')->getManager()->flush();
        $hid = static::getContainer()->get(IdChiffreur::class)->encode($expedition->getId());

        $crawler = $this->client->request('GET', '/ramassage?depart='.$hid, server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alerte-info', $expedition->getDestination()->ville());
        $form = $crawler->selectButton('Envoyer ma demande')->form();
        $this->assertSame($expedition->getDestination()->value, $form['destination']->getValue());

        $jour = new \DateTimeImmutable('tomorrow');
        if ('7' === $jour->format('N')) {
            $jour = $jour->modify('+1 day');
        }
        $valeurs = [
            'prenom' => 'Fanta', 'nom' => 'Keita', 'telephone' => self::TELEPHONE, 'email' => '',
            'adresse' => '12 rue des Lilas', 'code_postal' => '69001', 'ville' => 'Lyon',
            'date' => $jour->format('Y-m-d'), 'creneau' => 'matin', 'colis' => '2 valises',
        ];

        // Hors Île-de-France et sans consentement : refusé.
        $this->client->submit($form, $valeurs);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.champ-erreur', 'Île-de-France');
        $this->assertSelectorTextContains('body', 'Veuillez accepter');

        $form = $this->client->getCrawler()->selectButton('Envoyer ma demande')->form();
        $form['consentement']->tick();
        $this->client->submit($form, ['code_postal' => '93200', 'ville' => 'Saint-Denis'] + $valeurs);
        $this->assertResponseRedirects('/ramassage/merci');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h2', 'Merci Fanta');
        $this->assertSelectorTextContains('.recap-demande', '93200 Saint-Denis');

        $client = static::getContainer()->get(ClientRepository::class)->findOneBy(['telephone' => self::TELEPHONE]);
        $this->assertNotNull($client, 'Nouvelle fiche client créée');
        $ramassage = static::getContainer()->get(RamassageRepository::class)->findOneBy(['client' => $client]);
        $this->assertSame(StatutRamassage::Demande, $ramassage->getStatut());
        $this->assertSame($jour->format('Y-m-d').' 09:00', $ramassage->getDatePrevue()->format('Y-m-d H:i'));
        $this->assertStringContainsString('2 valises', $ramassage->getCommentaire());
        $this->assertStringContainsString('départ du '.$expedition->getDateDepart()->format('d/m/Y'), $ramassage->getCommentaire());

        // Robot (champ piège rempli) : faux succès, rien d'enregistré.
        $this->client->request('POST', '/ramassage', ['site_web' => 'http://spam.example'] + $valeurs, server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertResponseRedirects('/ramassage/merci');
        $this->assertCount(1, static::getContainer()->get(RamassageRepository::class)->findBy(['client' => $client]));

        // L'équipe la voit dans le planning puis la valide après avoir appelé le client.
        $this->connecter(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $this->client->request('GET', self::APP.'/ramassages');
        $this->assertSelectorTextContains('.card.border-primary', 'KEITA');
        $id = static::getContainer()->get(IdChiffreur::class)->encode($ramassage->getId());
        $crawler = $this->client->request('GET', self::APP.'/ramassages/'.$id);
        $this->assertSelectorTextContains('.alert-primary', 'Demande reçue sur le site');
        $this->client->submit($crawler->selectButton('Valider le rendez-vous')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'validée');
        $valide = static::getContainer()->get(RamassageRepository::class)->find($ramassage->getId());
        $this->assertSame(StatutRamassage::Confirme, $valide->getStatut());
        $this->assertSame('ramassage', $valide->getAgent()?->getUserIdentifier());
    }

    public function testUnClientConnuNEstPasModifie(): void
    {
        $existant = static::getContainer()->get(ClientRepository::class)->findOneBy([]);
        $nom = $existant->getNom();
        $crawler = $this->client->request('GET', '/ramassage', server: ['HTTP_HOST' => 'sotrama.net']);
        $form = $crawler->selectButton('Envoyer ma demande')->form();
        $form['consentement']->tick();
        $jour = new \DateTimeImmutable('tomorrow');
        $this->client->submit($form, [
            'prenom' => 'Autre', 'nom' => 'Personne', 'telephone' => '+33 '.$existant->getTelephone(),
            'adresse' => '1 place de la Gare', 'code_postal' => '75010', 'ville' => 'Paris',
            'date' => ('7' === $jour->format('N') ? $jour->modify('+1 day') : $jour)->format('Y-m-d'), 'creneau' => 'soir', 'destination' => 'mali',
        ]);
        $this->assertResponseRedirects('/ramassage/merci');

        $existant = static::getContainer()->get(ClientRepository::class)->find($existant->getId());
        $this->assertSame($nom, $existant->getNom());
        $ramassage = static::getContainer()->get(RamassageRepository::class)->findOneBy(['client' => $existant, 'statut' => StatutRamassage::Demande]);
        $this->assertStringContainsString('Demandeur : Autre Personne', $ramassage->getCommentaire());
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM ramassage WHERE id = ?', [$ramassage->getId()]);
    }

    /** Connexion à l'espace de gestion (le cookie de session de la vitrine est d'abord oublié). */
    private function connecter(?\Symfony\Component\Security\Core\User\UserInterface $user): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
    }

    private function departEnPreparation(): Expedition
    {
        return static::getContainer()->get(ExpeditionRepository::class)->findOneBy(['statut' => StatutExpedition::EnPreparation], ['dateDepart' => 'ASC']);
    }
}
