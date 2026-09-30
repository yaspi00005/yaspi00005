<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ajoute la date de modification du fichier à son adresse (sotrama.js?v=1727512345) : après une mise à jour,
 * navigateurs et application mobile rechargent aussitôt les fichiers modifiés, sans vider leur cache.
 */
final class VersionParDate implements VersionStrategyInterface
{
    public function __construct(#[Autowire('%kernel.project_dir%/public')] private readonly string $public)
    {
    }

    public function getVersion(string $path): string
    {
        $fichier = $this->public.'/'.ltrim((string) parse_url($path, \PHP_URL_PATH), '/');

        return is_file($fichier) ? (string) filemtime($fichier) : '';
    }

    /** Adresses qui doivent rester fixes (manifeste de l'application installée, service worker). */
    private const FIXES = ['manifest.webmanifest', 'sw.js'];

    public function applyVersion(string $path): string
    {
        if (\in_array(basename($path), self::FIXES, true)) {
            return $path;
        }
        $version = $this->getVersion($path);

        return '' === $version || str_contains($path, '?') ? $path : $path.'?v='.$version;
    }
}
