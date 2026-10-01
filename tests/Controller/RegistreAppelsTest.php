<?php

namespace App\Tests\Controller;

use App\Entity\Appel;
use App\Enum\StatutRamassage;
use App\Repository\AppelRepository;
use App\Repository\CamionRepository;
use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Registre des appels → planning des ramassages (camions, chauffeurs, « À planifier »). */
final class RegistreAppelsTest extends WebTestCase
{
    private const TELEPHONE = '07 58 31 92 46';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        $db = static::getContainer()->get(Connection::class);
        if ($ids = $db->fetchFirstColumn('SELECT id FROM client WHERE telephone = ?', [self::TELEPHONE])) {
            $db->executeStatement('DELETE FROM appel WHERE client_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM ramassage WHERE client_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM client WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        parent::tearDown();
    }

    public function testAppelConfirmeAvecDateDevientUnRamassageDuCamion(): void
    {
        $this->connecter('ramassage');
        $boxer = static::getContainer()->get(CamionRepository::class)->findOneBy(['nom' => 'Peugeot Boxer 2']);

        $crawler = $this->client->request('GET', '/appels');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.registre-onglets', 'Peugeot Boxer 2');
        $this->assertSelectorTextContains('.registre-onglets', '👤 Moussa');
        $jour = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $this->client->submit($crawler->selectButton("Enregistrer l'appel")->form(), [
            'appel[nom]' => 'Traoré', 'appel[prenom]' => 'Mamadou', 'appel[telephone]' => self::TELEPHONE,
            'appel[codePostal]' => '77184', 'appel[ville]' => 'Émerainville', 'appel[adresse]' => '2 allée des Belles Histoires',
            'appel[estimation]' => '250', 'appel[commentaire]' => 'Une télé',
            'appel[camion]' => (string) $boxer->getId(), 'appel[dateRamassage]' => $jour.'T14:30', 'appel[confirme]' => '1',
        ]);
        $this->assertResponseRedirects('/appels');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Ramassage au planning le');
        $this->assertSelectorTextContains('#registre-liste', 'Mamadou TRAORÉ');

        $appel = $this->appel();
        $this->assertSame(25000, $appel->getEstimation());
        $this->assertNotNull($appel->getClient()->getId(), 'Fiche client créée');
        $this->assertSame('Émerainville', $appel->getClient()->getVille());
        $ramassage = $appel->getRamassage();
        $this->assertSame(StatutRamassage::Confirme, $ramassage->getStatut());
        $this->assertSame($jour.' 14:30', $ramassage->getDatePrevue()->format('Y-m-d H:i'));
        $this->assertSame('Peugeot Boxer 2', $ramassage->getCamion()->getNom());
        $this->assertSame('ramassage', $ramassage->getAgent()->getUserIdentifier(), 'Chauffeur du camion');
        $this->assertStringContainsString('Une télé', $ramassage->getCommentaire());
        $this->assertStringContainsString('77184 Émerainville', $ramassage->getAdresse());

        // Planning : couleur et filtre du camion.
        $hidCamion = static::getContainer()->get(IdChiffreur::class)->encode($boxer->getId());
        $this->client->request('GET', '/ramassages/evenements.json', ['start' => $jour, 'end' => (new \DateTimeImmutable($jour.' +1 day'))->format('Y-m-d'), 'camion' => $hidCamion]);
        $evenements = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, array_filter($evenements, fn ($e) => str_contains($e['title'], 'TRAORÉ')));
        $this->assertSame('#F2994A', $evenements[array_key_first(array_filter($evenements, fn ($e) => str_contains($e['title'], 'TRAORÉ')))]['borderColor']);

        // Feuille de route du camion.
        $this->client->request('GET', '/ramassages/export.pdf', ['camion' => $hidCamion, 'du' => $jour, 'au' => $jour]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testConfirmeSansDateAPlanifierPuisDateAjouteeEtRetiree(): void
    {
        $this->connecter('ramassage');
        $crawler = $this->client->request('GET', '/appels');
        $this->client->submit($crawler->selectButton("Enregistrer l'appel")->form(), [
            'appel[nom]' => 'Sissoko', 'appel[prenom]' => 'Fanta', 'appel[telephone]' => self::TELEPHONE,
            'appel[codePostal]' => '94240', 'appel[ville]' => "L'Haÿ-les-Roses", 'appel[confirme]' => '1',
        ]);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'À planifier');

        $this->client->request('GET', '/ramassages');
        $this->assertSelectorTextContains('.card.border-warning', 'Fanta SISSOKO');

        $this->client->request('GET', '/appels', ['vue' => 'a_planifier']);
        $this->assertSelectorTextContains('#registre-liste', 'Fanta SISSOKO');

        // La date est fixée : le ramassage arrive au planning.
        $id = static::getContainer()->get(IdChiffreur::class)->encode($this->appel()->getId());
        $crawler = $this->client->request('GET', '/appels/'.$id);
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), ['appel[dateRamassage]' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d').'T10:00']);
        $ramassageId = $this->appel()->getRamassage()?->getId();
        $this->assertNotNull($ramassageId);

        // Remis en attente depuis le tableau : le ramassage aussi.
        $crawler = $this->client->request('GET', '/appels', ['q' => '0758319246']);
        $this->assertSelectorTextContains('#registre-liste', 'Fanta SISSOKO', 'Recherche par téléphone sans espaces');
        $this->client->submit($crawler->filter('#registre-liste form[action$="/confirmer"]')->form());
        $this->assertFalse($this->appel()->isConfirme());
        $this->assertSame(StatutRamassage::EnAttente, $this->appel()->getRamassage()->getStatut());

        // Date effacée : le rendez-vous à faire disparaît du planning.
        $crawler = $this->client->request('GET', '/appels/'.$id);
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), ['appel[dateRamassage]' => '']);
        $this->assertNull($this->appel()->getRamassage());
        $this->assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM ramassage WHERE id = ?', [$ramassageId]));
    }

    public function testClientConnuExportEtDroits(): void
    {
        $this->connecter('ramassage');
        $connu = static::getContainer()->get(ClientRepository::class)->findOneBy([]);
        $this->client->request('GET', '/appels/client.json', ['telephone' => '+33 '.ltrim($connu->getTelephone(), '0')]);
        $this->assertSame($connu->getNom(), json_decode((string) $this->client->getResponse()->getContent(), true)['client']['nom']);

        $this->client->request('GET', '/appels', ['export' => 'csv']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));

        // Un appel pris par quelqu'un d'autre : l'agent ne peut pas le supprimer, l'administrateur oui.
        $appel = static::getContainer()->get(AppelRepository::class)->findOneBy([]);
        $hid = static::getContainer()->get(IdChiffreur::class)->encode($appel->getId());
        static::getContainer()->get(Connection::class)->executeStatement('UPDATE appel SET utilisateur_id = (SELECT id FROM utilisateur WHERE username = ?) WHERE id = ?', ['admin', $appel->getId()]);
        $this->client->request('POST', '/appels/'.$hid.'/supprimer');
        $this->assertResponseStatusCodeSame(403);

        // Le représentant pays n'a pas accès au registre.
        $this->connecter('bamako');
        $this->client->request('GET', '/appels');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDemandeDuSiteDansLeRegistre(): void
    {
        $crawler = $this->client->request('GET', '/ramassage', server: ['HTTP_HOST' => 'sotrama.net']);
        $form = $crawler->selectButton('Envoyer ma demande')->form();
        $form['consentement']->tick();
        $jour = new \DateTimeImmutable('tomorrow');
        $this->client->submit($form, [
            'prenom' => 'Awa', 'nom' => 'Keita', 'telephone' => self::TELEPHONE, 'adresse' => '3 rue Jean Jaurès',
            'code_postal' => '93200', 'ville' => 'Saint-Denis', 'destination' => 'mali', 'creneau' => 'matin',
            'date' => ('7' === $jour->format('N') ? $jour->modify('+1 day') : $jour)->format('Y-m-d'), 'colis' => '3 cartons',
        ]);
        $this->assertResponseRedirects('/ramassage/merci');

        $appel = $this->appel();
        $this->assertSame(Appel::SOURCE_SITE, $appel->getSource());
        $this->assertFalse($appel->isConfirme());
        $this->assertSame(StatutRamassage::Demande, $appel->getRamassage()->getStatut());

        // Validée dans le planning : l'appel passe à « confirmé ».
        $this->client->getCookieJar()->clear();
        $this->connecter('ramassage');
        $hid = static::getContainer()->get(IdChiffreur::class)->encode($appel->getRamassage()->getId());
        $crawler = $this->client->request('GET', 'http://localhost/ramassages/'.$hid);
        $this->client->submit($crawler->selectButton('Valider le rendez-vous')->form());
        $this->assertTrue($this->appel()->isConfirme());

        $this->client->request('GET', 'http://localhost/appels');
        $this->assertSelectorExists('#registre-liste .badge[title="Demande faite sur sotrama.net"]');
    }

    private function connecter(string $login): void
    {
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));
    }

    private function appel(): Appel
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $client = static::getContainer()->get(ClientRepository::class)->findOneBy(['telephone' => self::TELEPHONE]);

        return static::getContainer()->get(AppelRepository::class)->findOneBy(['client' => $client]);
    }
}
