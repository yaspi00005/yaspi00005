/**
 * Captures d'écran de la page « Besoin d'aide », prises sur les DONNÉES DE DÉMONSTRATION
 * (jamais sur la vraie base : aucune donnée client ne doit apparaître dans l'aide).
 *
 *   1. Base de démo : php bin/console doctrine:fixtures:load (sur une base dédiée)
 *   2. Serveur :      php -S 127.0.0.1:8003 -t public
 *   3. Captures :     node scripts/captures-aide.mjs http://127.0.0.1:8003
 *
 * Les images sont écrites dans public/images/aide/ (JPEG). Playwright doit être installé
 * (npm i -D playwright, ou global).
 */
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';

const base = (process.argv[2] || 'http://127.0.0.1:8003').replace(/\/$/, '');
const dossier = new URL('../public/images/aide/', import.meta.url).pathname;
mkdirSync(dossier, { recursive: true });

let playwright;
try {
    playwright = createRequire(import.meta.url)('playwright');
} catch {
    playwright = createRequire(execSync('npm root -g').toString().trim() + '/')('playwright');
}
const navigateur = await playwright.chromium.launch({ executablePath: process.env.CHROMIUM || undefined }).catch(() => playwright.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }));

const CSS = '.sf-toolbar, .sf-minitoolbar, #preloader { display: none !important; } #main-wrapper { opacity: 1 !important; visibility: visible !important; }';

async function session(login, largeur = 1280, hauteur = 800) {
    const contexte = await navigateur.newContext({ viewport: { width: largeur, height: hauteur }, locale: 'fr-FR', deviceScaleFactor: 1 });
    const page = await contexte.newPage();
    if (login) {
        await page.goto(base + '/connexion');
        await page.fill('input[name=_username]', login);
        await page.fill('input[name=_password]', 'demo1234');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}

async function ouvrir(page, chemin) {
    await page.goto(chemin.startsWith('http') ? chemin : base + chemin);
    await page.addStyleTag({ content: CSS });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(600);
}

async function capture(page, nom, cible = null) {
    const options = { path: dossier + nom + '.jpg', type: 'jpeg', quality: 72 };
    if (cible) {
        await page.locator(cible).first().scrollIntoViewIfNeeded();
        await page.locator(cible).first().screenshot(options);
    } else {
        await page.screenshot(options);
    }
    console.log('✔', nom);
}

async function premierLien(page, chemin, selecteur) {
    await ouvrir(page, chemin);
    return page.locator(selecteur).first().getAttribute('href');
}

// Écran de connexion.
let p = await session(null);
await ouvrir(p, '/connexion');
await capture(p, 'connexion');

// ---------- Agent de ramassage ----------
p = await session('ramassage');
await ouvrir(p, '/');
await capture(p, 'tableau-de-bord-agent');
await capture(p, 'mes-performances', '.card:has-text("Mes performances")');
await ouvrir(p, '/clients/nouveau');
await capture(p, 'client-nouveau');
// Doublon : même téléphone qu'un client existant, écrit autrement.
await ouvrir(p, '/clients');
const telExistant = (await p.locator('table tbody a[href^="tel:"]').first().getAttribute('href')).replace('tel:', '').replace(/\s/g, '');
await ouvrir(p, '/clients/nouveau');
await p.fill('#client_prenom', 'Mamadou');
await p.fill('#client_nom', 'Coulibaly');
await p.fill('#client_telephone', telExistant.replace(/^0(\d)(\d\d)(\d\d)(\d\d)(\d\d)$/, '+33 $1 $2 $3 $4 $5'));
await Promise.all([p.waitForNavigation(), p.click('form[name=client] button:has-text("Enregistrer")')]);
await p.addStyleTag({ content: CSS });
await p.waitForTimeout(1200);
await capture(p, 'client-doublon', '.alert-warning');
await ouvrir(p, '/colis/nouveau');
await capture(p, 'colis-nouveau');
await ouvrir(p, '/colis');
await capture(p, 'colis-liste');
const fiche = await premierLien(p, '/colis?statut=en_preparation', 'table a[href*="/colis/"]');
await ouvrir(p, fiche);
await capture(p, 'colis-fiche');
await capture(p, 'colis-envoi-recu', '.carte-envoi-recu');
await ouvrir(p, '/ramassages');
await capture(p, 'ramassages');
await ouvrir(p, '/corbeille');
await capture(p, 'corbeille');

// Mobile (application Android).
p = await session('ramassage', 390, 844);
await ouvrir(p, '/colis/nouveau');
await capture(p, 'mobile-colis-nouveau');

// ---------- Représentant ----------
p = await session('bamako');
await ouvrir(p, '/');
await capture(p, 'tableau-de-bord-representant');
await ouvrir(p, '/agence');
await capture(p, 'guichet');
const aEncaisser = await premierLien(p, '/colis?paiement=partiel', 'table a[href*="/colis/"]').catch(() => null)
    || await premierLien(p, '/colis', 'table a[href*="/colis/"]');
await ouvrir(p, aEncaisser);
await capture(p, 'representant-colis');
await p.fill('#encaissement_montant', '20').catch(() => {});
await capture(p, 'encaissement', '.card:has-text("Encaisser")').catch(() => {});
await ouvrir(p, '/encaissements?du=2000-01-01');
await capture(p, 'mes-encaissements');

// ---------- Administrateur ----------
p = await session('admin');
await ouvrir(p, '/');
await capture(p, 'tableau-de-bord-admin');
await capture(p, 'rentabilite-tableau-de-bord', '.card:has-text("Rentabilité des expéditions")');
await ouvrir(p, '/expeditions');
await capture(p, 'expeditions');
const expedition = await premierLien(p, '/expeditions/rentabilite', 'table a[href*="/expeditions/"]');
await ouvrir(p, expedition);
await capture(p, 'expedition-fiche');
await capture(p, 'expedition-rentabilite', '#rentabilite');
await ouvrir(p, '/expeditions/rentabilite');
await capture(p, 'rentabilite');
await ouvrir(p, '/paiements?du=2000-01-01');
await capture(p, 'paiements');
await ouvrir(p, '/admin/bonus/bareme');
await capture(p, 'bareme-bonus');
await ouvrir(p, '/admin/utilisateurs');
await capture(p, 'utilisateurs');
await ouvrir(p, '/admin/journal');
await capture(p, 'journal');
await ouvrir(p, '/application');
await capture(p, 'application-mobile');

await navigateur.close();
