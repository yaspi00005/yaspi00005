<?php

namespace App\Tests\Controller;

use App\Enum\ModeTransport;
use App\Enum\StatutColis;
use App\Enum\StatutExpedition;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\ExpeditionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Conteneur pour Bamako : arrivé au port de Dakar, puis transit par la route jusqu'à Bamako. */
final class EtapesDakarTest extends WebTestCase
{
    public function testConteneurPourBamakoPasseParDakar(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        // Expédition en préparation pour le Mali, passée en conteneur (maritime).
        $expedition = $container->get(ExpeditionRepository::class)->findOneBy(['statut' => StatutExpedition::EnPreparation, 'destination' => 'mali']);
        $expedition->setModeTransport(ModeTransport::Maritime);
        $em->flush();
        $colis = $container->get(ColisRepository::class)->findOneBy(['expedition' => $expedition]);
        $this->assertNotNull($colis, 'Données de démonstration : un colis dans cette expédition.');

        // La fiche de l'expédition propose les deux étapes.
        $client->loginUser($container->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $client->request('GET', '/expeditions/'.$container->get(IdChiffreur::class)->encode($expedition->getId()));
        $this->assertSelectorExists('select[name="statut"] option[value="arrivee_dakar"]');
        $this->assertSelectorExists('select[name="statut"] option[value="transit_bamako"]');

        $service = $container->get(ExpeditionService::class);
        $service->changerStatut($expedition, StatutExpedition::ArriveeDakar, null, false);
        $this->assertSame(StatutColis::ArriveDakar, $container->get(ColisRepository::class)->find($colis->getId())->getStatut());

        $service->changerStatut($expedition, StatutExpedition::TransitBamako, null, false);
        $colis = $container->get(ColisRepository::class)->find($colis->getId());
        $this->assertSame(StatutColis::TransitBamako, $colis->getStatut());

        // Suivi public : les étapes de Dakar apparaissent, la première est faite.
        $client->getCookieJar()->clear();
        $client->request('GET', '/suivi/'.$colis->getToken());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.suivi-etapes', 'Arrivé à Dakar');
        $faites = $client->getCrawler()->filter('.suivi-etapes .etape.faite')->each(fn ($e) => trim($e->text()));
        $this->assertContains('Arrivé à Dakar', $faites);
        $this->assertContains('Transit Dakar → Bamako', $faites);

        // Données de démonstration remises dans leur état (connexion du conteneur courant).
        $db = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $db->executeStatement("UPDATE expedition SET statut = 'en_preparation', mode_transport = 'aerien' WHERE id = ?", [$expedition->getId()]);
        $db->executeStatement("UPDATE colis SET statut = 'en_preparation' WHERE expedition_id = ?", [$expedition->getId()]);
    }

    public function testPasDEtapeDeDakarPourUnEnvoiParAvion(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $expedition = $container->get(ExpeditionRepository::class)->findOneBy(['modeTransport' => ModeTransport::Aerien]);
        $client->loginUser($container->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $client->request('GET', '/expeditions/'.$container->get(IdChiffreur::class)->encode($expedition->getId()));
        $this->assertSelectorNotExists('select[name="statut"] option[value="arrivee_dakar"]');
    }
}
