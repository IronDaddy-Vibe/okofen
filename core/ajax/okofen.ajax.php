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
        $system = $api->testConnection();

        // On enrichit le message avec le modèle réellement détecté, plus parlant
        // qu'un simple « connexion réussie ».
        $details = array();
        try {
            // Le suffixe « ? » (métadonnées) n'est accepté que sur « all » : sur un
            // composant seul, la chaudière répond par sa page d'aide.
            $pe = $api->read('all', true);
            if (isset($pe['pe1']['L_type'])) {
                $types = okofenApi::parseFormat($pe['pe1']['L_type']['format']);
                $typeVal = intval($pe['pe1']['L_type']['val']);
                if (isset($types[$typeVal])) {
                    $details[] = __('Modèle : ', __FILE__) . $types[$typeVal];
                }
            }
            if (isset($pe['pe1']['L_runtime']['val'])) {
                $details[] = __('Compteur brûleur : ', __FILE__) . $pe['pe1']['L_runtime']['val'] . ' h';
            }
        } catch (Exception $e) {
            // Non bloquant : la connexion de base est déjà validée.
        }
        if (isset($system['system']['L_errors'])) {
            $details[] = __('Défauts : ', __FILE__) . $system['system']['L_errors'];
        }

        ajax::success(__('Connexion réussie. ', __FILE__) . implode(' — ', $details));
    }

    /* ---------------------------------------------------------------- */
    if (init('action') == 'syncCommands') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'okofen') {
            throw new Exception(__('Équipement ÖkoFEN introuvable : ', __FILE__) . init('id'));
        }
        $eqLogic->syncCommands();
        $eqLogic->refresh();
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
