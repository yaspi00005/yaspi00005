<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers du site vitrine envoyés depuis l'administration (vidéos des témoignages, aperçus, logos des partenaires, photos de la galerie) :
 * public/uploads/vitrine/<dossier>/, noms aléatoires. Pas de SVG : un SVG peut contenir du script et serait servi
 * depuis le même domaine que l'application.
 */
final class FichiersVitrine
{
    public const IMAGES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const VIDEOS = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

    public function __construct(#[Autowire('%kernel.project_dir%/public')] private readonly string $public)
    {
    }

    /**
     * @param array<string, string> $types type MIME accepté → extension
     *
     * @return string chemin relatif à public/ (pour asset())
     */
    public function enregistrer(UploadedFile $fichier, string $dossier, array $types): string
    {
        $extension = $types[$fichier->getMimeType()] ?? throw new \InvalidArgumentException('Format de fichier non accepté.');
        $relatif = 'uploads/vitrine/'.$dossier.'/'.bin2hex(random_bytes(10)).'.'.$extension;
        $fichier->move(\dirname($this->public.'/'.$relatif), basename($relatif));

        return $relatif;
    }

    public function supprimer(?string $relatif): void
    {
        if ($relatif && str_starts_with($relatif, 'uploads/vitrine/') && !str_contains($relatif, '..') && is_file($this->public.'/'.$relatif)) {
            @unlink($this->public.'/'.$relatif);
        }
    }

    /** Taille maximale d'envoi acceptée par le serveur (php.ini), pour l'afficher dans les formulaires. */
    public static function limiteEnvoi(): string
    {
        $valeurs = array_filter([\ini_get('upload_max_filesize'), \ini_get('post_max_size')]);
        $octets = array_map(function (string $v) {
            $n = (int) $v;

            return match (strtoupper(substr(trim($v), -1))) {
                'G' => $n * 1024 ** 3, 'M' => $n * 1024 ** 2, 'K' => $n * 1024, default => $n,
            };
        }, $valeurs);

        return $octets ? round(min($octets) / 1024 ** 2).' Mo' : 'inconnue';
    }
}
