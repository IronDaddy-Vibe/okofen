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
