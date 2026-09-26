<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stocke les photos des articles hors du dossier public (var/uploads) :
 * elles ne sont servies qu'aux utilisateurs connectés via PhotoController.
 */
final class PhotoUploader
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private readonly string $uploadsDir)
    {
    }

    public function upload(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        if (!isset(self::TYPES[$mime])) {
            throw new \InvalidArgumentException('Format de photo non accepté (JPEG, PNG, WebP ou GIF).');
        }
        $nom = date('Y/m/').bin2hex(random_bytes(12)).'.'.self::TYPES[$mime];
        $file->move(\dirname($this->chemin($nom)), basename($nom));

        return $nom;
    }

    public function chemin(string $nom): string
    {
        if (str_contains($nom, '..')) {
            throw new \InvalidArgumentException('Nom de fichier invalide.');
        }

        return $this->uploadsDir.'/photos/'.$nom;
    }

    public function supprimer(?string $nom): void
    {
        if ($nom && is_file($chemin = $this->chemin($nom))) {
            @unlink($chemin);
        }
    }

    public function dataUri(?string $nom): ?string
    {
        if (!$nom || !is_file($chemin = $this->chemin($nom))) {
            return null;
        }

        return 'data:'.(mime_content_type($chemin) ?: 'image/jpeg').';base64,'.base64_encode((string) file_get_contents($chemin));
    }
}
