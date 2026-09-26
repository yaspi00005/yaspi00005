<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Base SQLite de test recréée à chaque lancement, avec les données de démonstration.
if ('test' === $_SERVER['APP_ENV'] && !getenv('SOTRAMA_SKIP_DB_RESET')) {
    $console = escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach (['doctrine:schema:drop --force --full-database', 'doctrine:schema:create', 'doctrine:fixtures:load --no-interaction'] as $commande) {
        passthru(\sprintf('php %s %s --env=test --quiet', $console, $commande), $code);
        if (0 !== $code) {
            throw new RuntimeException("Échec de « $commande » pour la base de test.");
        }
    }
}
