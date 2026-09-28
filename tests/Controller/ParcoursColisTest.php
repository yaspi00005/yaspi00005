<?php

namespace App\Tests\Controller;

use App\Entity\Colis;
use App\Enum\StatutColis;
use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Enum\StatutExpedition;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Du dépôt en France à la remise au destinataire. */
final class ParcoursColisTest extends WebTestCase
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

    private function creerColis(): Colis
    {
        $this->connecte('ramassage');
        $clientId = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $expedition = static::getContainer()->get(ExpeditionRepository::class)->findOneBy(['statut' => StatutExpedition::EnPreparation]);

        $crawler = $this->client->request('GET', '/colis/nouveau');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer le colis')->form();
        $valeurs = $form->getPhpValues();
        $valeurs['colis']['client'] = $clientId;
        $valeurs['colis']['destinataireNom'] = 'Awa Test';
        $valeurs['colis']['destinataireTelephone'] = '76 11 22 33';
        $valeurs['colis']['expedition'] = $expedition->getId();
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [
            ['typeMarchandise' => 'Vêtements', 'poidsVolume' => '10', 'prix' => '80', 'observations' => ''],
            ['typeMarchandise' => 'Chaussures', 'poidsVolume' => '2,5', 'prix' => '22,50', 'observations' => 'Fragile'],
        ];
        $valeurs['confirmer_doublon'] = '1'; // les tests créent plusieurs fois le même colis
        $this->client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'enregistré');

        $id = static::getContainer()->get(IdChiffreur::class)->decode(preg_replace('#.*/colis/([A-Za-z0-9_-]{22}).*#', '$1', $this->client->getRequest()->getUri()));

        return static::getContainer()->get(ColisRepository::class)->find($id);
    }

    public function testCreationDUnColisAvecArticles(): void
    {
        $colis = $this->creerColis();

        $this->assertMatchesRegularExpression('/^ST\d{4}-\d{4}$/', $colis->getNumeroSuivi());
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $colis->getCodeRetrait());
        $this->assertSame(2, $colis->getNombreArticles());
        $this->assertSame(10250, $colis->getFrais(), 'Frais = somme des prix des articles (80 + 22,50).');
        $this->assertSame(12.5, $colis->getPoidsTotal());
        $this->assertSame(StatutColis::EnPreparation, $colis->getStatut());
        $this->assertCount(1, $colis->getHistorique());
    }

    public function testColisSansArticleRefuse(): void
    {
        $this->connecte('ramassage');
        $crawler = $this->client->request('GET', '/colis/nouveau');
        $valeurs = $crawler->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $valeurs['colis']['destinataireNom'] = 'X';
        $valeurs['colis']['destinataireTelephone'] = '76000000';
        $valeurs['colis']['typeExpedition'] = 'standard';
        unset($valeurs['colis']['articles']);
        $this->client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Ajoutez au moins un article.');
    }

    public function testDoubleEnvoiNeCreePasDeDoublon(): void
    {
        $this->connecte('ramassage');
        $avant = static::getContainer()->get(ColisRepository::class)->count([]);
        $crawler = $this->client->request('GET', '/colis/nouveau');
        $valeurs = $crawler->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $valeurs['colis']['destinataireNom'] = 'Double';
        $valeurs['colis']['destinataireTelephone'] = '76000000';
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Divers', 'poidsVolume' => '1', 'prix' => '10']];

        $this->client->request('POST', '/colis/nouveau', $valeurs);
        $this->client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertSame($avant + 1, static::getContainer()->get(ColisRepository::class)->count([]));
    }

    public function testEncaissementRemiseEtSuiviPublic(): void
    {
        $colis = $this->creerColis();
        $id = static::getContainer()->get(IdChiffreur::class)->encode($colis->getId());

        // L'agent de ramassage enregistre les colis mais n'encaisse pas.
        $crawler = $this->client->request('GET', "/colis/$id");
        $this->assertCount(0, $crawler->selectButton('Enregistrer le paiement'));
        $this->client->request('POST', "/colis/$id/encaisser");
        $this->assertResponseStatusCodeSame(403);

        // Admin : acompte, puis l'expédition arrive
        $this->connecte('admin');
        $crawler = $this->client->request('GET', "/colis/$id");
        $this->client->submit($crawler->selectButton('Enregistrer le paiement')->form(['encaissement[montant]' => '50']));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', '52,50');
        $expeditionId = static::getContainer()->get(IdChiffreur::class)->encode(static::getContainer()->get(ColisRepository::class)->find($colis->getId())->getExpedition()->getId());
        $crawler = $this->client->request('GET', "/expeditions/$expeditionId");
        $this->client->submit($crawler->selectButton('Mettre à jour')->form(['statut' => 'arrivee']));
        $colis = static::getContainer()->get(ColisRepository::class)->find($colis->getId());
        $this->assertSame(StatutColis::Arrive, $colis->getStatut());

        // Représentant : remise refusée tant que le colis n'est pas soldé
        $this->connecte('bamako');
        $crawler = $this->client->request('GET', "/colis/$id");
        $this->client->submit($crawler->selectButton('Remettre au destinataire')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'pas entièrement payé');

        // Encaissement du reste puis remise
        $crawler = $this->client->request('GET', "/colis/$id");
        $this->client->submit($crawler->selectButton('Enregistrer le paiement')->form(['encaissement[mode]' => 'orange_money', 'encaissement[reference]' => 'OM-1']));
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'soldé');
        // Code de retrait faux : remise refusée.
        $this->client->submit($crawler->selectButton('Remettre au destinataire')->form(['recuperePar' => 'Awa Test', 'code' => 'FAUX12']));
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Code de retrait incorrect');
        $this->assertSelectorTextNotContains('body', $colis->getCodeRetrait(), 'Le représentant ne voit pas le code.');
        $this->client->submit($crawler->selectButton('Remettre au destinataire')->form(['recuperePar' => 'Awa Test', 'code' => strtolower($colis->getCodeRetrait())]));
        $this->client->followRedirect();
        $colis = static::getContainer()->get(ColisRepository::class)->find($colis->getId());
        $this->assertSame(StatutColis::Recupere, $colis->getStatut());
        $this->assertSame('Awa Test', $colis->getRecupererPar());

        // Suivi public : numéro + téléphone
        $this->client->getCookieJar()->clear(); // déconnexion
        $crawler = $this->client->request('GET', '/suivi');
        $this->client->submit($crawler->selectButton('Voir mon colis')->form(['numero' => $colis->getNumeroSuivi(), 'telephone' => '00223 76 11 22 33']));
        $this->assertResponseRedirects('/suivi/'.$colis->getToken());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h3', $colis->getNumeroSuivi());
        $this->assertSelectorTextNotContains('body', $colis->getCodeRetrait());
    }

    public function testRecuPdf(): void
    {
        $colis = $this->creerColis();
        $this->client->request('GET', '/colis/'.static::getContainer()->get(IdChiffreur::class)->encode($colis->getId()).'/recu.pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }
}
