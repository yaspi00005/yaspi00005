# SOTRAMA — v2

Application de gestion d'envoi de colis France → Mali / Sénégal : ramassage chez le client,
expéditions aériennes et maritimes, encaissements, bonus fidélité, guichet de retrait en agence
et suivi en ligne pour les destinataires.

Réécriture complète de l'ancienne application (Symfony 7.4, PHP 8.2+, MySQL 8 / MariaDB),
avec l'interface du thème **Workload** (ThemeForest).

## Fonctionnalités

| Module | Pour qui | Ce qu'on y fait |
|---|---|---|
| Tableau de bord | tous | chiffres clés, graphiques (colis par mois, encaissements, statuts), derniers colis |
| Colis | tous (filtré par pays pour les représentants) | recherche et filtres, saisie multi-articles avec photos prises à l'appareil photo du téléphone (colis et articles, compressées avant l'envoi) et prix suggéré, reçu PDF avec QR code, export Excel |
| Clients | ramassage, admin | fiche client, historique des colis, compte bonus fidélité, position GPS (téléphone ou adresse), carte et itinéraire Google Maps / Waze |
| Ramassages | ramassage, admin | calendrier (glisser-déposer), rendez-vous à venir avec itinéraire vers le client |
| Expéditions | tous (admin pour modifier) | création, affectation des colis, changement de statut répercuté sur tous les colis + SMS, manifeste Excel |
| Guichet agence | représentants, admin | recherche par code de retrait / n° de suivi / scan QR (caméra ou douchette), encaissement, remise au destinataire |
| Paiements | tous (filtré par pays) | journal filtrable, totaux par caissier et par mode, export Excel, annulation (admin) |
| Suivi public | destinataires, sans compte | `/suivi` : n° de suivi + téléphone, ou lien du QR code |
| Administration | admin | utilisateurs et rôles, types de marchandises et tarifs au kg, journal des SMS / e-mails |

Rôles : **Administrateur**, **Agent de ramassage** (France), **Représentant** (agence, rattaché à un pays).

### Bonus fidélité

