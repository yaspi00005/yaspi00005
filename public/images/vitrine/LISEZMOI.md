# Photos du site vitrine (sotrama.net)

**Témoignages, logos des partenaires et annonces (nouvelles destinations…) se gèrent désormais dans
l'application : Administration → Site internet** (envoi des vidéos et logos depuis le navigateur).
Les dossiers `temoignages/` et `enseignes/` ci-dessous restent acceptés (dépôt par FTP).

Déposez ici vos photos (JPEG, PNG ou WebP), elles apparaissent automatiquement sur le site :

| Fichier | Où il apparaît |
|---|---|
| `hero.jpg` | Grande photo de fond en haut de la page d'accueil (camion, conteneur, équipe…). Format paysage, 1600 px de large conseillé. |
| `galerie/*.jpg` | Section « En images » (jusqu'à 12 photos, triées par nom : `01-chargement.jpg`, `02-depart.jpg`…). |
| `bamako.jpg`, `dakar.jpg` | Cartes des destinations Mali et Sénégal (sinon dessins par défaut). |
| `equipe/yacouba.jpg`, `equipe/daby.jpg`, `equipe/mamadou.jpg` | Photo de chaque membre de l'équipe (sinon ses initiales). Format carré. |
| `temoignages/*.mp4` | Onglet « Témoignages » et section de l'accueil : vidéos des clients (MP4, triées par nom : `01-awa.mp4`…). Image d'aperçu facultative du même nom : `01-awa.jpg`. Nom et phrase du client dans `config/vitrine.yaml` (sinon le nom du fichier). |
| `enseignes/*.png` | Section « Ils nous font confiance » : logos des enseignes (PNG transparent ou SVG de préférence ; JPG/WebP acceptés). Nom affiché d'après le fichier (`boutique-dupont.png` → « Boutique Dupont »), ou dans `config/vitrine.yaml` avec un lien. |
| `logo.png` | (facultatif) remplace le logo du camion dans l'en-tête du site vitrine. |

Vidéos : format vertical (téléphone) ou horizontal, en MP4 (H.264), 720p et moins de 30 Mo chacune ;
elles sont hébergées sur votre serveur (aucun YouTube ni traceur). Ajoutez l'image d'aperçu pour un affichage rapide.

Conseil : réduisez les photos avant de les déposer (1600 px de large, moins de 400 Ko) pour un site rapide sur mobile.
Sans photo, le site s'affiche avec ses illustrations par défaut.

## Illustrations par défaut

Le dossier `illustrations/` contient les dessins utilisés quand aucune photo n'est déposée :
`scene.svg` (bas de l'accueil : camion, porte-conteneurs, avion), `bamako.svg` et `dakar.svg`
(cartes des destinations). Pour les remplacer par des photos, déposez `hero.jpg`, `bamako.jpg`
ou `dakar.jpg` dans ce dossier.
