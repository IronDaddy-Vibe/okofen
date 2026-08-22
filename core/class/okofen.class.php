<?php
/* Plugin ÖkoFEN pour Jeedom — classe principale */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/okofenApi.class.php';

class okofen extends eqLogic {

    /* ATTENTION — ne jamais déclarer de propriété dans cette classe.
     * DB::save() construit sa requête par réflexion sur les propriétés de l'objet et
     * les traite comme autant de colonnes de la table `eqLogic` : toute propriété
     * supplémentaire fait échouer l'enregistrement avec « Unknown column ».
     * Les valeurs internes passent donc par des constantes, ou par une variable
     * statique locale à une méthode (voir suspendPostSave()). */

    /* Composants que l'on ne transforme pas en commandes : trop verbeux ou non pertinents. */
    const SKIPPED_COMPONENTS = array('forecast');

    /* Clés de métadonnées à ignorer dans la réponse « all? ». */
    const META_INFO_SUFFIX = '_info';

    /* ------------------------------------------------------------------ */
    /* Mode d'affichage                                                    */
    /*                                                                     */
    /* La découverte automatique produit une centaine de commandes : riche  */
    /* pour explorer, illisible au quotidien. Le mode « basique » n'en      */
    /* masque que l'affichage — toutes restent créées, alimentées et        */
    /* utilisables en scénario. Le basculement est donc réversible.         */
    /* ------------------------------------------------------------------ */

    /* Variables de mesure retenues en mode basique. */
    const BASIC_INFOS = array(
        'L_temp_act',      // température chaudière
        'L_statetext',     // état en clair
        'L_modulation',    // puissance instantanée
        'L_runtime',       // compteur brûleur
        'L_flowtemp_act',  // départ chauffage
        'L_ontemp_act',    // température ECS
    );

    /* Variables pilotables retenues en mode basique. */
    const BASIC_ACTIONS = array(
        'mode',            // marche/arrêt chaudière
        'mode_auto',       // mode chauffage et ECS
        'heat_once',       // chauffe ECS ponctuelle
        'temp_heat',       // consigne confort
        'temp_setback',    // consigne réduit
        'temp_min_set',    // enclenchement ECS
        'temp_max_set',    // arrêt ECS
    );

    /* Commandes calculées retenues en mode basique. */
    const BASIC_DERIVED = array(
        'conn_ok', 'error_active', 'error_text',
        'pellet_stock_kg', 'pellet_stock_pct', 'pellet_consumed_today',
        'pellet_autonomy_days', 'pellet_low_alert', 'ash_alert',
        'pellet_add_delivery', 'ash_reset', 'refresh',
    );

    /* Pouvoir calorifique inférieur du granulé, en kWh par kg. */
    const PELLET_KWH_PER_KG = 4.8;

    /* Codes d'état pe1 correspondant à une demande de maintenance. */
    const STATE_ASH = 8;
    const STATE_PELLETS = 9;
    const STATE_ERROR = 11;

    /* ------------------------------------------------------------------ */
    /* Tâches planifiées                                                    */
    /* ------------------------------------------------------------------ */