Chaque colis rapporte au client un bonus selon le total de ses articles (reprise du trigger
`bases_sahel` de l'ancienne application, voir `src/Service/BonusFidelite.php`) :

| Total des articles | Bonus | | Total des articles | Bonus |
|---|---|---|---|---|
| 200 – 299,99 € | 10 € | | 900 – 999,99 € | 70 € |
| 300 – 399,99 € | 15 € | | 1 000 – 1 099,99 € | 80 € |
| 400 – 499,99 € | 20 € | | 1 100 – 1 199,99 € | 90 € |
| 500 – 599,99 € | 30 € | | 1 200 – 1 299,99 € | 100 € |
| 600 – 699,99 € | 40 € | | 1 300 – 1 399,99 € | 110 € |
| 700 – 799,99 € | 50 € | | 1 400 – 1 499,99 € | 120 € |
| 800 – 899,99 € | 60 € | | 1 500 € et plus | 150 € |

Le bonus est recalculé si les articles changent et retiré si le colis est supprimé. Il est utilisable
sur les colis suivants (pas sur celui qui l'a rapporté) et figure sur le reçu.

### Améliorations par rapport à l'ancienne version

- Montants stockés en centimes, calculs centralisés (`PaiementService`) : plus de sur-encaissement possible, remises et bonus cohérents, annulation d'un paiement qui restitue le bonus.
- Statuts normalisés (énumérations) et historique horodaté de chaque colis.
- Remise au destinataire bloquée tant que le colis n'est pas soldé (forçage possible par un admin).
- Anti double-clic : un formulaire de colis ne peut créer qu'un seul colis.
- Photos stockées hors du dossier public, visibles uniquement par les utilisateurs autorisés.
- Lien de suivi non devinable ; le code de retrait n'apparaît jamais sur la page publique.
- Identifiants chiffrés dans toutes les URL (`/colis/0ls5xdWLtBp8M2_-OYtNTA` au lieu de `/colis/35`) : AES + signature, clé dérivée d'`APP_SECRET` ; un lien modifié ou inventé donne une erreur 404.
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
| `APP_SECRET` | clé secrète Symfony (chaîne aléatoire). Elle sert aussi à chiffrer les identifiants des URL : la changer rend invalides les liens et favoris déjà enregistrés. |
| `MAILER_DSN` | serveur d'envoi d'e-mails, ex. `smtp://user:mdp@smtp.exemple.com:587` |
| `APP_NOM`, `APP_DEVISE`, `APP_EMAIL_EXPEDITEUR`, `APP_TELEPHONE_AGENCE` | personnalisation |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | clés des notifications du navigateur (`app:generer-cles-push`) |
| `SMS_TRANSPORT` | `log` (n'envoie rien, écrit dans les logs) ou `orange` |
| `ORANGE_SMS_CLIENT_ID`, `ORANGE_SMS_CLIENT_SECRET`, `ORANGE_SMS_SENDER`, `ORANGE_SMS_SENDER_NAME` | API Orange SMS |

Ne jamais mettre de mots de passe ou de clés dans `.env` (versionné) : uniquement dans `.env.local`
ou dans les variables d'environnement du serveur.

## Rappels des ramassages (navigateur et e-mail)

Pour chaque ramassage « En attente », un rappel est envoyé **la veille** puis **environ 1 h avant**,
à l'agent assigné (ou à tous les agents de ramassage si personne n'est assigné), par notification
du navigateur et par e-mail. Chaque rappel n'est envoyé qu'une fois ; il repart si le rendez-vous est déplacé.

Mise en place (une seule fois) :

```bash
php bin/console app:generer-cles-push --ecrire   # écrit les 3 clés VAPID_… dans .env.local
```

Puis une tâche planifiée (cron) toutes les 10 minutes :

```
*/10 * * * * cd /chemin/vers/sotrama && php bin/console app:rappels-ramassages --env=prod >> var/log/rappels.log 2>&1
```

Chaque agent clique ensuite sur **« Activer les notifications »** (en haut de page ou dans *Mon profil*)
sur l'ordinateur ou le téléphone où il veut les recevoir ; *Mon profil* permet d'envoyer une notification de test.

- Les notifications du navigateur exigent un site en **HTTPS** (sauf `localhost`) et un navigateur en mode
  normal (Chrome, Edge, Firefox, Safari sur iPhone après « Ajouter à l'écran d'accueil »).
- Ne changez pas les clés VAPID une fois en service : tous les navigateurs devraient se réabonner.
- L'extension PHP `gmp` est recommandée (calculs plus rapides), mais pas obligatoire.
- Les envois (e-mail et navigateur) apparaissent dans *Administration → SMS & e-mails envoyés*.

## Application Android

L'application Android (dossier `android/`) est une *Trusted Web Activity* : elle ouvre le site SOTRAMA
en plein écran avec le moteur de Chrome (notifications des rappels, caméra pour le scan des QR codes,
photos des colis). Toute évolution du site est immédiatement disponible dans l'application.

Elle est compilée par **GitHub Actions** (`.github/workflows/android.yml`) et **hébergée par le site** :
le fichier `sotrama.apk` est déposé sur le serveur dans le dossier `apk/` du projet (hors Git). La page
`/application` (menu « Application mobile », lien sur la page de connexion) propose alors son
téléchargement, avec sa taille et sa date, et les étapes d'installation.

Mise en place (une seule fois), dans GitHub → *Settings → Secrets and variables → Actions* :

| Type | Nom | Valeur |
|---|---|---|
| Variable | `SOTRAMA_URL` | adresse HTTPS du site, ex. `https://app.sotrama.com` |
| Secret | `ANDROID_KEYSTORE_BASE64` | contenu du fichier `sotrama.jks.base64.txt` (clé de signature) |
| Secret | `ANDROID_KEYSTORE_PASSWORD` | mot de passe de la clé |

Puis *Actions → Application Android → Run workflow*. À la fin de l'exécution, téléchargez l'archive
`sotrama-apk-1.N` (section *Artifacts*, conservée 30 jours), décompressez-la et déposez `sotrama.apk`
sur le serveur dans `apk/sotrama.apk` (FTP ou `scp`). Même opération à chaque nouvelle version.

Sur le serveur, dans `.env.local` : `APP_ANDROID_SHA256=<empreinte SHA-256 de la clé>`. Le site publie
alors `/.well-known/assetlinks.json`, ce qui permet à l'application de s'afficher sans barre d'adresse.

**Conservez précieusement la clé de signature et son mot de passe** : sans elle, impossible de publier une
mise à jour installable par-dessus l'application existante.

## Site vitrine (sotrama.net)

Le même projet sert aussi le **site public** de SOTRAMA, affiché quand on visite un domaine de
`VITRINE_DOMAINES` (par défaut `sotrama.net`, `www.sotrama.net`, et `sotrama.localhost` pour tester en local) :

- présentation (services, destinations Bamako/Dakar, étapes, équipe, réseaux sociaux, FAQ), boutons WhatsApp ;
- **suivi des colis** (`/suivi`) : numéro de suivi + téléphone, directement relié aux données de l'application ;
- `robots.txt`, `sitemap.xml`, mentions légales.

Sur ce domaine, les pages de gestion (`/connexion`, `/colis`…) redirigent vers `APP_URL` (`https://app.sotrama.net`).

- Contacts, équipe, réseaux : `config/vitrine.yaml`.
- Photos : `public/images/vitrine/` (voir `LISEZMOI.md` : `hero.jpg`, `galerie/*.jpg`).
- Mise en ligne : faire pointer `sotrama.net` (et `www`) vers le **même dossier `public/`** que `app.sotrama.net`.
- En local : `http://sotrama.localhost:8000`.

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
