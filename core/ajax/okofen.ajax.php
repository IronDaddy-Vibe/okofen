<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    // L'autoloader de Jeedom sait résoudre la classe d'équipement « okofen », mais pas
    // la classe utilitaire « okofenApi » : sans cet include, « Tester la connexion »
    // échoue avec « Class okofenApi not found ».
    require_once dirname(__FILE__) . '/../class/okofen.class.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    /* ---------------------------------------------------------------- */
    if (init('action') == 'testConnection') {
        $api = new okofenApi(init('ip'), init('port', 4321), init('password'), config::byKey('httpTimeout', 'okofen', 10));

        // Une seule requête : « all? » contient déjà le composant « system », et la
        // chaudière n'accepte qu'une requête toutes les 2,6 s. Lire « system » puis
        // « all? » doublait donc le temps de réponse pour rien.
        $all = $api->read('all', true);
        if (!isset($all['system'])) {
            throw new Exception(__('Réponse valide mais composant « system » absent.', __FILE__));
        }

        // On enrichit le message avec le modèle réellement détecté, plus parlant
        // qu'un simple « connexion réussie ».
        $details = array();
        if (isset($all['pe1']['L_type'])) {
            $types = okofenApi::parseFormat($all['pe1']['L_type']['format']);
            $typeVal = intval($all['pe1']['L_type']['val']);
            if (isset($types[$typeVal])) {
                $details[] = __('Modèle : ', __FILE__) . $types[$typeVal];
            }
        }
        if (isset($all['pe1']['L_runtime']['val'])) {
            $details[] = __('Compteur brûleur : ', __FILE__) . $all['pe1']['L_runtime']['val'] . ' h';
        }
        if (isset($all['system']['L_errors'])) {
            $errors = $all['system']['L_errors'];
            $details[] = __('Défauts : ', __FILE__) . (is_array($errors) ? $errors['val'] : $errors);
        }

        ajax::success(__('Connexion réussie. ', __FILE__) . implode(' — ', $details));
    }

    /* ---------------------------------------------------------------- */
    /*
     * Exécution d'une commande depuis le widget.
     *
     * Les boutons du widget passaient par jeedom.cmd.execute() en lui confiant des
     * options : celles-ci n'arrivaient jamais côté PHP — le plugin ne recevait que
     * le contexte utilisateur — et toute commande ayant besoin d'une valeur échouait.
     * Seules les commandes à valeur fixe fonctionnaient.
     *
     * On construit donc les options ici, où l'on maîtrise les deux extrémités, en les
     * dérivant du sous-type réel de la commande.
     */
    if (init('action') == 'runCmd') {
        $cmd = cmd::byId(init('id'));
        if (!is_object($cmd) || $cmd->getEqType() != 'okofen') {
            throw new Exception(__('Commande ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }

        $value = trim((string) init('value', ''));
        $options = array();
        switch ($cmd->getSubType()) {
            case 'slider':
                $options['slider'] = $value;
                break;
            case 'select':
                $options['select'] = $value;
                break;
            case 'message':
                $options['message'] = $value;
                break;
            // « other » ne prend aucun paramètre : bouton simple.
        }

        log::add('okofen', 'debug', 'Widget → ' . $cmd->getLogicalId()
            . ' (' . $cmd->getSubType() . ') valeur « ' . $value . ' »');
        $cmd->execCmd($options);
        ajax::success();
    }

    /* ---------------------------------------------------------------- */
    /* Graphique d'historique, rendu en SVG côté serveur. */
    if (init('action') == 'historyChart') {
        $cmd = cmd::byId(init('id'));
        if (!is_object($cmd) || $cmd->getEqType() != 'okofen') {
            throw new Exception(__('Commande ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }
        if ($cmd->getIsHistorized() != 1) {
            throw new Exception(__('Cette commande n\'est pas historisée : aucun graphique ne peut être tracé. Activez l\'historisation dans sa configuration.', __FILE__));
        }
        ajax::success(okofen::renderHistoryChart($cmd, init('hours', 24)));
    }

    /* ---------------------------------------------------------------- */
    if (init('action') == 'syncCommands') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'okofen') {
            throw new Exception(__('Équipement ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }
        // Une seule lecture de la chaudière : « all? » sert à la fois à la
        // synchronisation et à la mise à jour des valeurs.
        $meta = $eqLogic->syncCommands();
        $eqLogic->refresh(okofen::flattenMeta($meta));
        ajax::success();
    }

    /* ---------------------------------------------------------------- */
    if (init('action') == 'addDelivery') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'okofen') {
            throw new Exception(__('Équipement ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }
        $eqLogic->declareDelivery(init('kg'));
        ajax::success();
    }

    /* ---------------------------------------------------------------- */
    if (init('action') == 'addAsh') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'okofen') {
            throw new Exception(__('Équipement ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }
        $eqLogic->declareAshEmptied();
        ajax::success();
    }

    /* ---------------------------------------------------------------- */
    if (init('action') == 'getHistories') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'okofen') {
            throw new Exception(__('Équipement ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }
        ajax::success(array(
            'deliveries' => $eqLogic->getDeliveryHistory(),
            'ash' => $eqLogic->getAshHistory(),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
