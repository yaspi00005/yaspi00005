<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Position GPS d'une adresse postale.
 *  - France : Base Adresse Nationale (api-adresse.data.gouv.fr) ;
 *  - autres pays : OpenStreetMap (Nominatim).
 * Services gratuits, sans clé. Réponses mises en cache 30 jours ; en cas de panne, null.
 */
final class Geocodage
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{lat: float, lng: float, libelle: string, precision: string}|null */
    public function localiser(?string $adresse, ?string $codePostal, ?string $ville, ?string $pays): ?array
    {
        $adresse = trim(preg_replace('/\s+/', ' ', (string) $adresse));
        $codePostal = trim((string) $codePostal);
        $ville = trim((string) $ville);
        $pays = trim((string) $pays) ?: 'France';
        if ('' === $codePostal && '' === $ville) {
            return null;
        }

        $cle = 'geo_'.hash('xxh128', mb_strtolower(implode('|', [$adresse, $codePostal, $ville, $pays])));

        return $this->cache->get($cle, function (ItemInterface $item) use ($adresse, $codePostal, $ville, $pays) {
            try {
                $position = 'France' === $pays
                    ? $this->france($adresse, $codePostal, $ville)
                    : $this->nominatim($adresse, $codePostal, $ville, $pays);
                $item->expiresAfter(30 * 86400);
            } catch (\Throwable $e) {
                $this->logger->warning('Géocodage indisponible : '.$e->getMessage());
                $item->expiresAfter(60);
                $position = null;
            }

            return $position;
        });
    }

    private function france(string $adresse, string $codePostal, string $ville): ?array
    {
        $query = ['q' => trim($adresse.' '.$codePostal.' '.$ville), 'limit' => 1];
        if (preg_match('/^\d{5}$/', $codePostal)) {
            $query['postcode'] = $codePostal;
        }
        $reponse = $this->httpClient->request('GET', 'https://api-adresse.data.gouv.fr/search/', ['query' => $query, 'timeout' => 5]);
        $resultat = $reponse->toArray()['features'][0] ?? null;
        if (!$resultat || ($resultat['properties']['score'] ?? 0) < 0.4) {
            return null;
        }
        [$lng, $lat] = $resultat['geometry']['coordinates'];

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'libelle' => (string) ($resultat['properties']['label'] ?? ''),
            // housenumber / street = adresse précise ; municipality = centre de la commune.
            'precision' => \in_array($resultat['properties']['type'] ?? '', ['housenumber'], true) ? 'adresse' : 'approximative',
        ];
    }

    private function nominatim(string $adresse, string $codePostal, string $ville, string $pays): ?array
    {
        $reponse = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
            'query' => ['q' => implode(', ', array_filter([$adresse, trim($codePostal.' '.$ville), $pays])), 'format' => 'jsonv2', 'limit' => 1],
            'headers' => ['User-Agent' => 'SOTRAMA/1.0 (gestion de colis)', 'Accept-Language' => 'fr'],
            'timeout' => 5,
        ]);
        $resultat = $reponse->toArray()[0] ?? null;
        if (!$resultat) {
            return null;
        }

        return [
            'lat' => (float) $resultat['lat'],
            'lng' => (float) $resultat['lon'],
            'libelle' => (string) ($resultat['display_name'] ?? ''),
            'precision' => \in_array($resultat['addresstype'] ?? '', ['building', 'house', 'place'], true) ? 'adresse' : 'approximative',
        ];
    }
}
