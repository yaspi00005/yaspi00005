<?php

namespace App\DataFixtures;

use App\Entity\Appel;
use App\Entity\Article;
use App\Entity\Camion;
use App\Entity\Client;
use App\Entity\Colis;
use App\Entity\Expedition;
use App\Entity\PalierBonus;
use App\Entity\Ramassage;
use App\Entity\TypeMarchandise;
use App\Entity\User;
use App\Enum\Destination;
use App\Enum\ModePaiement;
use App\Enum\ModeTransport;
use App\Enum\StatutExpedition;
use App\Enum\StatutRamassage;
use App\Service\BonusFidelite;
use App\Service\ColisService;
use App\Service\ExpeditionService;
use App\Service\PaiementService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de démonstration. Mot de passe de tous les comptes : « demo1234 ».
 * php bin/console doctrine:fixtures:load
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ColisService $colisService,
        private readonly ExpeditionService $expeditionService,
        private readonly PaiementService $paiementService,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(42);

        foreach (BonusFidelite::BAREME_INITIAL as [$seuil, $bonus]) {
            $manager->persist(new PalierBonus($seuil * 100, $bonus * 100));
        }
        $manager->flush();

        $admin = $this->user($manager, 'admin', 'Awa', 'Diallo', User::ROLE_ADMIN);
        $agent = $this->user($manager, 'ramassage', 'Moussa', 'Traoré', User::ROLE_RAMASSAGE);
        $mali = $this->user($manager, 'bamako', 'Fatoumata', 'Keïta', User::ROLE_REPRESENTANT, Destination::Mali);
        $this->user($manager, 'dakar', 'Ibrahima', 'Ndiaye', User::ROLE_REPRESENTANT, Destination::Senegal);

        $types = [];
        foreach (['Vêtements' => 800, 'Chaussures' => 900, 'Alimentaire' => 700, 'Électronique' => 1500, 'Cosmétiques' => 1000, 'Pièces auto' => 1200, 'Divers' => null] as $nom => $prix) {
            $manager->persist($types[] = (new TypeMarchandise())->setNom($nom)->setPrixUnitaire($prix));
        }

        $clients = [];
        $noms = [['Mamadou', 'Coulibaly', 'Montreuil'], ['Aïssata', 'Sissoko', 'Paris'], ['Oumar', 'Konaté', 'Saint-Denis'], ['Mariam', 'Touré', 'Aubervilliers'],
            ['Seydou', 'Camara', 'Évry'], ['Kadiatou', 'Bah', 'Créteil'], ['Boubacar', 'Diarra', 'Bobigny'], ['Rokia', 'Sangaré', 'Nanterre'],
            ['Cheikh', 'Fall', 'Mantes-la-Jolie'], ['Ndeye', 'Sow', 'Argenteuil'], ['Lassana', 'Dembélé', 'Villejuif'], ['Hawa', 'Doumbia', 'Ivry-sur-Seine']];
        foreach ($noms as $i => [$prenom, $nom, $ville]) {
            $c = (new Client())->setPrenom($prenom)->setNom($nom)->setVille($ville)->setCodePostal((string) (93100 + $i))
                ->setTelephone('06 '.sprintf('%02d %02d %02d %02d', 10 + $i, 20 + $i, 30 + $i, 40 + $i))
                ->setAdresse(($i + 3).' rue de la République')->setEmail(0 === $i % 3 ? mb_strtolower($prenom).'@example.com' : null);
            $manager->persist($c);
            $clients[] = $c;
        }
        $manager->flush();

        $this->paiementService->mouvementBonus($clients[0], 1500, 'Parrainage', $admin);
        $this->paiementService->mouvementBonus($clients[3], 1000, 'Geste commercial', $admin);

        $expeditions = [];
        foreach ([
            [ModeTransport::Aerien, Destination::Mali, '-40 days', StatutExpedition::Arrivee],
            [ModeTransport::Maritime, Destination::Senegal, '-25 days', StatutExpedition::EnTransit],
            [ModeTransport::Aerien, Destination::Mali, '-10 days', StatutExpedition::Arrivee],
            [ModeTransport::Aerien, Destination::Mali, '+5 days', StatutExpedition::EnPreparation],
            [ModeTransport::Maritime, Destination::Senegal, '+12 days', StatutExpedition::EnPreparation],
        ] as [$mode, $dest, $date, $statut]) {
            $e = (new Expedition())->setModeTransport($mode)->setDestination($dest)
                ->setDateDepart(new \DateTimeImmutable($date))
                ->setDateArriveePrevue(new \DateTimeImmutable($date.' +'.(ModeTransport::Aerien === $mode ? 3 : 21).' days'));
            $this->expeditionService->creer($e);
            $expeditions[] = [$e, $statut];
        }

        $destinataires = ['Adama Coulibaly', 'Fanta Sidibé', 'Ousmane Cissé', 'Binta Diallo', 'Modibo Keïta', 'Awa Ndiaye', 'Aminata Traoré', 'Souleymane Diop'];
        $n = 0;
        foreach ($expeditions as $k => [$expedition, $statut]) {
            $nb = 3 === $k ? 5 : 7;
            for ($i = 0; $i < $nb; ++$i, ++$n) {
                $colis = (new Colis())
                    ->setClient($clients[$n % \count($clients)])
                    ->setDestinataireNom($destinataires[$n % \count($destinataires)])
                    ->setDestinataireTelephone((Destination::Mali === $expedition->getDestination() ? '76 ' : '77 ').sprintf('%02d %02d %02d', 10 + $n, 30 + $n % 50, 50 + $n % 40))
                    ->setExpedition($expedition);
                for ($a = 0, $max = 1 + $n % 3; $a < $max; ++$a) {
                    $type = $types[($n + $a) % 6];
                    $poids = round(2 + (($n * 7 + $a * 3) % 25) + 0.5 * ($a % 2), 2);
                    $colis->addArticle((new Article())->setTypeMarchandise($type->getNom())->setPoidsVolume($poids)->setPrix((int) round($poids * $type->getPrixUnitaire())));
                }
                $this->colisService->creer($colis, $agent);
                // Étale les dates de réception sur les derniers mois.
                $colis->setDateReception(new \DateTimeImmutable('-'.(($n * 11) % 330).' days'));

                $reste = $colis->getMontantRestant();
                if (0 === $n % 3) {
                    $this->paiementService->encaisser($colis, $reste, ModePaiement::Especes, $agent);
                } elseif (1 === $n % 3) {
                    $this->paiementService->encaisser($colis, intdiv($reste, 2), ModePaiement::Carte, $agent);
                }
            }
            if (StatutExpedition::EnPreparation !== $statut) {
                $this->expeditionService->changerStatut($expedition, StatutExpedition::Expediee, $admin, false);
                $this->expeditionService->changerStatut($expedition, $statut, $admin, false);
            }
        }
        $manager->flush();

        // Quelques colis remis et un colis non affecté.
        foreach ($manager->getRepository(Colis::class)->findBy(['expedition' => $expeditions[0][0]], ['id' => 'ASC']) as $i => $colis) {
            if ($colis->isSolde() && $i < 4) {
                $this->colisService->remettre($colis, $mali, $colis->getDestinataireNom(), codePresente: $colis->getCodeRetrait());
            } elseif (!$colis->isSolde() && $i < 5) {
                $this->paiementService->encaisser($colis, $colis->getMontantRestant(), ModePaiement::OrangeMoney, $mali, 0, 'OM-'.(100000 + $i));
            }
        }
        $libre = (new Colis())->setClient($clients[2])->setDestinataireNom('Kalilou Sacko')->setDestinataireTelephone('76 44 55 66');
        $libre->addArticle((new Article())->setTypeMarchandise('Divers')->setPoidsVolume(4.5)->setPrix(4000)->setObservations('Carton scotché'));
        $this->colisService->creer($libre, $agent);

        foreach ([1, 2, 4, 8] as $j => $jours) {
            $manager->persist((new Ramassage())->setClient($clients[$j + 4])->setAgent($agent)
                ->setDatePrevue(new \DateTimeImmutable("+$jours days ".(9 + $j * 2).':00'))
                ->setCommentaire(0 === $j ? 'Sonner à l\'interphone B' : null));
        }
        $manager->persist((new Ramassage())->setClient($clients[1])->setAgent($agent)->setStatut(StatutRamassage::Effectue)->setDatePrevue(new \DateTimeImmutable('-3 days 14:00')));

        // Camions et registre des appels.
        $manager->persist($boxer = (new Camion())->setNom('Peugeot Boxer 2')->setVolume('13 m³')->setChauffeur($agent)->setCouleur('#F2994A'));
        $manager->persist((new Camion())->setNom('Sprinter')->setVolume('12 m³')->setChauffeur($admin)->setCouleur('#3A7BD5'));
        $manager->persist((new Camion())->setNom('20 m³')->setVolume('20 m³')->setCouleur('#8E44AD'));
        $prochaine = current(array_filter(array_map(fn ($x) => StatutExpedition::EnPreparation === $x[1] ? $x[0] : null, $expeditions)));
        foreach ([[3, true, $boxer, 25000, 'Une télé'], [5, false, null, 100000, 'Tracteur'], [0, false, null, 230000, 'Mètres linéaires bazar']] as [$i, $confirme, $camion, $estimation, $objet]) {
            $c = $clients[$i];
            $manager->persist((new Appel())->setUtilisateur($agent)->setClient($c)->setNom($c->getNom())->setPrenom($c->getPrenom())
                ->setTelephone($c->getTelephone())->setCodePostal($c->getCodePostal() ?? '93100')->setVille($c->getVille() ?? 'Montreuil')
                ->setAdresse($c->getAdresse())->setExpedition($prochaine)->setCamion($camion)
                ->setEstimation($estimation)->setCommentaire($objet)->setConfirme($confirme)->setDateAppel(new \DateTimeImmutable('-'.($i + 1).' days')));
        }
        $manager->flush();
    }

    private function user(ObjectManager $manager, string $login, string $prenom, string $nom, string $role, ?Destination $pays = null): User
    {
        $u = (new User())->setUsername($login)->setPrenom($prenom)->setNom($nom)->setRolePrincipal($role)->setPays($pays)->setEmail($login.'@example.com');
        $u->setPassword($this->hasher->hashPassword($u, 'demo1234'));
        $manager->persist($u);

        return $u;
    }
}
