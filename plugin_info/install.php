<?php
/* Plugin OkoFEN pour Jeedom — routines d'installation */

/* Ce fichier est appelé par Jeedom en ligne de commande (jeePlugin.php) lors de
 * l'installation, de la mise à jour et de la suppression du plugin. Il n'y a donc
 * pas de session utilisateur : tout contrôle isConnect() y échouerait toujours. */

function okofen_install() {
    okofen_update();
}

function okofen_update() {
    // Valeurs par défaut au niveau du plugin
    if (config::byKey('defaultPort', 'okofen') == '') {
        config::save('defaultPort', '4321', 'okofen');
    }
    if (config::byKey('defaultSiloCapacity', 'okofen') == '') {
        config::save('defaultSiloCapacity', '6000', 'okofen');
    }
    if (config::byKey('httpTimeout', 'okofen') == '') {
        config::save('httpTimeout', '10', 'okofen');
    }
    log::add('okofen', 'info', 'Installation / mise à jour du plugin ÖkoFEN terminée.');
}

function okofen_remove() {
    log::add('okofen', 'info', 'Désinstallation du plugin ÖkoFEN.');
}
