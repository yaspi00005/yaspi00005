<?php

namespace App\Tests\Controller;

use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Avant d'enregistrer un client ou un colis en double, l'utilisateur doit confirmer. */
final class DoublonsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
    }

    public function testClientAvecLeMemeTelephoneAutrementEcrit(): void
    {
        $existant = static::getContainer()->get(ClientRepository::class)->findOneBy([]);
        $chiffres = preg_replace('/\D/', '', $existant->getTelephone());
        $autreEcriture = '+33 '.substr($chiffres, 1, 1).' '.chunk_split(substr($chiffres, 2), 2, ' ');
        $nb = static::getContainer()->get(ClientRepository::class)->count([]);

        $crawler = $this->client->request('GET', '/clients/nouveau');
        $form = $crawler->filter('form[name="client"]')->form([
            'client[prenom]' => 'Autre', 'client[nom]' => 'Personne', 'client[telephone]' => trim($autreEcriture),
        ]);
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-warning', 'existe peut-être déjà');
        $this->assertSelectorTextContains('.alert-warning', $existant->getNomComplet());
        $this->assertSame($nb, static::getContainer()->get(ClientRepository::class)->count([]), 'Rien n\'est enregistré sans confirmation.');

        // « Créer quand même ».
        $this->client->submit($this->client->getCrawler()->selectButton('Créer quand même un nouveau client')->form());
        $this->assertResponseRedirects();
        $this->assertSame($nb + 1, static::getContainer()->get(ClientRepository::class)->count([]));
    }

    public function testClientAvecLesMemesNomsInverses(): void
    {
        $existant = static::getContainer()->get(ClientRepository::class)->findOneBy([]);
        $crawler = $this->client->request('GET', '/clients/nouveau');
        $this->client->submit($crawler->filter('form[name="client"]')->form([
            'client[prenom]' => mb_strtoupper($existant->getNom()), 'client[nom]' => mb_strtolower($existant->getPrenom()), 'client[telephone]' => '07 99 88 77 66',
        ]));
        $this->assertSelectorTextContains('.alert-warning', $existant->getNomComplet());
    }

    public function testColisEnDoubleSurTroisJours(): void
    {
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([], ['dateReception' => 'DESC']);
        $colis->setDateReception(new \DateTimeImmutable('-1 day'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->client->request('GET', '/colis/doublons.json', ['client' => $colis->getClient()->getId(), 'tel' => ' '.$colis->getDestinataireTelephone().' ']);
        $this->assertResponseIsSuccessful();
        $liste = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertContains($colis->getNumeroSuivi(), array_column($liste, 'numero'));

        $this->client->request('GET', '/colis/doublons.json', ['client' => $colis->getClient()->getId(), 'tel' => '70 00 00 00']);
        $this->assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true));

        // Sans le JavaScript : le serveur demande aussi confirmation.
        $crawler = $this->client->request('GET', '/colis/nouveau');
        $valeurs = $crawler->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = $colis->getClient()->getId();
        $valeurs['colis']['destinataireNom'] = 'Test Doublon';
        $valeurs['colis']['destinataireTelephone'] = $colis->getDestinataireTelephone();
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Vêtements', 'quantite' => '1', 'prix' => '40', 'observations' => '']];
        $this->client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-warning', $colis->getNumeroSuivi());

        $valeurs['confirmer_doublon'] = '1';
        $this->client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertResponseRedirects();
    }
}
