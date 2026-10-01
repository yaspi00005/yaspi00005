<?php

namespace App\Tests\Controller;

use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Util\Csv;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Protections ajoutées lors de l'audit de sécurité. */
final class SecuriteTest extends WebTestCase
{
    /** Les tests qui désactivent un compte le réactivent (la base de test est partagée entre les tests). */
    protected function tearDown(): void
    {
        if (static::$booted) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $em->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'ramassage'])?->setActif(true);
            $em->flush();
        }
        parent::tearDown();
    }

    public function testEnTetesDeSecurite(): void
    {
        $client = static::createClient();
        foreach (['/connexion', '/suivi'] as $url) {
            $client->request('GET', $url);
            $h = $client->getResponse()->headers;
            $this->assertSame('nosniff', $h->get('X-Content-Type-Options'), $url);
            $this->assertSame('SAMEORIGIN', $h->get('X-Frame-Options'), $url);
            $this->assertStringContainsString("frame-ancestors 'self'", (string) $h->get('Content-Security-Policy'), $url);
            $this->assertStringContainsString("object-src 'none'", (string) $h->get('Content-Security-Policy'), $url);
            $this->assertStringContainsString('geolocation=(self)', (string) $h->get('Permissions-Policy'), $url);
        }
    }

    /** Scripts autorisés seulement s'ils portent le nonce de la page : ni 'unsafe-inline' ni 'unsafe-eval', ni onclick. */
    public function testCspSansScriptsInlineNonSignes(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $nonces = [];
        foreach (['/', '/admin/bonus/bareme'] as $url) {
            $crawler = $client->request('GET', $url);
            $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9_-]{20,})'/", $csp, $url);
            $this->assertStringNotContainsString('unsafe-eval', $csp, $url);
            $this->assertStringContainsString("script-src-attr 'none'", $csp, $url);
            preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $csp, $m);
            $nonces[] = $m[1];
            $crawler->filter('script:not([src])')->each(fn ($script) => $this->assertSame($m[1], $script->attr('nonce'), $url.' : script inline sans le nonce de la page.'));
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', (string) $client->getResponse()->getContent(), $url.' : attribut on… interdit.');
        }
        $this->assertNotSame($nonces[0], $nonces[1], 'Un nouveau nonce à chaque page.');
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*unsafe-inline/", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testCompteDesactivePendantLaSessionDeconnecte(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']);
        $client->loginUser($user);
        $client->request('GET', '/colis');
        $this->assertResponseIsSuccessful();

        $user->setActif(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('GET', '/colis');
        $this->assertResponseRedirects('/connexion');
    }

    public function testCompteDesactiveNonReveleSansLeBonMotDePasse(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']);
        $user->setActif(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'ramassage', '_password' => 'pas-le-bon']));
        $client->followRedirect();
        $this->assertSelectorTextNotContains('body', 'désactivé');
    }

    public function testMotDePasseTropFaibleRefuse(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $crawler = $client->request('GET', '/profil');
        foreach (['court1', 'azertyuiop'] as $faible) {
            $client->submit($crawler->selectButton('Enregistrer')->form([
                'mot_de_passe[actuel]' => 'demo1234', 'mot_de_passe[nouveau][first]' => $faible, 'mot_de_passe[nouveau][second]' => $faible,
            ]));
            $this->assertResponseStatusCodeSame(422, $faible);
        }
    }

    public function testDeconnexionSansJetonIgnoree(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $client->request('GET', '/deconnexion');
        $client->request('GET', '/colis');
        $this->assertResponseIsSuccessful('Un lien piégé ne peut pas déconnecter l\'utilisateur.');
    }

    public function testCodeRetraitMasquePourLeRepresentant(): void
    {
        $client = static::createClient();
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy(['statut' => 'arrive']);
        $representant = $colis->getExpedition()->getDestination()->value === 'mali' ? 'bamako' : 'dakar';
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $representant]));
        $id = static::getContainer()->get(IdChiffreur::class)->encode($colis->getId());

        $client->request('GET', "/colis/$id");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', $colis->getCodeRetrait());
        // Le représentant peut imprimer le reçu, mais le code de retrait y est masqué.
        $client->request('GET', "/colis/$id/recu.pdf");
        $this->assertResponseIsSuccessful();
        $html = static::getContainer()->get('twig')->render('pdf/recu.html.twig', [
            'colis' => $colis, 'qr' => '', 'photos' => [], 'lien' => 'https://exemple.test', 'bonusGagne' => 0, 'fcfa' => true, 'masquerCode' => true,
        ]);
        $this->assertStringNotContainsString((string) $colis->getCodeRetrait(), $html);
    }

    public function testCodeRetraitBloqueApresCinqErreurs(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // le compteur d'essais (cache en mémoire en test) doit survivre entre les requêtes
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy(['statut' => 'arrive']);
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $id = static::getContainer()->get(IdChiffreur::class)->encode($colis->getId());
        $crawler = $client->request('GET', "/colis/$id");
        $form = $crawler->selectButton('Remettre au destinataire')->form(['code' => 'ZZZZZZ']);
        for ($i = 0; $i < 5; ++$i) {
            $client->submit($form);
        }
        $client->submit($form->setValues(['code' => $colis->getCodeRetrait()]));
        $client->followRedirect();
        // Les messages des essais précédents peuvent s'afficher avant : on cherche parmi toutes les alertes.
        $alertes = implode(' ', $client->getCrawler()->filter('.alert-danger')->each(fn ($a) => $a->text()));
        $this->assertStringContainsString('Trop de codes erronés', $alertes);
    }

    public function testExportCsvSansFormule(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", Csv::cellule('=HYPERLINK("http://x")'));
        $this->assertSame("'+33 6 12", Csv::cellule('+33 6 12'));
        $this->assertSame('-12,50', Csv::cellule('-12,50'));
        $this->assertSame('Awa', Csv::cellule('Awa'));
        $this->assertSame(12, Csv::cellule(12));
    }
}
