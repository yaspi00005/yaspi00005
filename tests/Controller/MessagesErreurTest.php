<?php

namespace App\Tests\Controller;

use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Les champs vides ou mal saisis donnent un message clair, jamais une erreur technique. */
final class MessagesErreurTest extends WebTestCase
{
    private function connecte(string $login): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));

        return $client;
    }

    private function assertPasDeMessageTechnique(KernelBrowser $client): void
    {
        $html = (string) $client->getResponse()->getContent();
        foreach (['Argument attendu', 'Expected argument', 'chemin de propriété', 'property path'] as $technique) {
            $this->assertStringNotContainsString($technique, $html);
        }
    }

    public function testArticleSansPrixNiPoids(): void
    {
        $client = $this->connecte('ramassage');
        $valeurs = $client->request('GET', '/colis/nouveau')->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $valeurs['colis']['destinataireNom'] = 'Awa';
        $valeurs['colis']['destinataireTelephone'] = '76000000';
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Vêtements', 'quantite' => '1', 'prix' => '']];
        $client->request('POST', '/colis/nouveau', $valeurs);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', "Indiquez le prix de l'article.");
        $this->assertSelectorTextNotContains('body', 'Indiquez le poids', 'Poids facultatif');
        $this->assertPasDeMessageTechnique($client);

        // Avec un prix mais sans poids : le colis est enregistré (destinataire propre à ce test, sinon « doublon »).
        $valeurs['colis']['destinataireTelephone'] = '76 91 82 73';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Vêtements', 'quantite' => '1', 'prix' => '45']];
        $client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', '—');
        $this->assertSelectorTextContains('body', '45');
    }

    public function testPrixMalSaisi(): void
    {
        $client = $this->connecte('ramassage');
        $valeurs = $client->request('GET', '/colis/nouveau')->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Vêtements', 'quantite' => 'deux', 'prix' => 'abc']];
        $client->request('POST', '/colis/nouveau', $valeurs);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Saisissez un montant valide');
        $this->assertSelectorTextContains('body', 'Saisissez un nombre entier');
        $this->assertPasDeMessageTechnique($client);
    }

    public function testUtilisateurVide(): void
    {
        $client = $this->connecte('admin');
        $crawler = $client->request('GET', '/admin/utilisateurs/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form(), []);

        $this->assertSelectorTextContains('body', 'Indiquez le prénom.');
        $this->assertSelectorTextContains('body', "Indiquez l'identifiant de connexion.");
        $this->assertSelectorTextContains('body', 'Choisissez un rôle.');
        $this->assertPasDeMessageTechnique($client);
    }

    public function testClientVide(): void
    {
        $client = $this->connecte('ramassage');
        $crawler = $client->request('GET', '/clients/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form());

        $this->assertSelectorTextContains('body', 'Indiquez le nom du client.');
        $this->assertSelectorTextContains('body', 'Indiquez le téléphone du client.');
        $this->assertPasDeMessageTechnique($client);
    }

    public function testFenetreDesErreursPresente(): void
    {
        $this->connecte('ramassage')->request('GET', '/colis/nouveau');
        $this->assertSelectorExists('#modal-erreurs .js-erreurs-liste');
    }
}
