<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// PHP 8.4 et plus : objets « lazy » natifs de PHP pour les entités Doctrine
// (remplace l'ancien générateur de proxies, déprécié). En PHP 8.2/8.3, rien ne change.
return static function (ContainerConfigurator $container): void {
    if (\PHP_VERSION_ID >= 80400) {
        $container->extension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
    }
};
