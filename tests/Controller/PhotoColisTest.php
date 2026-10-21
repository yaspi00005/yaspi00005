<?php

namespace App\Tests\Controller;

use App\Enum\StatutExpedition;
use App\Repository\ClientRepository;
use App\Repository\ColisRepository;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\PhotoUploader;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PhotoColisTest extends WebTestCase
{
    private function image(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'photo').'.png';
        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 161, 93));
        imagepng($img, $chemin);

        return new UploadedFile($chemin, 'colis.png', 'image/png', null, true);
    }

    public function testPhotoDuColisPriseALaSaisie(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));

        $crawler = $client->request('GET', '/colis/nouveau');
        $this->assertSelectorExists('.js-photo .js-photo-camera');
        $this->assertSelectorExists('input#colis_photoFile[accept="image/*"]');

        $valeurs = $crawler->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $valeurs['colis']['destinataireNom'] = 'Photo Test';
        $valeurs['colis']['destinataireTelephone'] = '76 00 00 01';
        $valeurs['colis']['expedition'] = static::getContainer()->get(ExpeditionRepository::class)->findOneBy(['statut' => StatutExpedition::EnPreparation])->getId();
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Divers', 'quantite' => '1', 'prix' => '10']];

        $client->request('POST', '/colis/nouveau', $valeurs, ['colis' => ['photoFile' => $this->image(), 'articles' => [['photoFile' => $this->image()]]]]);
        $this->assertResponseRedirects();

        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy(['destinataireNom' => 'Photo Test']);
        $this->assertNotNull($colis->getPhoto());
        $this->assertNotNull($colis->getArticles()->first()->getPhoto());
        $photos = static::getContainer()->get(PhotoUploader::class);
        $this->assertFileExists($photos->chemin($colis->getPhoto()));

        $id = static::getContainer()->get(IdChiffreur::class)->encode($colis->getId());
        $client->request('GET', "/colis/$id");
        $this->assertSelectorExists('img.photo-colis');
        $client->request('GET', "/colis/$id/photo");
        $this->assertResponseIsSuccessful();

        // Photo réservée aux utilisateurs connectés.
        $client->getCookieJar()->clear(); // déconnexion
        $client->request('GET', "/colis/$id/photo");
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'));

        $photos->supprimer($colis->getPhoto());
        $photos->supprimer($colis->getArticles()->first()->getPhoto());
    }

    public function testPlusieursPhotosSurLeColisEtLeRecu(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy([], ['id' => 'DESC']);
        $id = static::getContainer()->get(IdChiffreur::class)->encode($colis->getId());

        // Trois photos d'un coup depuis la fiche.
        $crawler = $client->request('GET', "/colis/$id");
        $form = $crawler->filter('form[action$="/photos"]')->form();
        $client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue()], ['photos' => [$this->image(), $this->image(), $this->image()]]);
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', '3 photo(s) ajoutée(s)');
        $colis = static::getContainer()->get(ColisRepository::class)->find($colis->getId());
        $this->assertCount(3, $colis->getPhotos());

        // Servies aux utilisateurs autorisés ; un fichier qui n'est pas une image est refusé.
        $photo = $colis->getPhotos()->first();
        $hidPhoto = static::getContainer()->get(IdChiffreur::class)->encode($photo->getId());
        $client->request('GET', "/photos-colis/$hidPhoto");
        $this->assertResponseIsSuccessful();
        $texte = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($texte, 'pas une image');
        $client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue()], ['photos' => [new UploadedFile($texte, 'a.png', 'image/png', null, true)]]);
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Envoyez des images');

        // Le reçu PDF les contient (plus lourd qu'un reçu sans photo).
        $client->request('GET', "/colis/$id/recu.pdf");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/Image', (string) $client->getResponse()->getContent());

        // Suppression d'une photo : fichier retiré du serveur.
        $chemin = static::getContainer()->get(PhotoUploader::class)->chemin($photo->getFichier());
        $this->assertFileExists($chemin);
        $crawler = $client->request('GET', "/colis/$id");
        $client->submit($crawler->filter('form[action$="/photos/'.$hidPhoto.'/supprimer"]')->form());
        $this->assertFileDoesNotExist($chemin);
        $this->assertCount(2, static::getContainer()->get(ColisRepository::class)->find($colis->getId())->getPhotos());
    }

    /** Pare-feu de l'hébergeur : photos envoyées en texte (base64) dans un envoi simple, retransformées en fichiers. */
    public function testPhotosEnvoyeesEnTexte(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $crawler = $client->request('GET', '/colis/nouveau');
        $valeurs = $crawler->selectButton('Enregistrer le colis')->form()->getPhpValues();
        $valeurs['colis']['client'] = static::getContainer()->get(ClientRepository::class)->findOneBy([])->getId();
        $valeurs['colis']['destinataireNom'] = 'Photo Texte';
        $valeurs['colis']['destinataireTelephone'] = '76 00 00 09';
        $valeurs['colis']['expedition'] = static::getContainer()->get(ExpeditionRepository::class)->findOneBy(['statut' => StatutExpedition::EnPreparation])->getId();
        $valeurs['colis']['typeExpedition'] = 'standard';
        $valeurs['colis']['articles'] = [['typeMarchandise' => 'Divers', 'quantite' => '1', 'prix' => '10']];
        $png = 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->image()->getPathname()));
        $valeurs['__photos'] = [
            json_encode(['champ' => 'colis[photoFile]', 'nom' => 'colis.png', 'data' => $png]),
            json_encode(['champ' => 'colis[photosFiles][]', 'nom' => 'a.png', 'data' => $png]),
            json_encode(['champ' => 'colis[photosFiles][]', 'nom' => 'b.png', 'data' => $png]),
            json_encode(['champ' => 'colis[articles][0][photoFile]', 'nom' => 'article.png', 'data' => $png]),
            // Rejetés : pas une image, nom de champ invalide.
            json_encode(['champ' => 'colis[photosFiles][]', 'nom' => 'x.php', 'data' => 'data:image/png;base64,'.base64_encode('<?php echo 1;')]),
            json_encode(['champ' => 'colis[a b]', 'nom' => 'c.png', 'data' => $png]),
        ];
        $client->request('POST', '/colis/nouveau', $valeurs);
        $this->assertResponseRedirects();

        $colis = static::getContainer()->get(ColisRepository::class)->findOneBy(['destinataireNom' => 'Photo Texte']);
        $this->assertNotNull($colis->getPhoto());
        $this->assertCount(2, $colis->getPhotos());
        $this->assertNotNull($colis->getArticles()->first()->getPhoto());
        $this->assertFileExists(static::getContainer()->get(PhotoUploader::class)->chemin($colis->getPhotos()->first()->getFichier()));
    }
}