    public static function cron() {
        foreach (self::byType('okofen', true) as $eqLogic) {
            $interval = intval($eqLogic->getConfiguration('pollInterval', 5));
            if ($interval < 1) {
                $interval = 1;
            }
            $last = intval($eqLogic->getCache('lastPoll', 0));
            if ((time() - $last) < ($interval * 60)) {
                continue;
            }
            $eqLogic->setCache('lastPoll', time());
            try {
                $eqLogic->refresh();
            } catch (Exception $e) {
                log::add('okofen', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
                $eqLogic->setConnectionState(false, $e->getMessage());
            }
        }
    }

    public static function cronDaily() {
        foreach (self::byType('okofen', true) as $eqLogic) {
            try {
                $eqLogic->rolloverPelletDay();
            } catch (Exception $e) {
                log::add('okofen', 'error', $eqLogic->getHumanName() . ' (bilan quotidien) : ' . $e->getMessage());
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Cycle de vie                                                        */
    /* ------------------------------------------------------------------ */

    public function preSave() {
        // Le bouton « Ajouter » de plugin.template.js enregistre l'équipement dès
        // la saisie du nom, avant d'afficher le formulaire de configuration :
        // exiger l'adresse IP à cet instant rendrait la création impossible,
        // puisque le champ n'est pas encore accessible. La validation ne porte
        // donc que sur les enregistrements suivants.
        if ($this->getId() != '') {
            if (trim($this->getConfiguration('ip')) === '') {
                throw new Exception(__('L\'adresse IP de la chaudière est obligatoire.', __FILE__));
            }
            if (trim($this->getConfiguration('password')) === '') {
                throw new Exception(__('Le mot de passe JSON est obligatoire.', __FILE__));
            }
        }
        if (intval($this->getConfiguration('port')) <= 0) {
            $this->setConfiguration('port', config::byKey('defaultPort', 'okofen', 4321));
        }
        if (intval($this->getConfiguration('pollInterval')) <= 0) {
            $this->setConfiguration('pollInterval', 5);
        }
        // Icône du widget, posée une seule fois : un choix fait à la main dans la
        // configuration avancée ne doit jamais être écrasé par une synchronisation.
        if (trim($this->getDisplay('icon', '')) === '') {
            $this->setDisplay('icon', '<i class="fas fa-fire"></i>');
        }
    }

    /**
     * Garde-fou de réentrance. Le rafraîchissement persiste l'état du stock via
     * save(), or postSave() relance une synchronisation puis un rafraîchissement :
     * sans ce drapeau, la première relève partirait en récursion infinie.
     *
     * Le drapeau est porté par une variable statique locale, et non par une propriété
     * de classe : une propriété serait prise pour une colonne SQL par DB::save().
     * Appel sans argument = lecture, avec argument booléen = écriture.
     */
    private static function suspendPostSave($_set = null) {
        static $suspend = false;
        if ($_set !== null) {
            $suspend = (bool) $_set;
        }
        return $suspend;
    }

    /** Enregistre l'équipement sans déclencher la chaîne postSave(). */
    private function persist() {
        self::suspendPostSave(true);
        try {
            $this->save();
        } catch (Exception $e) {
            self::suspendPostSave(false);
            throw $e;
        }
        self::suspendPostSave(false);
    }

    public function postSave() {
        if (self::suspendPostSave()) {
            return;
        }
        if ($this->getIsEnable() != 1) {
            return;
        }
        try {
            // Une seule lecture pour les deux opérations (voir syncCommands()).
            $meta = $this->syncCommands();
            $this->refresh(self::flattenMeta($meta));
        } catch (Exception $e) {
            log::add('okofen', 'error', 'Synchronisation impossible : ' . $e->getMessage());
            // On ne bloque pas l'enregistrement : l'utilisateur doit pouvoir corriger sa config.
            message::add('okofen', __('Synchronisation impossible : ', __FILE__) . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* API                                                                 */
    /* ------------------------------------------------------------------ */

    public function getApi() {
        // Un équipement fraîchement créé n'a pas encore d'adresse : message clair
        // plutôt qu'un échec HTTP obscur au premier cron.
        if (trim($this->getConfiguration('ip')) === '') {
            throw new Exception(__('Adresse IP de la chaudière non configurée.', __FILE__));
        }
        return new okofenApi(
            $this->getConfiguration('ip'),
            $this->getConfiguration('port', 4321),
            $this->getConfiguration('password'),
            config::byKey('httpTimeout', 'okofen', 10)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Découverte et création des commandes                                */
    /* ------------------------------------------------------------------ */

    /**
     * Interroge « all? » et crée/met à jour les commandes correspondant aux
     * variables réellement présentes sur l'installation. La configuration
     * matérielle n'est donc jamais codée en dur : un circuit hk2 ajouté plus
     * tard apparaîtra tout seul à la prochaine synchronisation.
     */
    public function syncCommands() {
        $meta = $this->getApi()->read('all', true);
        $order = 0;
        $found = array();

        foreach ($meta as $component => $variables) {
            if (in_array($component, self::SKIPPED_COMPONENTS) || !is_array($variables)) {
                continue;
            }
            if ($component === 'error') {
                continue; // traité à part, structure variable
            }
            foreach ($variables as $variable => $definition) {
                if (substr($variable, -strlen(self::META_INFO_SUFFIX)) === self::META_INFO_SUFFIX) {
                    continue; // clé descriptive du composant, pas une variable
                }
                $fullName = $component . '_' . $variable;
                $order++;
                $created = $this->syncOneVariable($component, $variable, $fullName, $definition, $order);
                foreach ($created as $logicalId) {
                    $found[] = $logicalId;
                }
            }
        }

        $order = $this->createDerivedCommands($order, $meta);
        $this->applyDisplayMode();

        log::add('okofen', 'info', $this->getHumanName() . ' : ' . count($found) . ' variables synchronisées depuis la chaudière.');
        $this->refreshWidget();

        // Renvoyé pour que l'appelant puisse enchaîner un refresh() sans relire la
        // chaudière : « all? » porte déjà les valeurs, en plus des métadonnées.
        return $meta;
    }

    /**
     * Ramène une réponse « all? » à la forme d'une réponse « all » : chaque variable
     * n'est plus un objet de métadonnées mais sa seule valeur.
     */
    public static function flattenMeta($_meta) {
        $flat = array();
        foreach ($_meta as $component => $variables) {
            if (!is_array($variables)) {
                $flat[$component] = $variables;
                continue;
            }
            foreach ($variables as $variable => $definition) {
                $flat[$component][$variable] = (is_array($definition) && array_key_exists('val', $definition))
                    ? $definition['val']
                    : $definition;
            }
        }
        return $flat;
    }

    /**
     * Crée les commandes d'une variable. Renvoie la liste des logicalId créés.
     *
     * Règle ÖkoFEN : seules les variables sans préfixe « L_ » sont inscriptibles.
     * Les variables inscriptibles reçoivent donc, en plus de leur commande info,
     * une commande action (curseur, liste déroulante ou boutons).
     */
    private function syncOneVariable($_component, $_variable, $_fullName, $_definition, $_order) {
        $created = array();
        $writable = (strpos($_variable, 'L_') !== 0);

        // La réponse « all? » mêle deux formes : soit un objet de métadonnées,
        // soit directement une chaîne (cas de L_statetext).
        if (!is_array($_definition)) {
            $definition = array('val' => $_definition);
        } else {
            $definition = $_definition;
        }

        $value = isset($definition['val']) ? $definition['val'] : '';
        $unit = isset($definition['unit']) ? okofenApi::toUtf8($definition['unit']) : '';
        $unit = str_replace('?C', '°C', $unit);
        $factor = isset($definition['factor']) ? floatval($definition['factor']) : 1;
        if ($factor == 0) {
            $factor = 1;
        }
        $format = isset($definition['format']) ? $definition['format'] : '';
        $enum = okofenApi::parseFormat($format);
        $isBool = okofenApi::isBooleanValue($value);
        $isNumeric = is_numeric($value);

        // --- Commande info -------------------------------------------------
        // Les valeurs brutes des énumérations inscriptibles n'ont pas d'intérêt
        // sur le dashboard : c'est la liste déroulante qui sera affichée.
        $naturalVisible = ($writable && count($enum) > 2) ? 0 : 1;
        $infoCmd = $this->getCmd(null, $_fullName);
        if (!is_object($infoCmd)) {
            $infoCmd = new okofenCmd();
            $infoCmd->setLogicalId($_fullName);
            $infoCmd->setEqLogic_id($this->getId());
            $infoCmd->setName($this->uniqueCmdName($this->humanLabel($_component, $_variable), $_fullName));
            $infoCmd->setIsVisible($naturalVisible);
        }
        // Mémorisée à chaque synchronisation : c'est l'état que le mode expert
        // restaure, y compris après un passage par le mode basique.
        $infoCmd->setConfiguration('defaultVisible', $naturalVisible);
        $infoCmd->setType('info');
        if ($isBool) {
            $infoCmd->setSubType('binary');
        } elseif ($isNumeric) {
            $infoCmd->setSubType('numeric');
            $infoCmd->setUnite($unit);
        } else {
            $infoCmd->setSubType('string');
        }
        $infoCmd->setConfiguration('component', $_component);
        $infoCmd->setConfiguration('variable', $_variable);
        $infoCmd->setConfiguration('factor', $factor);
        $infoCmd->setConfiguration('format', $format);
        $infoCmd->setConfiguration('writable', $writable ? 1 : 0);
        $infoCmd->setOrder($_order);
        $infoCmd->setIsHistorized($this->shouldHistorize($_variable, $isNumeric));
        $infoCmd->save();
        $created[] = $_fullName;

        if (!$writable) {
            return $created;
        }

        // --- Commandes action ----------------------------------------------
        if ($isBool) {
            foreach (array('on' => __('Activer', __FILE__), 'off' => __('Désactiver', __FILE__)) as $suffix => $label) {
                $actionId = 'set_' . $_fullName . '_' . $suffix;
                $cmd = $this->getCmd(null, $actionId);
                if (!is_object($cmd)) {
                    $cmd = new okofenCmd();
                    $cmd->setLogicalId($actionId);
                    $cmd->setEqLogic_id($this->getId());
                    $cmd->setName($this->uniqueCmdName($this->humanLabel($_component, $_variable) . ' — ' . $label, $actionId));
                    $cmd->setIsVisible(1);
                }
                $cmd->setConfiguration('defaultVisible', 1);
                $cmd->setType('action');
                $cmd->setSubType('other');
                $cmd->setValue($infoCmd->getId());
                $cmd->setConfiguration('component', $_component);
                $cmd->setConfiguration('variable', $_variable);
                $cmd->setConfiguration('writeMode', 'fixed');
                $cmd->setConfiguration('fixedValue', ($suffix === 'on') ? 'true' : 'false');
                $cmd->setOrder($_order);
                $cmd->save();
                $created[] = $actionId;
            }
            return $created;
        }

        $actionId = 'set_' . $_fullName;
        $cmd = $this->getCmd(null, $actionId);
        if (!is_object($cmd)) {
            $cmd = new okofenCmd();
            $cmd->setLogicalId($actionId);
            $cmd->setEqLogic_id($this->getId());
            // Le nom doit différer de celui de la commande info correspondante :
            // la table `cmd` refuse deux homonymes sur un même équipement.
            $cmd->setName($this->uniqueCmdName(__('Régler', __FILE__) . ' ' . $this->humanLabel($_component, $_variable), $actionId));
            $cmd->setIsVisible(1);
        }
        $cmd->setConfiguration('defaultVisible', 1);
        $cmd->setType('action');
        $cmd->setValue($infoCmd->getId());
        $cmd->setConfiguration('component', $_component);
        $cmd->setConfiguration('variable', $_variable);
        $cmd->setConfiguration('factor', $factor);

        if (count($enum) > 0) {
            $listValue = array();
            foreach ($enum as $key => $label) {
                $listValue[] = $key . '|' . okofenApi::toUtf8($label);
            }
            $cmd->setSubType('select');
            $cmd->setConfiguration('listValue', implode(';', $listValue));
            $cmd->setConfiguration('writeMode', 'select');
        } elseif ($isNumeric) {
            // Les bornes fournies par l'API sont exprimées en valeur brute :
            // on les convertit à l'échelle affichée via le facteur.
            $min = isset($definition['min']) ? floatval($definition['min']) : null;
            $max = isset($definition['max']) ? floatval($definition['max']) : null;
            $cmd->setSubType('slider');
            if ($min !== null && $min > okofenApi::SENTINEL_NA) {
                $cmd->setConfiguration('minValue', round($min * $factor, 2));
            }
            if ($max !== null && $max < 32767) {
                $cmd->setConfiguration('maxValue', round($max * $factor, 2));
            }
            $cmd->setConfiguration('writeMode', 'slider');
            $cmd->setUnite($unit);
        } else {
            $cmd->setSubType('message');
            $cmd->setConfiguration('writeMode', 'message');
        }
        $cmd->setOrder($_order);
        $cmd->save();
        $created[] = $actionId;

        return $created;
    }

    /**
     * Applique le mode d'affichage à toutes les commandes de l'équipement.
     *
     * Rien n'est supprimé : seule la visibilité change, donc les historiques, les
     * scénarios et les widgets survivent au basculement. Le mode expert restaure la
     * visibilité mémorisée à la création (`defaultVisible`), et non « tout visible » :
     * les valeurs brutes des énumérations restent masquées, comme prévu à l'origine.
     *
     * Contrepartie assumée : un masquage ou un affichage réglé à la main sur une
     * commande est écrasé à la synchronisation suivante. C'est le prix d'un mode
     * d'affichage qui fasse autorité.
     */
    private function applyDisplayMode() {
        $basic = ($this->getConfiguration('displayMode', 'basic') === 'basic');
        $changed = 0;

        foreach (cmd::byEqLogicId($this->getId()) as $cmd) {
            $natural = intval($cmd->getConfiguration('defaultVisible', 1));

            if (!$basic) {
                $visible = $natural;
            } elseif (intval($cmd->getConfiguration('derived', 0)) === 1) {
                $visible = in_array($cmd->getLogicalId(), self::BASIC_DERIVED) ? 1 : 0;
            } elseif ($natural === 0) {
                // Une commande masquée par nature le reste en toutes circonstances.
                $visible = 0;
            } else {
                $variable = $cmd->getConfiguration('variable', '');
                $retained = ($cmd->getType() === 'action') ? self::BASIC_ACTIONS : self::BASIC_INFOS;
                $visible = in_array($variable, $retained) ? 1 : 0;
            }

            if (intval($cmd->getIsVisible()) !== $visible) {
                $cmd->setIsVisible($visible);
                $cmd->save();
                $changed++;
            }
        }

        log::add('okofen', 'info', $this->getHumanName() . ' : mode d\'affichage « '
            . ($basic ? __('basique', __FILE__) : __('expert', __FILE__)) . ' », '
            . $changed . __(' commande(s) modifiée(s).', __FILE__));
    }

    /** Historiser d'office ce qui a un intérêt de suivi dans le temps. */
    private function shouldHistorize($_variable, $_isNumeric) {
        if (!$_isNumeric) {
            return 0;
        }
        $patterns = array('temp_act', 'temp_set', 'L_modulation', 'L_runtime', 'L_starts', 'storage');
        foreach ($patterns as $pattern) {
            if (strpos($_variable, $pattern) !== false) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * Libellé lisible d'une variable, par exemple « Chauffage — Température de départ ».
     *
     * Le dictionnaire ne couvre que les variables rencontrées sur l'installation de
     * référence ; toute variable inconnue retombe sur un libellé dégrossi plutôt que
     * d'être ignorée, pour rester cohérent avec la découverte automatique — un
     * composant ajouté plus tard restera lisible même sans entrée dédiée.
     */
    /**
     * Garantit l'unicité du nom d'une commande au sein de l'équipement.
     *
     * La table `cmd` porte une contrainte unique sur (eqLogic_id, name) : deux
     * commandes homonymes font échouer toute la synchronisation. Comme les libellés
     * sont dérivés d'un dictionnaire et non des noms techniques, des collisions sont
     * possibles ; le nom technique sert alors de départage.
     */
    private function uniqueCmdName($_name, $_logicalId) {
        $base = trim(mb_substr($_name, 0, 45));
        $taken = array();
        foreach (cmd::byEqLogicId($this->getId()) as $cmd) {
            if ($cmd->getLogicalId() !== $_logicalId) {
                $taken[] = $cmd->getName();
            }
        }
        if (!in_array($base, $taken)) {
            return $base;
        }
        $suffix = ' (' . $_logicalId . ')';
        return trim(mb_substr($base, 0, max(1, 45 - mb_strlen($suffix)))) . $suffix;
    }

    private function humanLabel($_component, $_variable) {
        $base = rtrim($_component, '0123456789');
        $index = substr($_component, strlen($base));

        $components = array(
            'pe' => __('Chaudière', __FILE__),
            'hk' => __('Chauffage', __FILE__),
            'ww' => __('ECS', __FILE__),
            'pu' => __('Ballon tampon', __FILE__),
            'sk' => __('Solaire', __FILE__),
            'se' => __('Solaire', __FILE__),
            'circ' => __('Circulation', __FILE__),
            'system' => __('Système', __FILE__),
            'weather' => __('Météo', __FILE__),
            'power' => __('Production', __FILE__),
            'stirling' => __('Stirling', __FILE__),
        );
        $componentLabel = isset($components[$base]) ? $components[$base] : ucfirst($base);
        // L'indice n'est utile que s'il y a plusieurs circuits du même type.
        if ($index !== '' && intval($index) > 1) {
            $componentLabel .= ' ' . intval($index);
        }

        $variables = array(
            'L_temp_act' => __('Température', __FILE__),
            'L_temp_set' => __('Consigne', __FILE__),
            'L_frt_temp_act' => __('Température foyer', __FILE__),
            'L_frt_temp_set' => __('Consigne foyer', __FILE__),
            'L_ext_temp' => __('Température extérieure', __FILE__),
            'L_modulation' => __('Modulation', __FILE__),
            'L_uw' => __('Pompe', __FILE__),
            'L_uw_speed' => __('Vitesse de pompe', __FILE__),
            'L_uw_release' => __('Libération de pompe', __FILE__),
            'L_state' => __('Code d\'état', __FILE__),
            'L_statetext' => __('État', __FILE__),
            'L_starts' => __('Démarrages', __FILE__),
            'L_runtime' => __('Heures de fonctionnement', __FILE__),
            'L_avg_runtime' => __('Durée moyenne de cycle', __FILE__),
            'L_br' => __('Brûleur', __FILE__),
            'L_stb' => __('Sécurité STB', __FILE__),
            'L_storage_fill' => __('Contenu du silo', __FILE__),
            'L_storage_min' => __('Seuil bas du silo', __FILE__),
            'L_storage_max' => __('Capacité du silo', __FILE__),
            'L_storage_popper' => __('Réservoir journalier', __FILE__),
            'L_roomtemp_act' => __('Température ambiante', __FILE__),
            'L_roomtemp_set' => __('Consigne ambiante', __FILE__),
            'L_flowtemp_act' => __('Température de départ', __FILE__),
            'L_flowtemp_set' => __('Consigne de départ', __FILE__),
            'L_pump' => __('Pompe', __FILE__),
            'L_ontemp_act' => __('Température haute ballon', __FILE__),
            'L_offtemp_act' => __('Température basse ballon', __FILE__),
            'L_ambient' => __('Température ambiante', __FILE__),
            'L_errors' => __('Nombre de défauts', __FILE__),
            'L_usb_stick' => __('Clé USB', __FILE__),
            'mode' => __('Mode', __FILE__),
            'mode_auto' => __('Mode', __FILE__),
            'mode_dhw' => __('Mode ECS', __FILE__),
            'heat_once' => __('Chauffe unique', __FILE__),
            'temp_heat' => __('Consigne confort', __FILE__),
            'temp_setback' => __('Consigne réduit', __FILE__),
            'temp_vacation' => __('Consigne vacances', __FILE__),
            'temp_min_set' => __('Seuil d\'enclenchement', __FILE__),
            'temp_max_set' => __('Seuil d\'arrêt', __FILE__),
            'time_prg' => __('Programme horaire', __FILE__),
            'oekomode' => __('Mode éco', __FILE__),
            'smartstart' => __('Anticipation', __FILE__),
            'use_boiler_heat' => __('Appoint chaudière', __FILE__),
            'storage_fill_yesterday' => __('Consommation de la veille', __FILE__),
            'name' => __('Nom', __FILE__),
        );

        if (isset($variables[$_variable])) {
            $variableLabel = $variables[$_variable];
        } else {
            // Dégrossissage : « L_foo_bar » devient « Foo bar ».
            $clean = (strpos($_variable, 'L_') === 0) ? substr($_variable, 2) : $_variable;
            $variableLabel = ucfirst(str_replace('_', ' ', $clean));
        }

        return $componentLabel . ' — ' . $variableLabel;
    }

    /* ------------------------------------------------------------------ */
    /* Commandes calculées : stock de pellets, maintenance, défauts         */
    /* ------------------------------------------------------------------ */

    private function createDerivedCommands($_order, $_meta) {
        $definitions = array(
            // logicalId                  => array(nom, type, subType, unité, visible, historisé)
            'conn_ok'                     => array(__('Connexion chaudière', __FILE__), 'info', 'binary', '', 1, 0),
            'error_active'                => array(__('Défaut en cours', __FILE__), 'info', 'binary', '', 1, 1),
            'error_count'                 => array(__('Nombre de défauts', __FILE__), 'info', 'numeric', '', 0, 0),
            'error_text'                  => array(__('Message de défaut', __FILE__), 'info', 'string', '', 1, 0),
            'pellet_stock_kg'             => array(__('Stock pellets', __FILE__), 'info', 'numeric', 'kg', 1, 1),
            'pellet_stock_pct'            => array(__('Stock pellets', __FILE__), 'info', 'numeric', '%', 1, 1),
            'pellet_capacity_kg'          => array(__('Capacité silo', __FILE__), 'info', 'numeric', 'kg', 0, 0),
            'pellet_consumed_today'       => array(__('Consommation du jour', __FILE__), 'info', 'numeric', 'kg', 1, 1),
            'pellet_consumed_yesterday'   => array(__('Consommation de la veille', __FILE__), 'info', 'numeric', 'kg', 1, 1),
            'pellet_autonomy_days'        => array(__('Autonomie estimée', __FILE__), 'info', 'numeric', 'j', 1, 0),
            'pellet_last_delivery'        => array(__('Dernier remplissage', __FILE__), 'info', 'string', '', 1, 0),
            'pellet_days_since_delivery'  => array(__('Jours depuis remplissage', __FILE__), 'info', 'numeric', 'j', 1, 0),
            'pellet_low_alert'            => array(__('Alerte niveau bas', __FILE__), 'info', 'binary', '', 1, 1),
            'ash_last_empty'              => array(__('Dernière vidange cendres', __FILE__), 'info', 'string', '', 1, 0),
            'ash_days_since'              => array(__('Jours depuis vidange cendres', __FILE__), 'info', 'numeric', 'j', 1, 0),
            'ash_runtime_since'           => array(__('Heures depuis vidange cendres', __FILE__), 'info', 'numeric', 'h', 1, 0),
            'ash_alert'                   => array(__('Alerte vidange cendres', __FILE__), 'info', 'binary', '', 1, 1),
        );

        foreach ($definitions as $logicalId => $def) {
            $_order++;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new okofenCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($this->uniqueCmdName($def[0] . ($def[3] !== '' && $def[3] !== 'kg' ? ' (' . $def[3] . ')' : ''), $logicalId));
                $cmd->setIsVisible($def[4]);
            }
            $cmd->setConfiguration('defaultVisible', $def[4]);
            $cmd->setType($def[1]);
            $cmd->setSubType($def[2]);
            if ($def[3] !== '') {
                $cmd->setUnite($def[3]);
            }
            $cmd->setConfiguration('derived', 1);
            $cmd->setIsHistorized($def[5]);
            $cmd->setOrder($_order);
            $cmd->save();
        }

        // Actions de maintenance
        $actions = array(
            'pellet_add_delivery' => array(__('Déclarer un remplissage (kg)', __FILE__), 'message'),
            'pellet_set_stock'    => array(__('Corriger le stock (kg)', __FILE__), 'message'),
            'ash_reset'           => array(__('Vidange cendres effectuée', __FILE__), 'other'),
            'refresh'             => array(__('Rafraîchir', __FILE__), 'other'),
        );
        foreach ($actions as $logicalId => $def) {
            $_order++;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new okofenCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($this->uniqueCmdName($def[0], $logicalId));
                $cmd->setIsVisible(1);
            }
            $cmd->setConfiguration('defaultVisible', 1);
            $cmd->setType('action');
            $cmd->setSubType($def[1]);
            $cmd->setConfiguration('derived', 1);
            $cmd->setOrder($_order);
            $cmd->save();
        }

        return $_order;
    }

    /* ------------------------------------------------------------------ */
    /* Rafraîchissement des valeurs                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Met à jour toutes les valeurs.
     *
     * $_data permet de réutiliser une réponse déjà obtenue — celle de la
     * synchronisation, par exemple. Avec une fenêtre de 2,6 s entre deux requêtes,
     * éviter une lecture redondante se voit directement sur le temps de réponse.
     */
    public function refresh($_data = null) {
        $data = ($_data === null) ? $this->getApi()->read('all', false) : $_data;
        $this->setConnectionState(true);

        foreach ($data as $component => $variables) {
            if (!is_array($variables)) {
                continue;
            }
            if ($component === 'error') {
                $this->updateErrors($variables, $data);
                continue;
            }
            if (in_array($component, self::SKIPPED_COMPONENTS)) {
                continue;
            }
            foreach ($variables as $variable => $rawValue) {
                $this->updateOneValue($component . '_' . $variable, $rawValue);
            }
        }

        if (isset($data['system']['L_errors'])) {
            $this->checkAndUpdate('error_count', intval($data['system']['L_errors']));
        }

        $this->updatePelletAndMaintenance($data);
        $this->refreshWidget();
    }

    private function updateOneValue($_logicalId, $_rawValue) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return; // apparue depuis la dernière synchro : sera créée au prochain sync
        }

        // Piège n°3 : capteur absent.
        if (okofenApi::isUnavailable($_rawValue)) {
            log::add('okofen', 'debug', $_logicalId . ' : capteur absent (-32768), valeur ignorée.');
            return;
        }

        $subType = $cmd->getSubType();
        if ($subType === 'binary') {
            $value = okofenApi::boolToInt($_rawValue);
        } elseif ($subType === 'numeric') {
            $factor = floatval($cmd->getConfiguration('factor', 1));
            if ($factor == 0) {
                $factor = 1;
            }
            $value = round(floatval($_rawValue) * $factor, 2);
        } else {
            $value = okofenApi::toUtf8(str_replace('?C', '°C', (string)$_rawValue));
        }
        $this->checkAndUpdate($_logicalId, $value);
    }

    private function checkAndUpdate($_logicalId, $_value) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return;
        }
        if ($cmd->execCmd() != $_value) {
            $cmd->event($_value);
        }
    }

    private function setConnectionState($_ok, $_message = '') {
        $this->checkAndUpdate('conn_ok', $_ok ? 1 : 0);
        if (!$_ok && $_message !== '') {
            $this->checkAndUpdate('error_text', substr($_message, 0, 250));
        }
    }

    /**
     * L'objet « error » est vide tant qu'aucun défaut n'est actif, et sa structure
     * exacte n'a pas pu être observée. On reste donc volontairement tolérant :
     * on accepte aussi bien une liste de chaînes qu'une liste d'objets.
     */
    private function updateErrors($_errors, $_all) {
        $count = isset($_all['system']['L_errors']) ? intval($_all['system']['L_errors']) : 0;
        $messages = array();

        // Instrumentation : au premier défaut réel, on veut le JSON brut plutôt qu'une
        // interprétation. C'est la méthode qui a tranché la question des accents en un
        // seul aller-retour, là où deux hypothèses successives s'étaient révélées
        // fausses. La documentation communautaire ne connaît pas ce format non plus,
        // et doute même du sens exact de « system.L_errors ».
        if (!empty($_errors)) {
            log::add('okofen', 'info', 'Structure brute de l\'objet « error » (à documenter) : '
                . json_encode($_errors, JSON_UNESCAPED_UNICODE));
        }

        foreach ($_errors as $key => $entry) {
            if (is_string($entry)) {
                $messages[] = okofenApi::toUtf8($entry);
            } elseif (is_array($entry)) {
                $parts = array();
                foreach ($entry as $subKey => $subValue) {
                    if (is_scalar($subValue)) {
                        $parts[] = okofenApi::toUtf8((string)$subValue);
                    }
                }
                if (count($parts) > 0) {
                    $messages[] = implode(' — ', $parts);
                }
            }
        }

        $active = (count($messages) > 0 || $count > 0);
        $this->checkAndUpdate('error_active', $active ? 1 : 0);
        $this->checkAndUpdate('error_text', $active ? substr(implode(' | ', $messages), 0, 250) : '');

        if ($active && count($messages) > 0) {
            log::add('okofen', 'warning', $this->getHumanName() . ' — défaut signalé : ' . implode(' | ', $messages));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Stock de pellets et suivi de maintenance                            */
    /* ------------------------------------------------------------------ */

    /**
     * Deux sources possibles pour le stock :
     *
     *  - « boiler » : on lit pe1.L_storage_fill, le compteur interne de la chaudière.
     *    Fiable seulement si la fonction de niveau a été initialisée sur l'écran.
     *  - « plugin » : le plugin tient sa propre comptabilité, alimentée par les
     *    remplissages déclarés et par la consommation estimée à partir du temps
     *    de fonctionnement du brûleur. À calibrer avec le facteur de correction.
     */
    private function updatePelletAndMaintenance($_data) {
        if (!isset($_data['pe1'])) {
            return;
        }
        $pe = $_data['pe1'];

        $capacity = intval($this->getConfiguration('siloCapacity', 0));
        if ($capacity <= 0) {
            // À défaut, on prend la capacité déclarée par la chaudière elle-même.
            $capacity = isset($pe['L_storage_max']) ? intval($pe['L_storage_max']) : 0;
        }
        if ($capacity > 0) {
            $this->checkAndUpdate('pellet_capacity_kg', $capacity);
        }

        $source = $this->getConfiguration('stockSource', 'plugin');
        $boilerFill = isset($pe['L_storage_fill']) ? floatval($pe['L_storage_fill']) : 0;

        // La consommation estimée est cumulée dans tous les cas : elle alimente le
        // suivi quotidien et le calcul d'autonomie, même quand le niveau de stock
        // provient du compteur interne de la chaudière.
        $consumedSinceLast = $this->estimateConsumption($pe);
        $stock = floatval($this->getConfiguration('stockKg', 0));
        $dirty = false;

        if ($consumedSinceLast > 0) {
            $today = floatval($this->getConfiguration('consumedToday', 0)) + $consumedSinceLast;
            $this->setConfiguration('consumedToday', round($today, 2));
            $stock = max(0, $stock - $consumedSinceLast);
            $dirty = true;
        }

        // Le compteur interne de la chaudière (calculé à partir de la vis d'alimentation)
        // n'est utilisable que si l'installation offre un moyen de le recharger après
        // une livraison. Ce n'est pas le cas partout : sans remise à niveau possible, il
        // reste bloqué à zéro une fois le silo vidé. Le mode « boiler » est donc opt-in,
        // et n'est pris en compte que lorsqu'il renvoie réellement une valeur.
        if ($source === 'boiler' && $boilerFill > 0) {
            if (abs($stock - $boilerFill) > 0.5) {
                $stock = $boilerFill;
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->setConfiguration('stockKg', round($stock, 2));
            $this->persist();
        }

        $this->checkAndUpdate('pellet_stock_kg', round($stock, 1));
        if ($capacity > 0) {
            $this->checkAndUpdate('pellet_stock_pct', round(100 * $stock / $capacity, 1));
        }
        $this->checkAndUpdate('pellet_consumed_today', round(floatval($this->getConfiguration('consumedToday', 0)), 2));
        $this->checkAndUpdate('pellet_consumed_yesterday', round(floatval($this->getConfiguration('consumedYesterday', 0)), 2));

        // Autonomie : basée sur la moyenne des sept derniers jours si disponible.
        $avgDaily = $this->getAverageDailyConsumption();
        if ($avgDaily > 0.1) {
            $this->checkAndUpdate('pellet_autonomy_days', round($stock / $avgDaily, 1));
        }

        // Seuil bas : celui configuré dans la chaudière, à défaut 10 % de la capacité.
        $lowThreshold = isset($pe['L_storage_min']) ? floatval($pe['L_storage_min']) : ($capacity * 0.1);
        $this->checkAndUpdate('pellet_low_alert', ($stock > 0 && $stock <= $lowThreshold) ? 1 : 0);

        // Dates de maintenance
        $lastDelivery = $this->getConfiguration('lastDelivery', '');
        $this->checkAndUpdate('pellet_last_delivery', $lastDelivery !== '' ? $lastDelivery : __('jamais déclaré', __FILE__));
        if ($lastDelivery !== '') {
            $this->checkAndUpdate('pellet_days_since_delivery', $this->daysSince($lastDelivery));
        }

        $lastAsh = $this->getConfiguration('lastAsh', '');
        $this->checkAndUpdate('ash_last_empty', $lastAsh !== '' ? $lastAsh : __('jamais déclaré', __FILE__));
        if ($lastAsh !== '') {
            $this->checkAndUpdate('ash_days_since', $this->daysSince($lastAsh));
        }
        if (isset($pe['L_runtime'])) {
            $runtimeAtAsh = floatval($this->getConfiguration('runtimeAtAsh', 0));
            if ($runtimeAtAsh > 0) {
                $this->checkAndUpdate('ash_runtime_since', max(0, intval($pe['L_runtime']) - $runtimeAtAsh));
            }
        }

        // Alertes issues directement de l'état de la chaudière.
        $state = isset($pe['L_state']) ? intval($pe['L_state']) : -1;
        $ashAlert = ($state === self::STATE_ASH) ? 1 : 0;
        $this->checkAndUpdate('ash_alert', $ashAlert);
        if ($state === self::STATE_PELLETS) {
            $this->checkAndUpdate('pellet_low_alert', 1);
        }
    }

    /**
     * Estime la consommation écoulée depuis la relève précédente.
     *
     * La Pellematic module sa puissance en continu selon le besoin : la calculer
     * à la puissance nominale sur les heures de fonctionnement surestimerait
     * nettement la consommation. On intègre donc la puissance réellement délivrée,
     * échantillonnée à chaque relève :
     *
     *   kg = durée écoulée (h) × puissance nominale (kW) × modulation (%) × correction
     *        ÷ PCI (kWh/kg)
     *
     * La modulation vaut 0 quand le brûleur ne produit pas : les périodes d'arrêt
     * ne consomment donc rien, sans avoir à tester l'état de la chaudière.
     *
     * Repli : si la chaudière n'expose pas L_modulation, on retombe sur la
     * progression du compteur d'heures, à puissance nominale.
     */
    private function estimateConsumption($_pe) {
        $now = time();
        $lastSample = intval($this->getCache('lastConsumptionSample', 0));
        $this->setCache('lastConsumptionSample', $now);

        $power = floatval($this->getConfiguration('nominalPower', 12));
        if ($power <= 0) {
            $power = 12;
        }
        $correction = floatval($this->getConfiguration('consumptionCorrection', 1));
        if ($correction <= 0) {
            $correction = 1;
        }

        if (isset($_pe['L_modulation'])) {
            if ($lastSample <= 0) {
                return 0; // premier échantillon : pas de durée de référence
            }
            $elapsedHours = ($now - $lastSample) / 3600;

            // Jeedom a pu être arrêté entre deux relèves : on plafonne l'intervalle
            // pris en compte à deux fois la période d'interrogation, sinon une coupure
            // de plusieurs heures se traduirait par une consommation fictive énorme.
            $maxHours = (2 * intval($this->getConfiguration('pollInterval', 5))) / 60;
            if ($elapsedHours <= 0) {
                return 0;
            }
            if ($elapsedHours > $maxHours) {
                log::add('okofen', 'debug', 'Intervalle de ' . round($elapsedHours, 2) . ' h plafonné à ' . round($maxHours, 2) . ' h pour le calcul de consommation.');
                $elapsedHours = $maxHours;
            }

            $modulation = floatval($_pe['L_modulation']);
            if ($modulation <= 0) {
                return 0;
            }
            $modulation = min(100, $modulation);

            return ($elapsedHours * $power * ($modulation / 100) * $correction) / self::PELLET_KWH_PER_KG;
        }

        // --- Repli sans modulation disponible ------------------------------
        if (!isset($_pe['L_runtime'])) {
            return 0;
        }
        $runtime = floatval($_pe['L_runtime']);
        $previous = floatval($this->getConfiguration('lastRuntime', 0));
        if ($runtime != $previous) {
            $this->setConfiguration('lastRuntime', $runtime);
            $this->persist();
        }
        if ($previous <= 0 || $runtime <= $previous) {
            return 0; // premier relevé, ou compteur remis à zéro
        }
        return (($runtime - $previous) * $power * $correction) / self::PELLET_KWH_PER_KG;
    }

    private function getAverageDailyConsumption() {
        $history = json_decode($this->getConfiguration('dailyHistory', '[]'), true);
        if (!is_array($history) || count($history) === 0) {
            return 0;
        }
        $recent = array_slice($history, -7);
        $sum = 0;
        foreach ($recent as $entry) {
            $sum += floatval($entry['kg']);
        }
        return $sum / count($recent);
    }

    /** Bascule quotidienne : archive la consommation du jour écoulé. */
    public function rolloverPelletDay() {
        $today = floatval($this->getConfiguration('consumedToday', 0));
        $history = json_decode($this->getConfiguration('dailyHistory', '[]'), true);
        if (!is_array($history)) {
            $history = array();
        }
        $history[] = array('date' => date('Y-m-d', strtotime('-1 day')), 'kg' => round($today, 2));
        $history = array_slice($history, -60); // deux mois d'historique suffisent

        $this->setConfiguration('dailyHistory', json_encode($history));
        $this->setConfiguration('consumedYesterday', round($today, 2));
        $this->setConfiguration('consumedToday', 0);
        $this->persist();

        $this->checkAndUpdate('pellet_consumed_yesterday', round($today, 2));
        $this->checkAndUpdate('pellet_consumed_today', 0);
        log::add('okofen', 'info', $this->getHumanName() . ' : bilan quotidien, ' . round($today, 2) . ' kg consommés hier.');
    }

    /**
     * Normalise une quantité saisie par l'utilisateur.
     *
     * Refuse explicitement une saisie vide ou non numérique au lieu de la convertir
     * silencieusement en zéro : `floatval('')` vaut 0, ce qui a suffi à remettre un
     * stock à zéro sans le moindre avertissement.
     */
    private static function parseKg($_raw, $_label) {
        $value = trim(str_replace(',', '.', (string) $_raw));
        if ($value === '' || !is_numeric($value)) {
            throw new Exception($_label . __(' : saisissez une quantité en kg. Valeur reçue : « ', __FILE__)
                . (string) $_raw . ' ».');
        }
        return floatval($value);
    }

    /** Déclare un remplissage de silo : ajoute la quantité au stock et date l'opération. */
    public function declareDelivery($_kg) {
        $kg = self::parseKg($_kg, __('Remplissage impossible', __FILE__));
        if ($kg <= 0) {
            throw new Exception(__('Quantité de remplissage invalide : ', __FILE__) . $_kg);
        }
        $capacity = intval($this->getConfiguration('siloCapacity', 0));
        $stock = floatval($this->getConfiguration('stockKg', 0)) + $kg;
        if ($capacity > 0 && $stock > $capacity) {
            log::add('okofen', 'warning', 'Stock déclaré (' . $stock . ' kg) supérieur à la capacité du silo (' . $capacity . ' kg), plafonné.');
            $stock = $capacity;
        }

        $history = json_decode($this->getConfiguration('deliveryHistory', '[]'), true);
        if (!is_array($history)) {
            $history = array();
        }
        $history[] = array('date' => date('Y-m-d H:i'), 'kg' => $kg);
        $history = array_slice($history, -50);

        $this->setConfiguration('stockKg', round($stock, 2));
        $this->setConfiguration('lastDelivery', date('Y-m-d H:i'));
        $this->setConfiguration('deliveryHistory', json_encode($history));
        $this->persist();

        log::add('okofen', 'info', $this->getHumanName() . ' : remplissage de ' . $kg . ' kg déclaré, stock à ' . round($stock, 1) . ' kg.');
        $this->refresh();
    }

    /** Force la valeur du stock, pour recaler après une pesée ou une correction. */
    public function setStock($_kg) {
        $kg = self::parseKg($_kg, __('Correction de stock impossible', __FILE__));
        if ($kg < 0) {
            throw new Exception(__('Stock invalide : ', __FILE__) . $_kg);
        }
        $this->setConfiguration('stockKg', round($kg, 2));
        $this->persist();
        log::add('okofen', 'info', $this->getHumanName() . ' : stock corrigé manuellement à ' . $kg . ' kg.');
        $this->refresh();
    }

    /** Déclare une vidange du cendrier : date l'opération et mémorise le compteur d'heures. */
    public function declareAshEmptied() {
        $runtime = 0;
        try {
            $data = $this->getApi()->read('pe1');
            $runtime = isset($data['pe1']['L_runtime']) ? intval($data['pe1']['L_runtime']) : 0;
        } catch (Exception $e) {
            log::add('okofen', 'warning', 'Vidange cendres : compteur d\'heures non relevé (' . $e->getMessage() . ')');
        }

        $history = json_decode($this->getConfiguration('ashHistory', '[]'), true);
        if (!is_array($history)) {
            $history = array();
        }
        $history[] = array('date' => date('Y-m-d H:i'), 'runtime' => $runtime);
        $history = array_slice($history, -50);

        $this->setConfiguration('lastAsh', date('Y-m-d H:i'));
        $this->setConfiguration('runtimeAtAsh', $runtime);
        $this->setConfiguration('ashHistory', json_encode($history));
        $this->persist();

        log::add('okofen', 'info', $this->getHumanName() . ' : vidange du cendrier déclarée (compteur à ' . $runtime . ' h).');
        $this->checkAndUpdate('ash_last_empty', date('Y-m-d H:i'));
        $this->checkAndUpdate('ash_days_since', 0);
        $this->checkAndUpdate('ash_runtime_since', 0);
    }

    private function daysSince($_dateString) {
        $ts = strtotime($_dateString);
        if ($ts === false) {
            return 0;
        }
        return intval(floor((time() - $ts) / 86400));
    }

    /* ------------------------------------------------------------------ */
    /* Historiques exposés à l'interface                                    */
    /* ------------------------------------------------------------------ */

    public function getDeliveryHistory() {
        $history = json_decode($this->getConfiguration('deliveryHistory', '[]'), true);
        return is_array($history) ? array_reverse($history) : array();
    }

    public function getAshHistory() {
        $history = json_decode($this->getConfiguration('ashHistory', '[]'), true);
        return is_array($history) ? array_reverse($history) : array();
    }

    /* ------------------------------------------------------------------ */
    /* Widget de tableau de bord                                           */
    /* ------------------------------------------------------------------ */

    /** Valeur d'une commande, ou un tiret cadratin si elle n'existe pas. */
    private function widgetValue($_logicalId, $_default = '—') {
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return $_default;
        }
        $value = $cmd->execCmd();
        return ($value === null || $value === '') ? $_default : $value;
    }

    /** Valeur numérique d'une commande, ou null si absente ou non numérique. */
    private function widgetNumber($_logicalId) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return null;
        }
        $value = $cmd->execCmd();
        return is_numeric($value) ? floatval($value) : null;
    }

    /** Formatage d'une température, tiret cadratin si la mesure manque. */
    private function widgetTemp($_logicalId, $_unit = ' °C') {
        $value = $this->widgetNumber($_logicalId);
        return ($value === null) ? '—' : number_format($value, 1, ',', ' ') . $_unit;
    }

    /**
     * Trouve le premier composant présent d'une famille : hk1, hk2… ou ww1, ww2…
     * La configuration matérielle n'étant jamais codée en dur, le widget doit
     * s'adapter à une installation dont le circuit ne s'appellerait pas « hk1 ».
     */
    private function firstComponent($_prefix, $_max, $_probeVariable) {
        for ($i = 1; $i <= $_max; $i++) {
            if (is_object($this->getCmd(null, $_prefix . $i . '_' . $_probeVariable))) {
                return $_prefix . $i;
            }
        }
        return $_prefix . '1';
    }

    /**
     * Rangée de boutons pour une commande à liste déroulante (les modes).
     *
     * Le bouton correspondant à la valeur courante est mis en évidence, ce qui évite
     * d'avoir à afficher le mode séparément.
     */
    private function widgetModeButtons($_actionLogicalId, $_infoLogicalId) {
        $cmd = $this->getCmd(null, $_actionLogicalId);
        if (!is_object($cmd)) {
            return '';
        }
        $list = $cmd->getConfiguration('listValue', '');
        if (trim($list) === '') {
            return '';
        }
        $current = $this->widgetValue($_infoLogicalId, null);

        $buttons = '';
        foreach (explode(';', $list) as $entry) {
            $parts = explode('|', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $label = trim($parts[1]);
            $active = ($current !== null && (string) $current === $key) ? ' active' : '';
            $buttons .= '<button type="button" class="ok-btn' . $active . '"'
                . ' onclick="jeedom.cmd.execute({id:' . intval($cmd->getId())
                . ',options:{select:\'' . htmlspecialchars($key, ENT_QUOTES) . '\'}})">'
                . htmlspecialchars($label) . '</button>';
        }
        return ($buttons === '') ? '' : '<div class="ok-btns">' . $buttons . '</div>';
    }

    /**
     * Valeur réglable par deux boutons − et +.
     *
     * Les valeurs cibles sont calculées ici, au rendu, et non en JavaScript : un bloc
     * <script> inséré dynamiquement dans un tableau de bord ne s'exécute pas de façon
     * garantie, alors qu'un gestionnaire « onclick » fonctionne toujours. Les bornes
     * viennent de la chaudière, via la configuration de la commande.
     *
     * La commande action attend la valeur en unité affichée : execute() applique
     * l'inverse du facteur avant l'envoi.
     */
    private function widgetStepper($_actionLogicalId, $_infoLogicalId, $_step = 0.5, $_unit = ' °C') {
        $current = $this->widgetNumber($_infoLogicalId);
        $cmd = $this->getCmd(null, $_actionLogicalId);
        $shown = ($current === null) ? '—' : number_format($current, 1, ',', ' ') . $_unit;

        if (!is_object($cmd) || $current === null) {
            // Pas de commande action, ou pas de mesure : on affiche sans prétendre régler.
            return '<span class="val v-mute">' . $shown . '</span>';
        }

        $min = $cmd->getConfiguration('minValue', '');
        $max = $cmd->getConfiguration('maxValue', '');
        $down = $current - $_step;
        $up = $current + $_step;
        $downOff = ($min !== '' && $min !== null && $down < floatval($min));
        $upOff = ($max !== '' && $max !== null && $up > floatval($max));

        $button = function ($_target, $_symbol, $_disabled) use ($cmd) {
            if ($_disabled) {
                return '<button type="button" class="ok-pm" disabled>' . $_symbol . '</button>';
            }
            return '<button type="button" class="ok-pm"'
                . ' onclick="jeedom.cmd.execute({id:' . intval($cmd->getId())
                . ',options:{slider:' . number_format($_target, 1, '.', '') . '}})">'
                . $_symbol . '</button>';
        };

        return '<span class="ok-step">'
            . $button($down, '&minus;', $downOff)
            . '<span class="val v-blue">' . $shown . '</span>'
            . $button($up, '+', $upOff)
            . '</span>';
    }

    /** Bouton déclenchant une commande action sans paramètre. */
    private function widgetActionButton($_logicalId, $_label, $_class = '') {
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return '';
        }
        return '<button type="button" class="ok-btn ' . $_class . '"'
            . ' onclick="jeedom.cmd.execute({id:' . intval($cmd->getId()) . '})">'
            . htmlspecialchars($_label) . '</button>';
    }

    /** Bouton demandant une valeur à l'utilisateur avant d'exécuter la commande. */
    private function widgetPromptButton($_logicalId, $_label, $_question) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return '';
        }
        $question = htmlspecialchars($_question, ENT_QUOTES);
        return '<button type="button" class="ok-btn"'
            . ' onclick="var v=window.prompt(\'' . $question . '\');'
            . 'if(v){jeedom.cmd.execute({id:' . intval($cmd->getId()) . ',options:{message:v}});}">'
            . htmlspecialchars($_label) . '</button>';
    }

    /**
     * Widget de tableau de bord.
     *
     * Toute erreur de rendu retombe sur le widget standard de Jeedom : un tableau de
     * bord cassé serait un prix disproportionné pour un affichage d'agrément.
     */
    public function toHtml($_version = 'dashboard') {
        try {
            $replace = $this->preToHtml($_version);
            if (!is_array($replace)) {
                return $replace;
            }

            $hk = $this->firstComponent('hk', 6, 'L_flowtemp_act');
            $ww = $this->firstComponent('ww', 3, 'L_ontemp_act');

            // --- État de la chaudière ---------------------------------------
            $stateCode = $this->widgetNumber('pe1_L_state');
            $stateText = $this->widgetValue('pe1_L_statetext', __('État inconnu', __FILE__));
            $faultActive = (intval($this->widgetValue('error_active', 0)) === 1);
            $connected = (intval($this->widgetValue('conn_ok', 0)) === 1);

            $stateColor = '#8CC63F';
            $stateDot = '';
            if (!$connected) {
                $stateColor = '#8FA391';
                $stateDot = 'bad';
                $stateText = __('Chaudière injoignable', __FILE__);
            } elseif ($faultActive || ($stateCode !== null && intval($stateCode) === self::STATE_ERROR)) {
                $stateColor = '#E8543C';
                $stateDot = 'bad';
            } elseif ($stateCode !== null && in_array(intval($stateCode), array(self::STATE_ASH, self::STATE_PELLETS))) {
                // Demande de maintenance : ni une panne, ni un fonctionnement normal.
                $stateColor = '#F0A030';
                $stateDot = 'warn';
            }

            $replace['#state_code#'] = ($stateCode === null) ? '?' : intval($stateCode);
            $replace['#state_text#'] = $stateText;
            $replace['#state_color#'] = $stateColor;
            $replace['#state_dot#'] = $stateDot;
            // L'anneau se remplit à la modulation : un cercle plein serait décoratif,
            // celui-ci porte une information.
            $modulation = $this->widgetNumber('pe1_L_modulation');
            $modulation = ($modulation === null) ? 0 : max(0, min(100, $modulation));
            $replace['#modulation#'] = intval(round($modulation));
            $replace['#state_offset#'] = round(326.7 * (1 - ($modulation / 100)), 1);

            // --- Chaudière ---------------------------------------------------
            $replace['#temp_act#'] = $this->widgetTemp('pe1_L_temp_act');
            $replace['#temp_set#'] = $this->widgetTemp('pe1_L_temp_set');
            $runtime = $this->widgetNumber('pe1_L_runtime');
            $replace['#runtime#'] = ($runtime === null) ? '—' : number_format($runtime, 0, ',', ' ');

            // --- Circuit de chauffage ---------------------------------------
            $replace['#hk_comfort_ctl#'] = $this->widgetStepper('set_' . $hk . '_temp_heat', $hk . '_temp_heat');
            $replace['#hk_setback_ctl#'] = $this->widgetStepper('set_' . $hk . '_temp_setback', $hk . '_temp_setback');
            $replace['#hk_mode_buttons#'] = $this->widgetModeButtons('set_' . $hk . '_mode_auto', $hk . '_mode_auto');
            $replace['#hk_flow#'] = $this->widgetTemp($hk . '_L_flowtemp_act');
            $pump = $this->widgetValue($hk . '_L_pump', null);
            if ($pump === null) {
                $replace['#hk_pump#'] = '—';
                $replace['#hk_pump_class#'] = 'v-mute';
            } else {
                $running = (okofenApi::boolToInt($pump) === 1);
                $replace['#hk_pump#'] = $running ? __('En marche', __FILE__) : __('Arrêt', __FILE__);
                $replace['#hk_pump_class#'] = $running ? 'v-green' : 'v-mute';
            }

            // --- Eau chaude sanitaire ---------------------------------------
            $replace['#ww_set_ctl#'] = $this->widgetStepper('set_' . $ww . '_temp_max_set', $ww . '_temp_max_set');
            $replace['#ww_min_ctl#'] = $this->widgetStepper('set_' . $ww . '_temp_min_set', $ww . '_temp_min_set');
            $replace['#ww_state#'] = $this->widgetValue($ww . '_L_statetext');

            // Les modes ECS, plus le bouton de chauffe ponctuelle : « heat_once » est un
            // booléen, il a donc deux commandes action distinctes (_on et _off).
            $wwButtons = $this->widgetModeButtons('set_' . $ww . '_mode_auto', $ww . '_mode_auto');
            $heatOnce = $this->widgetActionButton('set_' . $ww . '_heat_once_on', __('Chauffe unique', __FILE__), 'accent');
            if ($heatOnce !== '') {
                $wwButtons = ($wwButtons === '')
                    ? '<div class="ok-btns">' . $heatOnce . '</div>'
                    : substr($wwButtons, 0, -6) . $heatOnce . '</div>';
            }
            $replace['#ww_mode_buttons#'] = $wwButtons;

            $wwAct = $this->widgetNumber($ww . '_L_ontemp_act');
            $replace['#ww_act_raw#'] = ($wwAct === null) ? '—' : number_format($wwAct, 1, ',', ' ');

            // L'anneau se remplit entre les deux seuils configurés, pas de 0 à 100 :
            // c'est la plage qui a un sens pour l'utilisateur.
            $wwMin = $this->widgetNumber($ww . '_temp_min_set');
            $wwMax = $this->widgetNumber($ww . '_temp_max_set');
            $ratio = 0;
            if ($wwAct !== null && $wwMin !== null && $wwMax !== null && $wwMax > $wwMin) {
                $ratio = max(0, min(1, ($wwAct - $wwMin) / ($wwMax - $wwMin)));
            }
            $replace['#ww_offset#'] = round(301.6 * (1 - $ratio), 1);

            // --- Silo --------------------------------------------------------
            $pct = $this->widgetNumber('pellet_stock_pct');
            $pct = ($pct === null) ? 0 : max(0, min(100, $pct));
            $replace['#silo_pct#'] = intval(round($pct));
            $kg = $this->widgetNumber('pellet_stock_kg');
            $replace['#silo_kg#'] = ($kg === null) ? '—' : number_format($kg, 0, ',', ' ');
            $days = $this->widgetNumber('pellet_autonomy_days');
            $replace['#silo_days#'] = ($days === null)
                ? __('indéterminée', __FILE__)
                : '~ ' . intval(round($days)) . __(' jours', __FILE__);
            // La zone remplissable du dessin va de y=22 à y=100, soit 78 unités.
            $height = round(78 * ($pct / 100), 1);
            $replace['#silo_h#'] = $height;
            $replace['#silo_y#'] = round(100 - $height, 1);

            // --- Défauts -----------------------------------------------------
            if ($faultActive) {
                $replace['#fault_color#'] = '#E8543C';
                $replace['#fault_path#'] = 'M60 34v34M60 82v4';
                $replace['#fault_title#'] = __('Défaut en cours', __FILE__);
                $replace['#fault_text#'] = $this->widgetValue('error_text', __('Consultez la chaudière', __FILE__));
            } else {
                $replace['#fault_color#'] = '#8CC63F';
                $replace['#fault_path#'] = 'M40 61l14 15 27-30';
                $replace['#fault_title#'] = __('Aucun défaut', __FILE__);
                $replace['#fault_text#'] = __('Tout fonctionne correctement', __FILE__);
            }

            // --- Pied --------------------------------------------------------
            $replace['#conn_text#'] = $connected ? __('OK', __FILE__) : __('perdue', __FILE__);
            $replace['#api_host#'] = $this->getConfiguration('ip', '—') . ':' . $this->getConfiguration('port', 4321);
            $lastPoll = intval($this->getCache('lastPoll', 0));
            $replace['#updated#'] = ($lastPoll > 0) ? date('H:i:s', $lastPoll) : '—';
            $replace['#now_time#'] = date('H:i');
            $replace['#now_date#'] = date('d/m/Y');

            // --- Contrôles restants ------------------------------------------
            $replace['#pe_mode_buttons#'] = $this->widgetModeButtons('set_pe1_mode', 'pe1_mode');

            $silo = $this->widgetPromptButton('pellet_add_delivery', __('Remplissage', __FILE__), __('Quantité livrée, en kg ?', __FILE__))
                . $this->widgetPromptButton('pellet_set_stock', __('Corriger', __FILE__), __('Stock réel, en kg ?', __FILE__))
                . $this->widgetActionButton('ash_reset', __('Cendres vidées', __FILE__));
            $replace['#silo_buttons#'] = ($silo === '') ? '' : '<div class="ok-btns">' . $silo . '</div>';

            $refresh = $this->widgetActionButton('refresh', __('Rafraîchir', __FILE__));
            $replace['#refresh_button#'] = ($refresh === '') ? '' : '<div class="ok-btns">' . $refresh . '</div>';

            return $this->postToHtml($_version, template_replace($replace, getTemplate('core', 'dashboard', 'okofen', 'okofen')));
        } catch (Throwable $e) {
            // Throwable et non Exception : c'est précisément une Error PHP 7+ — un appel
            // à une méthode inexistante — qui avait produit un HTTP 500 en 1.0.5.
            log::add('okofen', 'error', 'Rendu du widget impossible, retour au widget standard : ' . $e->getMessage());
            return parent::toHtml($_version);
        }
    }
}

class okofenCmd extends cmd {

    /**
     * Récupère la valeur saisie pour une commande de type message.
     *
     * Jeedom ne transmet pas toujours la saisie sous la même clé selon le point
     * d'appel — widget de commande du tableau de bord, tuile du plugin, scénario,
     * API. Parier sur la seule clé « message » a produit deux échecs : un remplissage
     * refusé et, plus grave, un stock remis à zéro. On accepte donc plusieurs clés.
     *
     * Les options brutes sont tracées en debug : c'est l'instrumentation qui a permis
     * de trancher les diagnostics précédents plutôt que d'accumuler les hypothèses.
     */
    private static function optionValue($_options) {
        log::add('okofen', 'debug', 'Options reçues : ' . json_encode($_options, JSON_UNESCAPED_UNICODE));

        if (is_scalar($_options)) {
            return trim((string) $_options);
        }
        if (!is_array($_options)) {
            return '';
        }
        foreach (array('message', 'value', 'slider', 'title', 'select') as $key) {
            if (isset($_options[$key]) && is_scalar($_options[$key]) && trim((string) $_options[$key]) !== '') {
                return trim((string) $_options[$key]);
            }
        }
        return '';
    }

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        /** @var okofen $eqLogic */
        $eqLogic = $this->getEqLogic();
        $logicalId = $this->getLogicalId();

        // --- Actions internes du plugin -----------------------------------
        switch ($logicalId) {
            case 'refresh':
                $eqLogic->refresh();
                return;
            case 'ash_reset':
                $eqLogic->declareAshEmptied();
                return;
            case 'pellet_add_delivery':
                $eqLogic->declareDelivery(self::optionValue($_options));
                return;
            case 'pellet_set_stock':
                $eqLogic->setStock(self::optionValue($_options));
                return;
        }

        // --- Écriture d'une variable de la chaudière ------------------------
        $component = $this->getConfiguration('component');
        $variable = $this->getConfiguration('variable');
        if ($component === '' || $variable === '') {
            throw new Exception(__('Commande action mal configurée : ', __FILE__) . $logicalId);
        }
        $fullName = $component . '_' . $variable;
        $writeMode = $this->getConfiguration('writeMode', 'fixed');

        switch ($writeMode) {
            case 'fixed':
                $value = $this->getConfiguration('fixedValue', '');
                break;
            case 'select':
                $value = isset($_options['select']) ? $_options['select'] : null;
                break;
            case 'slider':
                // L'utilisateur manipule des unités affichées ; la chaudière attend
                // des valeurs brutes. On applique donc l'inverse du facteur.
                $factor = floatval($this->getConfiguration('factor', 1));
                if ($factor == 0) {
                    $factor = 1;
                }
                $raw = isset($_options['slider']) ? floatval(str_replace(',', '.', $_options['slider'])) : null;
                $value = ($raw === null) ? null : intval(round($raw / $factor));
                break;
            case 'message':
                $value = self::optionValue($_options);
                break;
            default:
                $value = null;
        }

        if ($value === null || $value === '') {
            throw new Exception(__('Aucune valeur fournie pour la commande ', __FILE__) . $logicalId);
        }

        $eqLogic->getApi()->write($fullName, $value);

        // La fenêtre de débit impose déjà une attente avant la requête suivante : la
        // relecture laisse donc à la chaudière le temps de refléter le changement,
        // sans qu'il soit besoin d'un sleep() supplémentaire.
        $eqLogic->refresh();
        $this->verifyApplied($eqLogic, $fullName, $value);
    }

    /**
     * Contrôle qu'une écriture a réellement été prise en compte.
     *
     * L'écho renvoyé par la chaudière ne prouve rien : constaté sur
     * « pe1_storage_fill_yesterday », qui répond l'écho attendu et laisse la valeur
     * inchangée. Seule la relecture fait foi.
     *
     * Un écart n'est pas nécessairement un échec : la chaudière borne les consignes
     * hors plage. Le message dit donc ce qui a été retenu, sans conclure à sa place.
     */
    private function verifyApplied($_eqLogic, $_fullName, $_expected) {
        $infoCmd = $_eqLogic->getCmd(null, $_fullName);
        if (!is_object($infoCmd)) {
            return;
        }
        $actual = $infoCmd->execCmd();

        $factor = floatval($this->getConfiguration('factor', 1));
        if ($factor == 0) {
            $factor = 1;
        }

        if (okofenApi::isBooleanValue($_expected)) {
            $applied = (okofenApi::boolToInt($actual) === okofenApi::boolToInt($_expected));
            $expectedShown = okofenApi::boolToInt($_expected);
        } elseif (is_numeric($_expected) && is_numeric($actual)) {
            // La commande info porte la valeur mise à l'échelle, l'écriture la valeur brute.
            $expectedShown = round(floatval($_expected) * $factor, 2);
            $applied = (abs(floatval($actual) - $expectedShown) < 0.01);
        } else {
            $expectedShown = $_expected;
            $applied = (trim((string) $actual) === trim((string) $_expected));
        }

        if ($applied) {
            log::add('okofen', 'info', 'Écriture confirmée par relecture : ' . $_fullName . ' = ' . $actual);
            return;
        }

        $message = __('La chaudière n\'a pas retenu la valeur demandée pour « ', __FILE__)
            . $_fullName . __(' » : demandé ', __FILE__) . $expectedShown
            . __(', relu ', __FILE__) . $actual
            . __('. La valeur a pu être bornée hors plage, ou l\'écriture refusée sans le dire.', __FILE__);
        log::add('okofen', 'warning', $message);
        message::add('okofen', $message);
    }
}
