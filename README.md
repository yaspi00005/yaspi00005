# GESCO Marchandises — v2

Application de gestion d'envoi de colis France → Mali / Sénégal : ramassage chez le client,
expéditions aériennes et maritimes, encaissements, bonus fidélité, guichet de retrait en agence
et suivi en ligne pour les destinataires.

Réécriture complète de l'ancienne application (Symfony 7.4, PHP 8.2+, MySQL 8 / MariaDB),
avec l'interface du thème **Workload** (ThemeForest).

## Fonctionnalités

| Module | Pour qui | Ce qu'on y fait |
|---|---|---|
| Tableau de bord | tous | chiffres clés, graphiques (colis par mois, encaissements, statuts), derniers colis |
| Colis | tous (filtré par pays pour les représentants) | recherche et filtres, saisie multi-articles avec photos et prix suggéré, reçu PDF avec QR code, export Excel |
| Clients | ramassage, admin | fiche client, historique des colis, compte bonus fidélité |
| Ramassages | ramassage, admin | calendrier (glisser-déposer), rendez-vous à venir |
| Expéditions | tous (admin pour modifier) | création, affectation des colis, changement de statut répercuté sur tous les colis + SMS, manifeste Excel |
| Guichet agence | représentants, admin | recherche par code de retrait / n° de suivi / scan QR (caméra ou douchette), encaissement, remise au destinataire |
| Paiements | tous (filtré par pays) | journal filtrable, totaux par caissier et par mode, export Excel, annulation (admin) |
| Suivi public | destinataires, sans compte | `/suivi` : n° de suivi + téléphone, ou lien du QR code |
| Administration | admin | utilisateurs et rôles, types de marchandises et tarifs au kg, journal des SMS / e-mails |

Rôles : **Administrateur**, **Agent de ramassage** (France), **Représentant** (agence, rattaché à un pays).

### Améliorations par rapport à l'ancienne version

- Montants stockés en centimes, calculs centralisés (`PaiementService`) : plus de sur-encaissement possible, remises et bonus cohérents, annulation d'un paiement qui restitue le bonus.
- Statuts normalisés (énumérations) et historique horodaté de chaque colis.
- Remise au destinataire bloquée tant que le colis n'est pas soldé (forçage possible par un admin).
- Anti double-clic : un formulaire de colis ne peut créer qu'un seul colis.
- Photos stockées hors du dossier public, visibles uniquement par les utilisateurs autorisés.
- Lien de suivi non devinable ; le code de retrait n'apparaît jamais sur la page publique.
- SMS Orange avec vérification TLS active et jeton mis en cache ; chaque envoi est journalisé.
- Connexion limitée à 5 essais / 15 min, comptes désactivables, protections CSRF partout.
- Bugs de l'ancienne version corrigés (relations Doctrine cassées, recherche en erreur, route en double…).

## Installation

Prérequis : PHP 8.2+ (extensions `intl`, `gd`, `pdo_mysql`), Composer, MySQL 8 ou MariaDB 10.6+.

```bash
composer install
cp .env .env.local            # puis renseigner DATABASE_URL, MAILER_DSN, APP_SECRET…
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:installer-theme /chemin/vers/WorkLoad-v1.2-28-Octuber-2022/xhtml
php bin/console app:creer-admin monidentifiant
```

Le thème Workload est sous licence ThemeForest : il **n'est pas versionné** (dossier `public/theme/`
ignoré par git). La commande `app:installer-theme` copie uniquement les fichiers nécessaires depuis
l'archive achetée.

En développement : `symfony serve` (ou `php -S 127.0.0.1:8000 -t public`).
Données de démonstration : `php bin/console doctrine:fixtures:load` (comptes `admin`, `ramassage`,
`bamako`, `dakar`, mot de passe `demo1234`).

### Variables d'environnement (`.env.local`)

| Variable | Rôle |
|---|---|
| `DATABASE_URL` | connexion MySQL/MariaDB (indiquer `serverVersion`, ex. `8.0.32` ou `10.11.14-MariaDB`) |
| `APP_SECRET` | clé secrète Symfony (chaîne aléatoire) |
| `MAILER_DSN` | serveur d'envoi d'e-mails, ex. `smtp://user:mdp@smtp.exemple.com:587` |
| `APP_NOM`, `APP_DEVISE`, `APP_EMAIL_EXPEDITEUR`, `APP_TELEPHONE_AGENCE` | personnalisation |
| `SMS_TRANSPORT` | `log` (n'envoie rien, écrit dans les logs) ou `orange` |
| `ORANGE_SMS_CLIENT_ID`, `ORANGE_SMS_CLIENT_SECRET`, `ORANGE_SMS_SENDER`, `ORANGE_SMS_SENDER_NAME` | API Orange SMS |

Ne jamais mettre de mots de passe ou de clés dans `.env` (versionné) : uniquement dans `.env.local`
ou dans les variables d'environnement du serveur.

## Reprise des données de l'ancienne application

Dans une base neuve (migrations exécutées, aucun client ni colis) :

```bash
# Simulation (rien n'est enregistré)
php bin/console app:import-ancienne-base "mysql://user:mdp@127.0.0.1:3306/ancienne_base" --dry-run
# Import réel, avec copie des photos
php bin/console app:import-ancienne-base "mysql://user:mdp@127.0.0.1:3306/ancienne_base" --photos=/chemin/ancienne-app/public/uploads
```

Repris : utilisateurs (mêmes identifiants et mots de passe ; les comptes « client » ne sont pas repris),
types de marchandises, clients et solde bonus (Sahel), expéditions, colis et articles, paiements,
programmes de ramassage. Les montants sont convertis en centimes, les statuts normalisés, les codes de
retrait manquants ou en double régénérés. Aucun SMS ni e-mail n'est envoyé pendant l'import, et tout
est annulé en cas d'erreur.

Les anciens liens de suivi (`/view/details/…`) et QR codes des reçus déjà imprimés ne sont pas repris :
les destinataires utilisent `/suivi` avec leur n° de suivi.

## Tests

```bash
php vendor/bin/phpunit
```

La suite (SQLite, recréée à chaque lancement) couvre les calculs de montants, les encaissements et
le bonus, les droits par rôle et par pays, et le parcours complet d'un colis (saisie → encaissement →
arrivée → remise → suivi public), ainsi que la génération du reçu PDF.

## Mise en production

```bash
composer install --no-dev --optimize-autoloader
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php bin/console cache:clear
```

Le serveur web doit pointer sur `public/`. Les dossiers `var/` (cache, logs, `var/uploads` pour les photos)
doivent être accessibles en écriture et **sauvegardés** (photos).
