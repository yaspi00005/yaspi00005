<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

/**
 * Villes correspondant à un code postal européen.
 *  - France : base officielle des communes (geo.api.gouv.fr) ;
 *  - autres pays : zippopotam.us.
 * Services gratuits, sans clé. Réponses mises en cache 30 jours ; en cas de panne, liste vide
 * (la ville reste saisissable à la main).
 */
final class CodePostal
{
    /** Pays proposés dans la fiche client => [code ISO, format du code postal]. */
    public const PAYS = [
        'France' => ['FR', '/^\d{5}$/'],
        'Belgique' => ['BE', '/^\d{4}$/'],
        'Espagne' => ['ES', '/^\d{5}$/'],
        'Italie' => ['IT', '/^\d{5}$/'],
        'Allemagne' => ['DE', '/^\d{5}$/'],
        'Portugal' => ['PT', '/^\d{4}-\d{3}$/'],
        'Pays-Bas' => ['NL', '/^\d{4}$/'],
        'Luxembourg' => ['LU', '/^\d{4}$/'],
        'Suisse' => ['CH', '/^\d{4}$/'],
        'Autriche' => ['AT', '/^\d{4}$/'],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Normalise la saisie : espaces retirés ; « L-1234 », « B-1000 »… ramenés au code seul. */
    public function normaliser(string $pays, string $codePostal): ?string
    {
        if (!isset(self::PAYS[$pays])) {
            return null;
        }
        $cp = strtoupper(preg_replace('/\s+/', '', $codePostal));
        $cp = preg_replace('/^[A-Z]{1,2}-(?=\d)/', '', $cp);
        if ('NL' === self::PAYS[$pays][0]) {
            $cp = substr($cp, 0, 4); // Pays-Bas : « 1012AB » → « 1012 »
        }

        return preg_match(self::PAYS[$pays][1], $cp) ? $cp : null;
    }

    /** @return list<string> villes triées (vide si code inconnu ou service indisponible) */
    public function villes(string $pays, string $codePostal): array
    {
        $cp = $this->normaliser($pays, $codePostal);
        if (null === $cp) {
            return [];
        }
        $iso = self::PAYS[$pays][0];

        return $this->cache->get('villes_'.$iso.'_'.$cp, function (ItemInterface $item) use ($iso, $cp) {
            try {
                $villes = 'FR' === $iso ? $this->france($cp) : $this->zippopotam($iso, $cp);
                $item->expiresAfter(30 * 86400);
            } catch (\Throwable $e) {
                $this->logger->warning('Recherche de ville par code postal indisponible : '.$e->getMessage());
                $item->expiresAfter(60); // on réessaiera bientôt
                $villes = [];
            }
            $villes = array_values(array_unique(array_filter(array_map('trim', $villes))));
            // Tri alphabétique français (« Évry » avec les E, pas après « Z »).
            \extension_loaded('intl') ? (new \Collator('fr_FR'))->sort($villes) : usort($villes, fn ($a, $b) => strcmp(u($a)->ascii()->lower()->toString(), u($b)->ascii()->lower()->toString()));

            return $villes;
        });
    }

    /**
     * Codes postaux d'une ville française (registre des appels : on tape la ville, le code postal se remplit).
     *
     * @return list<array{ville: string, cp: string}> communes les plus peuplées d'abord (vide si inconnue ou panne)
     */
    public function codesPostaux(string $ville): array
    {
        $nom = trim(preg_replace('/\s+/', ' ', $ville));
        if (mb_strlen($nom) < 2 || mb_strlen($nom) > 80) {
            return [];
        }

        return $this->cache->get('cp_'.md5(u($nom)->ascii()->lower()->toString()), function (ItemInterface $item) use ($nom) {
            try {
                $communes = $this->httpClient->request('GET', 'https://geo.api.gouv.fr/communes', [
                    'query' => ['nom' => $nom, 'fields' => 'nom,codesPostaux', 'boost' => 'population', 'limit' => 6, 'format' => 'json'],
                    'timeout' => 4,
                ])->toArray();
                $item->expiresAfter(30 * 86400);
            } catch (\Throwable $e) {
                $this->logger->warning('Recherche de code postal par ville indisponible : '.$e->getMessage());
                $item->expiresAfter(60);

                return [];
            }
            $resultats = [];
            foreach ($communes as $c) {
                foreach ($c['codesPostaux'] ?? [] as $cp) {
                    $resultats[] = ['ville' => (string) $c['nom'], 'cp' => (string) $cp];
                }
            }

            return \array_slice($resultats, 0, 30);
        });
    }

    /** @return list<string> */
    private function france(string $cp): array
    {
        $reponse = $this->httpClient->request('GET', 'https://geo.api.gouv.fr/communes', [
            'query' => ['codePostal' => $cp, 'fields' => 'nom', 'format' => 'json'],
            'timeout' => 4,
        ]);

        return array_column($reponse->toArray(), 'nom');
    }

    /** @return list<string> */
    private function zippopotam(string $iso, string $cp): array
    {
        $reponse = $this->httpClient->request('GET', 'https://api.zippopotam.us/'.strtolower($iso).'/'.rawurlencode($cp), ['timeout' => 4]);
        if (404 === $reponse->getStatusCode()) {
            return [];
        }

        return array_column($reponse->toArray()['places'] ?? [], 'place name');
    }
}
