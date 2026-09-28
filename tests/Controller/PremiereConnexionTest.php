<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Première connexion avec le mot de passe provisoire (unique, généré à la création du compte et
 * affiché une seule fois à l'administrateur) : l'utilisateur doit le changer et indiquer son
 * adresse e-mail avant d'accéder au reste de l'application.
 */
final class PremiereConnexionTest extends WebTestCase
{
    public function testMotDePasseProvisoireEtEmailObligatoires(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // L'administrateur crée le compte avec le mot de passe provisoire (coché par défaut).
        $client->loginUser($container->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $crawler = $client->request('GET', '/admin/utilisateurs/nouveau');
        $form = $crawler->selectButton('Enregistrer')->form([
            'user[prenom]' => 'Nouvel', 'user[nom]' => 'Agent', 'user[username]' => 'nouvel.agent', 'user[rolePrincipal]' => User::ROLE_RAMASSAGE,
        ]);
        $this->assertTrue($form['user[provisoire]']->hasValue(), 'Mot de passe provisoire proposé par défaut.');
        $client->submit($form);
        $this->assertResponseRedirects('/admin/utilisateurs');

        // Le mot de passe provisoire généré est affiché une seule fois à l'administrateur.
        $crawler = $client->followRedirect();
        $this->assertMatchesRegularExpression('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $crawler->text());
        preg_match('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $crawler->text(), $m);
        $provisoire = $m[0];
        $client->restart(); // déconnexion

        // Connexion avec le mot de passe provisoire : toutes les pages renvoient vers le profil.
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'nouvel.agent', '_password' => $provisoire]));
        $client->request('GET', '/colis');
        $this->assertResponseRedirects('/profil');
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('.alert-warning', 'Changez le mot de passe provisoire');
        $this->assertSelectorTextContains('.alert-warning', 'Indiquez votre adresse e-mail');
        $client->request('GET', '/aide');
        $this->assertResponseIsSuccessful();

        // Un mot de passe trop simple est refusé.
        $client->submit($crawler->filter('form[name="mot_de_passe"]')->form([
            'mot_de_passe[actuel]' => $provisoire,
            'mot_de_passe[nouveau][first]' => 'azertyuiop',
            'mot_de_passe[nouveau][second]' => 'azertyuiop',
        ]));
        $this->assertResponseStatusCodeSame(422, 'Refusé (mot de passe trop simple).');

        $crawler = $client->request('GET', '/profil');
        $client->submit($crawler->filter('form[name="mot_de_passe"]')->form([
            'mot_de_passe[actuel]' => $provisoire,
            'mot_de_passe[nouveau][first]' => 'Colis-Bamako-2026!',
            'mot_de_passe[nouveau][second]' => 'Colis-Bamako-2026!',
        ]));
        $this->assertResponseRedirects('/profil');
        $client->request('GET', '/colis');
        $this->assertResponseRedirects('/profil', null, 'Il manque encore l\'adresse e-mail.');

        $crawler = $client->request('GET', '/profil');
        $client->submit($crawler->filter('form[name="email_profil"]')->form(['email_profil[email]' => 'pas-une-adresse']));
        $this->assertResponseStatusCodeSame(422);
        $crawler = $client->request('GET', '/profil');
        $client->submit($crawler->filter('form[name="email_profil"]')->form(['email_profil[email]' => ' Nouvel.Agent@Exemple.com ']));
        $this->assertResponseRedirects('/', null, 'Profil complété : retour au tableau de bord.');
        $client->request('GET', '/colis');
        $this->assertResponseIsSuccessful();

        $container->get(EntityManagerInterface::class)->clear();
        $user = $container->get(UserRepository::class)->findOneBy(['username' => 'nouvel.agent']);
        $this->assertFalse($user->isMotDePasseProvisoire());
        $this->assertSame('nouvel.agent@exemple.com', $user->getEmail());

        // Compte de test retiré : les autres tests comptent les agents de ramassage.
        $connexion = $container->get(EntityManagerInterface::class)->getConnection();
        $connexion->executeStatement('DELETE FROM journal WHERE utilisateur_id = ?', [$user->getId()]);
        $connexion->executeStatement('DELETE FROM utilisateur WHERE id = ?', [$user->getId()]);
    }
}
