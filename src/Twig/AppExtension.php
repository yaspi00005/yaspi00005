<?php

namespace App\Twig;

use App\Entity\Client;
use App\Security\IdChiffreur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%app.devise%')] private readonly string $devise,
        private readonly IdChiffreur $chiffreur,
        #[Autowire('%kernel.project_dir%/public/images/logo-sotrama.png')] private readonly string $cheminLogo,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hid', fn (?int $id) => null === $id ? '' : $this->chiffreur->encode($id)),
            // Logo de l'entreprise : public/images/logo-sotrama.png (facultatif, sinon icône par défaut).
            new TwigFunction('logo_existe', fn () => is_file($this->cheminLogo)),
            new TwigFunction('itineraire', $this->itineraire(...)),
            new TwigFunction('carte_osm', $this->carteOsm(...)),
            new TwigFunction('logo_data_uri', fn () => is_file($this->cheminLogo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->cheminLogo)) : null),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('poids', fn ($kg) => number_format((float) $kg, 2, ',', ' ').' kg'),
            new TwigFilter('jour_fr', fn (\DateTimeInterface $d) => \App\Util\DateFr::jour($d)),
            new TwigFilter('jour_heure_fr', fn (\DateTimeInterface $d) => \App\Util\DateFr::jourHeure($d)),
            new TwigFilter('tel', fn (?string $t) => $t ? \App\Util\Telephone::affichage($t) : ''),
        ];
    }

    /**
     * Lien de navigation (Google Maps ouvre l'application GPS du téléphone) vers le client :
     * position GPS si elle est connue, sinon l'adresse. $adresse remplace celle du client (adresse du ramassage).
     */
    public function itineraire(?Client $client, ?string $adresse = null, string $appli = 'google'): ?string
    {
        $adresse = trim((string) $adresse);
        if ('' === $adresse && $client?->hasPosition()) {
            $lat = $client->getLatitude();
            $lng = $client->getLongitude();

            return 'waze' === $appli
                ? 'https://waze.com/ul?ll='.$lat.','.$lng.'&navigate=yes'
                : 'https://www.google.com/maps/dir/?api=1&destination='.$lat.','.$lng;
        }
        if ('' === $adresse) {
            $adresse = (string) $client?->getAdresseComplete();
        } elseif ($client && !str_contains(mb_strtolower($adresse), mb_strtolower((string) $client->getVille()))) {
            $adresse .= ', '.trim($client->getCodePostal().' '.$client->getVille());
        }
        if ('' === trim($adresse)) {
            return null;
        }

        return 'waze' === $appli
            ? 'https://waze.com/ul?q='.rawurlencode($adresse).'&navigate=yes'
            : 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($adresse);
    }

    /** Carte OpenStreetMap intégrable (iframe) centrée sur un point. */
    public function carteOsm(?float $lat, ?float $lng): ?string
    {
        if (null === $lat || null === $lng) {
            return null;
        }
        $d = 0.004;

        return 'https://www.openstreetmap.org/export/embed.html?'.http_build_query([
            'bbox' => implode(',', [$lng - $d, $lat - $d / 2, $lng + $d, $lat + $d / 2]),
            'layer' => 'mapnik',
            'marker' => $lat.','.$lng,
        ]);
    }

    /** Formate un montant en centimes : 123450 → « 1 234,50 € ». */
    public function money(?int $centimes, bool $devise = true): string
    {
        $v = number_format(($centimes ?? 0) / 100, 2, ',', "\u{202F}");
        if (str_ends_with($v, ',00')) {
            $v = substr($v, 0, -3);
        }

        return $devise ? $v."\u{00A0}".$this->devise : $v;
    }
}
