<?php
/*
 * Couche de communication avec l'API JSON locale ÖkoFEN (Pelletronic Touch).
 *
 * Particularités de cette API, vérifiées sur une Pellematic SMART XS :
 *
 *  1. Un mot de passe invalide ne renvoie PAS d'erreur HTTP : la chaudière répond
 *     200 OK avec sa page d'aide. On détecte donc l'échec d'authentification au
 *     contenu (marqueur HELP_MARKER), pas au code HTTP.
 *  2. La chaudière n'émet QUE de l'ASCII : chaque caractère accentué est remplacé par
 *     « ? » à la source (« Mode arrêt » devient « Mode arr?t »). Vérifié en
 *     hexadécimal — aucun octet ≥ 0x80 dans ses réponses. Ce n'est donc pas un
 *     problème d'encodage : l'information est perdue avant l'envoi, et seule une
 *     table de correspondance peut la restituer (voir restoreAccents()).
 *  3. La valeur -32768 est une sentinelle signifiant « capteur absent »
 *     (typiquement L_ext_temp quand aucune sonde extérieure n'est câblée).
 *     Non filtrée, elle s'affiche en -3276,8 °C.
 *  4. Une écriture ne renvoie pas de JSON mais l'écho de la commande envoyée
 *     (ex. « hk1_temp_heat=200 »). C'est l'écho qui atteste du succès.
 *  5. Le suffixe « ? », qui demande les métadonnées (unité, facteur, bornes, format),
 *     n'est accepté que sur « all ». Sur un composant seul — « pe1? » — la chaudière
 *     répond par sa page d'aide.
 *  6. La page d'aide n'arrive pas en tête de réponse : un « { » la précède. Elle se
 *     détecte donc par recherche dans tout le corps, pas sur son début.
 */

class okofenApi {

    /** Début de la page d'aide renvoyée quand le mot de passe est refusé. */
    const HELP_MARKER = 'http://www.oekofen.at';

    /** Valeur renvoyée par la chaudière pour un capteur absent. */
    const SENTINEL_NA = -32768;

    /**
     * Fenêtre minimale imposée par la chaudière entre deux requêtes, en secondes.
     *
     * Les 401 ne sont pas « sporadiques » : c'est une LIMITATION DE DÉBIT. Deux sources
     * indépendantes le confirment — le plugin MyOkoTouch gère « le délai minimum
     * obligatoire de 2500 ms entre deux appels », et la bibliothèque pyokofen documente
     * « a soft limitation of 1 request per 10 seconds ». Nos propres logs concordent :
     * une requête refusée deux fois n'aboutit qu'environ 2,5 s après la précédente.
     *
     * Réessayer sans attendre revient donc à bombarder une API qui demande qu'on la
     * laisse respirer : on finissait par réussir par accident, au bout de trois essais.
     * Une marge est prise sur les 2500 ms annoncés.
     */
    const MIN_INTERVAL = 2.6;

    /** Clé de cache portant l'horodatage de la dernière requête, tous processus confondus. */
    const LAST_REQUEST_KEY = 'okofen::lastRequest';

    /**
     * Nombre de tentatives par requête. Devenu un simple filet de sécurité depuis que la
     * fenêtre de débit est respectée : un échec signale désormais un vrai problème
     * (chaudière éteinte, réseau coupé) et non un refus de cadence.
     */
    const MAX_ATTEMPTS = 3;

    /** Pause avant un réessai, en microsecondes. Alignée sur la fenêtre de débit. */
    const RETRY_DELAY_US = 2600000;

    private $ip;
    private $port;
    private $password;
    private $timeout;

    public function __construct($_ip, $_port = 4321, $_password = '', $_timeout = 10) {
        $this->ip = trim($_ip);
        $this->port = intval($_port) > 0 ? intval($_port) : 4321;
        $this->password = trim($_password);
        $this->timeout = intval($_timeout) > 0 ? intval($_timeout) : 10;
    }

