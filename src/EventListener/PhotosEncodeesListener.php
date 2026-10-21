<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Photos envoyées en texte (base64) dans un envoi simple.
 *
 * Le pare-feu de l'hébergeur bloque tout envoi « multipart/form-data » : le navigateur (sotrama.js) transforme donc
 * les photos choisies en champs texte « __photos[] » ({"champ": "colis[photoFile]", "nom": "…", "data": "data:…"}).
 * Ici, avant les contrôleurs, chaque photo redevient un fichier envoyé (UploadedFile) sous le nom de son champ
 * d'origine : les formulaires et contrôleurs fonctionnent comme avec un envoi de fichiers classique.
 */
final class PhotosEncodeesListener
{
    public const CHAMP = '__photos';
    private const MAX_PHOTOS = 30;
    private const MAX_OCTETS = 8 * 1024 * 1024; // par photo
    private const MAX_TOTAL = 60 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** @var list<string> fichiers temporaires à supprimer en fin de requête */
    private array $temporaires = [];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
    public function decoder(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !$request->request->has(self::CHAMP)) {
            return;
        }
        $encodees = $request->request->all(self::CHAMP);
        $request->request->remove(self::CHAMP);

        $requete = [];
        $fichiers = [];
        $total = 0;
        foreach (\array_slice($encodees, 0, self::MAX_PHOTOS) as $i => $json) {
            $photo = \is_string($json) ? json_decode($json, true) : null;
            $champ = \is_array($photo) ? (string) ($photo['champ'] ?? '') : '';
            // Nom de champ de formulaire : lettres, chiffres, _ et crochets (« colis[articles][0][photoFile] »).
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\[[A-Za-z0-9_]*\])*$/', $champ)
                || !preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#s', (string) ($photo['data'] ?? ''), $m)) {
                continue;
            }
            $octets = base64_decode($m[2], true);
            if (false === $octets || '' === $octets || \strlen($octets) > self::MAX_OCTETS || ($total += \strlen($octets)) > self::MAX_TOTAL) {
                continue;
            }
            // Type réel lu dans le contenu, pas celui annoncé.
            $type = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($octets) ?: '';
            if (!isset(self::TYPES[$type])) {
                continue;
            }
            $chemin = tempnam(sys_get_temp_dir(), 'photo');
            file_put_contents($chemin, $octets);
            $this->temporaires[] = $chemin;
            $nom = preg_replace('/[^\w.-]+/u', '_', (string) ($photo['nom'] ?? '')) ?: 'photo';
            $fichiers['__f'.$i] = new UploadedFile($chemin, $nom, $type, \UPLOAD_ERR_OK, true);
            $requete[] = $champ.'=__f'.$i;
        }
        if (!$fichiers) {
            return;
        }

        // « colis[photosFiles][]=__f1&colis[articles][0][photoFile]=__f2 » → tableau imbriqué, puis vrais fichiers.
        parse_str(implode('&', $requete), $arbre);
        array_walk_recursive($arbre, function (&$valeur) use ($fichiers) {
            $valeur = $fichiers[$valeur] ?? null;
        });
        $request->files->replace(array_replace_recursive($request->files->all(), $arbre));
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function nettoyer(TerminateEvent $event): void
    {
        foreach ($this->temporaires as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
        $this->temporaires = [];
    }
}
