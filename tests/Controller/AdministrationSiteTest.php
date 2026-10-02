<?php

namespace App\Tests\Controller;

use App\Repository\NouveauteRepository;
use App\Repository\PartenaireRepository;
use App\Repository\PhotoSiteRepository;
use App\Repository\TemoignageClientRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Administration → Site internet : nouveautés, témoignages, partenaires affichés sur sotrama.net. */
final class AdministrationSiteTest extends WebTestCase
{
    private const APP = 'http://localhost';
    private KernelBrowser $client;
    /** @var list<string> */
    private array $temporaires = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
    }

    protected function tearDown(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $public = static::getContainer()->getParameter('kernel.project_dir').'/public/';
        foreach ($db->fetchFirstColumn('SELECT video FROM temoignage_client UNION SELECT apercu FROM temoignage_client UNION SELECT logo FROM partenaire UNION SELECT photo FROM photo_site') as $f) {
            if ($f && is_file($public.$f)) {
                unlink($public.$f);
            }
        }
        $db->executeStatement('DELETE FROM nouveaute');
        $db->executeStatement('DELETE FROM temoignage_client');
        $db->executeStatement('DELETE FROM partenaire');
        $db->executeStatement('DELETE FROM photo_site');
        array_map('unlink', array_filter($this->temporaires, 'is_file'));
        parent::tearDown();
    }

    public function testNouveautes(): void
    {
        $crawler = $this->client->request('GET', self::APP.'/admin/site/nouveautes/nouvelle');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), [
            'nouveaute[titre]' => 'Kayes, bientôt desservie', 'nouveaute[etiquette]' => 'bientot', 'nouveaute[icone]' => 'fa-map-marker-alt',
            'nouveaute[texte]' => 'Premier départ en janvier.', 'nouveaute[publiee]' => '1',
        ]);
        $this->assertResponseRedirects('/admin/site');
        $crawler = $this->client->request('GET', self::APP.'/admin/site/nouveautes/nouvelle');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), [
            'nouveaute[titre]' => 'Ancienne promo', 'nouveaute[etiquette]' => 'promo', 'nouveaute[publiee]' => '1',
            'nouveaute[dateFin]' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
        ]);
        $this->client->request('GET', self::APP.'/admin/site');
        $this->assertSelectorTextContains('body', 'Expirée');

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertSelectorTextContains('#nouveautes', 'Kayes, bientôt desservie');
        $this->assertSelectorTextContains('#nouveautes .nouveaute-etiquette', 'Bientôt');
        $this->assertSelectorTextNotContains('#nouveautes', 'Ancienne promo', 'Date de fin dépassée');

        // Masquée : disparaît du site.
        $kayes = static::getContainer()->get(NouveauteRepository::class)->findOneBy(['titre' => 'Kayes, bientôt desservie']);
        $crawler = $this->client->request('GET', self::APP.'/admin/site/nouveautes/'.$this->hid($kayes->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['nouveaute[publiee]']->untick();
        $this->client->submit($form);
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertSelectorNotExists('#nouveautes');
    }

    public function testTemoignageVideoEtPartenaire(): void
    {
        // Témoignage avec vidéo MP4.
        $crawler = $this->client->request('GET', self::APP.'/admin/site/temoignages/nouveau');
        $form = $crawler->selectButton('Enregistrer')->form(['temoignage_client[nom]' => 'Awa, Montreuil', 'temoignage_client[texte]' => 'Tout est arrivé.', 'temoignage_client[publie]' => '1']);
        $form['temoignage_client[videoFichier]']->upload($this->mp4());
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/site');
        $t = static::getContainer()->get(TemoignageClientRepository::class)->findOneBy(['nom' => 'Awa, Montreuil']);
        $this->assertMatchesRegularExpression('#^uploads/vitrine/temoignages/[a-f0-9]{20}\.mp4$#', $t->getVideo());

        // Témoignage texte seul.
        $crawler = $this->client->request('GET', self::APP.'/admin/site/temoignages/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['temoignage_client[nom]' => 'Moussa', 'temoignage_client[texte]' => 'Service rapide et sérieux.', 'temoignage_client[publie]' => '1']));

        // Partenaire : un SVG est refusé, un PNG accepté.
        $crawler = $this->client->request('GET', self::APP.'/admin/site/partenaires/nouveau');
        $form = $crawler->selectButton('Enregistrer')->form(['partenaire[nom]' => 'Boutique Test', 'partenaire[publie]' => '1']);
        $form['partenaire[logoFichier]']->upload($this->fichier('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'));
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'PNG, JPEG ou WebP');
        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form(['partenaire[nom]' => 'Boutique Test', 'partenaire[url]' => 'https://www.exemple.fr']);
        $form['partenaire[logoFichier]']->upload($this->png());
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/site');

        $this->client->request('GET', '/temoignages', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertSelectorExists('.temoignage video source[src*="uploads/vitrine/temoignages/"]');
        $this->assertSelectorTextContains('.temoignage-texte blockquote', 'Service rapide');
        $this->assertSelectorExists('#enseignes a[href="https://www.exemple.fr"] img[alt="Boutique Test"]');

        // Suppression : le fichier est retiré du serveur.
        $chemin = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$t->getVideo();
        $this->assertFileExists($chemin);
        $crawler = $this->client->request('GET', self::APP.'/admin/site/temoignages/'.$this->hid($t->getId()));
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());
        $this->assertFileDoesNotExist($chemin);
        $this->assertNull(static::getContainer()->get(PartenaireRepository::class)->findOneBy(['nom' => 'inexistant']));
    }

    public function testPhotosEnImages(): void
    {
        // Plusieurs photos d'un coup, même catégorie et même légende.
        $crawler = $this->client->request('GET', self::APP.'/admin/site/photos/nouvelles');
        $form = $crawler->selectButton('Enregistrer')->form(['photo_site[categorie]' => 'depart', 'photo_site[legende]' => 'Départ du conteneur pour Bamako', 'photo_site[publie]' => '1']);
        $valeurs = $form->getPhpValues();
        $fichiers = $form->getPhpFiles();
        $fichiers['photo_site']['photosFichiers'] = [$this->png(), $this->png()];
        $this->client->request('POST', self::APP.'/admin/site/photos/nouvelles', $valeurs, $fichiers);
        $this->assertResponseRedirects('/admin/site#photos');
        $photos = static::getContainer()->get(PhotoSiteRepository::class)->findAll();
        $this->assertCount(2, $photos);
        $this->assertMatchesRegularExpression('#^uploads/vitrine/galerie/[a-f0-9]{20}\.png$#', $photos[0]->getPhoto());

        // Un SVG est refusé.
        $fichiers['photo_site']['photosFichiers'] = [$this->fichier('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'photo.svg')];
        $this->client->request('POST', self::APP.'/admin/site/photos/nouvelles', $valeurs, $fichiers);
        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(2, static::getContainer()->get(PhotoSiteRepository::class)->findAll());

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertCount(2, $this->client->getCrawler()->filter('#galerie img[src*="uploads/vitrine/galerie/"]'));
        $this->assertSelectorTextContains('#galerie figcaption', 'Départ du conteneur pour Bamako');
        $this->assertSelectorTextContains('#galerie .galerie-categorie', 'Départ');

        // Masquée puis supprimée (le fichier est retiré du serveur).
        $crawler = $this->client->request('GET', self::APP.'/admin/site/photos/'.$this->hid($photos[0]->getId()));
        $form = $crawler->selectButton('Enregistrer')->form(['photo_site[categorie]' => 'chargement']);
        $form['photo_site[publie]']->untick();
        $this->client->submit($form);
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'sotrama.net']);
        $this->assertCount(1, $this->client->getCrawler()->filter('#galerie img[src*="uploads/vitrine/galerie/"]'));

        $chemin = static::getContainer()->getParameter('kernel.project_dir').'/public/'.$photos[1]->getPhoto();
        $this->assertFileExists($chemin);
        $crawler = $this->client->request('GET', self::APP.'/admin/site/photos/'.$this->hid($photos[1]->getId()));
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());
        $this->assertFileDoesNotExist($chemin);
    }

    public function testReserveAuxAdministrateurs(): void
    {
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $this->client->request('GET', self::APP.'/admin/site');
        $this->assertResponseStatusCodeSame(403);
    }

    private function hid(int $id): string
    {
        return static::getContainer()->get(IdChiffreur::class)->encode($id);
    }

    private function fichier(string $contenu, string $nom): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'site');
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function mp4(): UploadedFile
    {
        // En-tête « ftyp » : reconnu comme video/mp4.
        return $this->fichier("\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64), 'temoignage.mp4');
    }

    private function png(): UploadedFile
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);

        return $this->fichier((string) ob_get_clean(), 'logo.png');
    }
}