    public function getBaseUrl() {
        return 'http://' . $this->ip . ':' . $this->port . '/' . rawurlencode($this->password) . '/';
    }

    /**
     * Attend que la fenêtre de débit soit écoulée depuis la dernière requête, puis
     * réserve immédiatement le créneau suivant.
     *
     * L'horodatage passe par le cache de Jeedom et non par une variable statique : le
     * cron, les pages web et les appels ajax sont des processus PHP distincts, qui ne
     * partagent aucune mémoire. Sans cela, une action lancée depuis l'interface pendant
     * une relève automatique repartirait de zéro et se ferait refuser.
     */
    private static function throttle() {
        $last = floatval(cache::byKey(self::LAST_REQUEST_KEY)->getValue(0));
        $wait = ($last + self::MIN_INTERVAL) - microtime(true);
        if ($wait > 0) {
            // Borne de sécurité : une horloge qui recule ne doit pas figer le plugin.
            usleep((int) (min($wait, self::MIN_INTERVAL) * 1000000));
        }
        cache::set(self::LAST_REQUEST_KEY, microtime(true));
    }

    /**
     * Exécute une requête brute et renvoie le corps de la réponse converti en UTF-8.
     * Lève une exception en cas d'erreur réseau ou de refus d'authentification.
     */
    private function rawRequest($_path) {
        if ($this->ip === '') {
            throw new Exception(__('Adresse IP de la chaudière non renseignée.', __FILE__));
        }
        if ($this->password === '') {
            throw new Exception(__('Mot de passe JSON non renseigné.', __FILE__));
        }

        $url = $this->getBaseUrl() . $_path;
        log::add('okofen', 'debug', 'Requête : ' . $this->maskUrl($url));

        $lastError = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            self::throttle();
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                $lastError = __('Chaudière injoignable : ', __FILE__) . $error;
            } elseif ($httpCode === 200) {
                // Piège n°1 : la chaudière renvoie sa page d'aide, en 200 OK, aussi bien
                // pour un mot de passe refusé que pour une commande qu'elle ne comprend
                // pas. Le marqueur n'est PAS en tête de réponse — un « { » le précède —
                // d'où une recherche sur l'ensemble du corps et non sur son début.
                if (strpos($body, self::HELP_MARKER) !== false) {
                    throw new Exception(__('La chaudière a répondu par sa page d\'aide : mot de passe JSON refusé, ou commande non reconnue (« ', __FILE__) . $_path . __(' »). Le mot de passe se relève dans Menu > Réglages généraux > Touch/JSON.', __FILE__));
                }
                if ($attempt > 1) {
                    log::add('okofen', 'debug', 'Requête aboutie au bout de ' . $attempt . ' tentatives.');
                }
                // Piège n°2 : conversion d'encodage, puis restitution des accents.
                self::logEncodingSample($body);
                return self::restoreAccents(self::toUtf8($body));
            } else {
                // Piège n°5 : un 401 avec un mot de passe valide signale un dépassement
                // de cadence, pas un refus d'authentification — celui-ci se manifeste
                // par la page d'aide, traitée plus haut. Depuis que la fenêtre de débit
                // est respectée, ce cas doit être devenu rare : s'il persiste, la
                // fenêtre est trop courte pour ce modèle de chaudière.
                if ($httpCode === 401) {
                    log::add('okofen', 'debug', 'Refus 401 malgré la fenêtre de '
                        . self::MIN_INTERVAL . ' s — cadence peut-être encore trop rapide.');
                }
                $lastError = __('Réponse HTTP inattendue : ', __FILE__) . $httpCode;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                log::add('okofen', 'debug', 'Tentative ' . $attempt . ' échouée (' . $lastError . '), nouvel essai.');
                usleep(self::RETRY_DELAY_US);
            }
        }

        throw new Exception($lastError . __(' (après ', __FILE__) . self::MAX_ATTEMPTS . __(' tentatives)', __FILE__));
    }

    /**
     * Lecture d'un composant (all, system, hk1, ww1, pe1, error...).
     * $_withMeta ajoute le suffixe « ? » qui fait renvoyer unité, facteur, bornes et énumérations.
     */
    public function read($_component = 'all', $_withMeta = false) {
        $body = $this->rawRequest($_component . ($_withMeta ? '?' : ''));
        $data = json_decode($body, true);
        if (!is_array($data)) {
            log::add('okofen', 'error', 'Réponse non JSON : ' . substr($body, 0, 200));
            throw new Exception(__('Réponse illisible de la chaudière (JSON invalide).', __FILE__));
        }
        return $data;
    }

    /**
     * Écriture d'une variable. $_variable est le nom complet, préfixé du composant,
     * par exemple « hk1_mode_auto » ou « pe1_mode ».
     * Renvoie true si la chaudière a renvoyé l'écho attendu.
     */
    public function write($_variable, $_value) {
        if (strpos($_variable, 'L_') === 0 || strpos($_variable, '_L_') !== false) {
            throw new Exception(__('Variable en lecture seule (préfixe L_) : ', __FILE__) . $_variable);
        }
        $payload = $_variable . '=' . $_value;
        $body = trim($this->rawRequest($payload));

        // Piège n°4 : le succès se lit dans l'écho, pas dans un code de retour.
        $expected = $_variable . '=' . $_value;
        if ($body === $expected) {
            log::add('okofen', 'info', 'Écriture acceptée : ' . $expected);
            return true;
        }
        log::add('okofen', 'warning', 'Écriture « ' . $expected . ' » : réponse inattendue « ' . substr($body, 0, 200) . ' »');
        // Certaines versions renvoient la variable sans la valeur, ou avec la valeur bornée.
        return (strpos($body, $_variable) === 0);
    }

    /** Test de connexion : renvoie un libellé décrivant la chaudière trouvée. */
    public function testConnection() {
        $data = $this->read('system');
        if (!isset($data['system'])) {
            throw new Exception(__('Réponse valide mais composant « system » absent.', __FILE__));
        }
        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* Utilitaires                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Conversion vers UTF-8, sans double encodage si la source l'est déjà.
     *
     * L'encodage réellement émis par la chaudière n'est pas garanti : on le détecte
     * au lieu de le supposer, en n'acceptant que des jeux plausibles. Un repli sur
     * ISO-8859-1 couvre le cas indéterminé, qui reste l'encodage documenté de l'API.
     */
    public static function toUtf8($_string) {
        if (!is_string($_string) || $_string === '') {
            return $_string;
        }
        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($_string, array('UTF-8', 'Windows-1252', 'ISO-8859-1'), true);
            if ($detected === 'UTF-8') {
                return $_string;
            }
            if ($detected !== false) {
                return mb_convert_encoding($_string, 'UTF-8', $detected);
            }
            return mb_convert_encoding($_string, 'UTF-8', 'ISO-8859-1');
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($_string, 'UTF-8')) {
            return $_string;
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($_string, 'UTF-8', 'ISO-8859-1');
        }
        return $_string;
    }

    /**
     * Restitue les accents que la chaudière a détruits.
     *
     * Constaté sur l'installation réelle : la chaudière n'émet QUE de l'ASCII et
     * remplace chaque caractère accentué par « ? » avant l'envoi (vérifié en
     * hexadécimal — aucun octet ≥ 0x80 dans ses réponses). L'information est donc
     * perdue à la source : aucune conversion d'encodage ne peut la reconstituer,
     * seule une table du vocabulaire connu le permet.
     *
     * Limite assumée : toute chaîne absente de cette table conservera ses « ? ».
     * Les entrées sont ordonnées du plus spécifique au plus général — « Mise ? l'arr?t »
     * doit être traité avant « arr?t ».
     */
    public static function restoreAccents($_string) {
        if (!is_string($_string) || strpos($_string, '?') === false) {
            return $_string;
        }
        $map = array(
            // États de la chaudière
            'Mise ? l\'arr?t' => 'Mise à l\'arrêt',
            'R?amor?age' => 'Réamorçage',
            'D?marrage' => 'Démarrage',
            '?talonner' => 'Étalonner',
            'S?curit?' => 'Sécurité',
            'Pr?chauffage' => 'Préchauffage',
            'D?cendrage' => 'Décendrage',
            // Modes
            'Marche forc?e' => 'Marche forcée',
            'Interm?diaire' => 'Intermédiaire',
            '?cologique' => 'Écologique',
            'R?duit' => 'Réduit',
            'Mode arr?t' => 'Mode arrêt',
            'Arr?t' => 'Arrêt',
            'arr?t' => 'arrêt',
            // Vocabulaire courant
            'Chaudi?re' => 'Chaudière',
            'D?faut' => 'Défaut',
            'd?faut' => 'défaut',
            'Ext?rieur' => 'Extérieur',
            'ext?rieure' => 'extérieure',
            'R?servoir' => 'Réservoir',
            '?nergie' => 'Énergie',
            'Vacances' => 'Vacances',
            // Unités
            '?C' => '°C',
        );
        return str_replace(array_keys($map), array_values($map), $_string);
    }

    /**
     * Trace en hexadécimal le premier caractère non-ASCII de la réponse.
     *
     * Sans accès direct à la chaudière, c'est le seul moyen de distinguer une réponse
     * ISO-8859-1 (« ê » = E9) d'une réponse UTF-8 (« ê » = C3 AA) d'une réponse déjà
     * translittérée par la chaudière elle-même (« ê » = 3F, le point d'interrogation).
     */
    private static function logEncodingSample($_body) {
        if (!preg_match('/[\x80-\xFF]/', $_body, $matches, PREG_OFFSET_CAPTURE)) {
            log::add('okofen', 'debug', 'Encodage : aucun octet non-ASCII dans la réponse.');
            return;
        }
        $position = $matches[0][1];
        $extract = substr($_body, max(0, $position - 6), 16);
        $hex = '';
        for ($i = 0; $i < strlen($extract); $i++) {
            $hex .= sprintf('%02X ', ord($extract[$i]));
        }
        log::add('okofen', 'debug', 'Encodage : extrait « ' . $extract .' » = ' . trim($hex));
    }

    /** Vrai si la valeur brute correspond à un capteur absent. */
    public static function isUnavailable($_rawValue) {
        return (is_numeric($_rawValue) && intval($_rawValue) === self::SENTINEL_NA);
    }

    /** Masque le mot de passe dans les URL écrites au log. */
    private function maskUrl($_url) {
        if ($this->password === '') {
            return $_url;
        }
        return str_replace('/' . rawurlencode($this->password) . '/', '/********/', $_url);
    }

    /**
     * Transforme une chaîne de format ÖkoFEN « 0:Arrêt|1:Auto|2:Confort »
     * en tableau associatif [0 => 'Arrêt', 1 => 'Auto', 2 => 'Confort'].
     */
    public static function parseFormat($_format) {
        $result = array();
        if (!is_string($_format) || $_format === '') {
            return $result;
        }
        foreach (explode('|', $_format) as $entry) {
            $parts = explode(':', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $label = trim($parts[1]);
            if ($key === '' || !is_numeric($key)) {
                continue;
            }
            $result[intval($key)] = $label;
        }
        return $result;
    }

    /**
     * Un format ne décrit un booléen que s'il ne comporte que deux entrées 0 et 1.
     * On s'appuie surtout sur la valeur ("true"/"false") pour trancher.
     */
    public static function isBooleanValue($_value) {
        return ($_value === 'true' || $_value === 'false' || $_value === true || $_value === false);
    }

    public static function boolToInt($_value) {
        return ($_value === 'true' || $_value === true || $_value === '1' || $_value === 1) ? 1 : 0;
    }
}
