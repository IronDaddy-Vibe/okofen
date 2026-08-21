# Plugin ÖkoFEN

Supervision et pilotage des chaudières à granulés ÖkoFEN (Pellematic / Pelletronic Touch)
depuis Jeedom, via l'**API JSON locale** de la chaudière.

## Prérequis

L'API JSON doit être activée sur la chaudière et protégée par un mot de passe :

> Écran tactile → **Menu → Réglages généraux → Touch / JSON**

Relevez-y le **mot de passe JSON** et le **port** (4321 par défaut).

> **Attention :** ce mot de passe est distinct de celui de l'interface web / Okovision.
> Utiliser le mauvais donne une erreur d'authentification.

## Configuration d'un équipement

| Champ | Rôle |
|---|---|
| Adresse IP | adresse locale de la chaudière |
| Port JSON | 4321 par défaut |
| Mot de passe JSON | code relevé sur l'écran tactile |
| Intervalle d'interrogation | fréquence de relève, en minutes |
| Capacité du silo | en kg ; vide = reprend la valeur déclarée par la chaudière |
| Source du niveau de stock | compteur de la chaudière, ou comptabilité du plugin |
| Puissance nominale | sert à estimer la consommation |
| Facteur de correction | recalage de l'estimation |

Le bouton **Tester la connexion** valide l'accès et affiche le modèle détecté.

## Synchronisation des commandes

Le bouton **Synchroniser les commandes** interroge la chaudière et crée automatiquement
les commandes correspondant aux composants réellement présents : circuits de chauffage
(`hk1` à `hk6`), ballons d'eau chaude (`ww1` à `ww3`), ballons tampon (`pu`), circuits
solaires (`sk`), et la chaudière elle-même (`pe`).

Rien n'est codé en dur : si vous ajoutez un second circuit de chauffage plus tard, il
apparaîtra à la synchronisation suivante.

### Informations et commandes

La règle de l'API ÖkoFEN est simple : **les variables préfixées `L_` sont en lecture
seule**, les autres sont modifiables. Le plugin applique cette règle :

- variable `L_*` → une commande **info**
- variable modifiable → une commande **info** plus une commande **action**
  (liste déroulante, curseur ou boutons selon le type)

Les unités, facteurs d'échelle et bornes min/max sont **lus depuis la chaudière**, jamais
supposés.

### Principales commandes d'action

| Commande | Effet |
|---|---|
| `pe1_mode` | Arrêt / Auto / Marche forcée de la chaudière |
| `hk1_mode_auto` | Arrêt / Auto / Confort / Réduit du circuit de chauffage |
| `ww1_mode_auto` | Arrêt / Auto / Marche forcée de l'eau chaude sanitaire |
| `ww1_heat_once` | déclenche une chauffe ECS ponctuelle |
| `hk1_temp_heat` | consigne de confort |
| `ww1_temp_min_set` / `ww1_temp_max_set` | seuils d'enclenchement et d'arrêt ECS |

## Stock de pellets

Deux modes, au choix dans la configuration de l'équipement.

**Comptabilité du plugin** (par défaut) — le plugin tient seul son décompte : vous
déclarez chaque livraison dans l'onglet **Maintenance** (ou via `pellet_add_delivery`),
et la consommation estimée est déduite au fil de l'eau. La commande `pellet_set_stock`
permet de recaler le stock à tout moment.

**Compteur de la chaudière** — le plugin lit `pe1.L_storage_fill`, que la chaudière
calcule à partir du fonctionnement de la vis d'alimentation.

> **Attention :** ce compteur décompte, mais ne se recharge pas tout seul. Il n'est
> exploitable que si votre installation offre un moyen de le remettre à niveau après une
> livraison (saisie du remplissage sur l'écran tactile ou dans l'interface web). Faute de
> quoi il reste bloqué à zéro une fois le silo vidé, et ce mode ne sert à rien. En cas de
> doute, restez sur la comptabilité du plugin.

Le mode « chaudière » ignore un compteur à zéro et retombe alors sur la comptabilité
interne, de sorte qu'un mauvais choix ne fait rien perdre.

### Estimation de la consommation

Elle tourne dans les deux modes, car elle alimente le suivi quotidien et le calcul
d'autonomie.

La chaudière module sa puissance en continu selon le besoin. Calculer la consommation à
la puissance nominale sur les heures de fonctionnement la surestimerait nettement. Le
plugin intègre donc la **puissance réellement délivrée**, échantillonnée à chaque relève :

`kg = durée écoulée (h) × puissance nominale (kW) × modulation (%) × correction ÷ 4,8 kWh/kg`

La modulation valant 0 à l'arrêt, les périodes d'inactivité ne consomment rien
naturellement. L'intervalle pris en compte est plafonné à deux fois la période
d'interrogation : une coupure de Jeedom ne peut donc pas produire une consommation
fictive.

Plus la période d'interrogation est courte, plus l'intégration est fine. Cinq minutes
constituent un bon compromis.

### Calibrage

L'estimation reste approchée tant qu'elle n'est pas recalée. La méthode :

1. Notez le stock au départ d'une livraison.
2. À la livraison suivante, comparez la consommation cumulée annoncée par le plugin à la
   consommation réelle.
3. Ajustez le **facteur de correction** : si le plugin annonce 10 % de moins que le réel,
   passez-le à `1.1`.

## Suivi de maintenance

L'onglet **Maintenance** tient l'historique des remplissages de silo et des vidanges du
cendrier, avec le compteur d'heures du brûleur au moment de chaque vidange.

Deux alertes proviennent directement de l'état de la chaudière :

- `ash_alert` — la chaudière signale l'état « !Cendre! » (code 8)
- `pellet_low_alert` — état « ! Pellets ! » (code 9), ou stock sous le seuil bas

## Défauts

- `error_active` — un défaut est en cours
- `error_count` — nombre de défauts signalés par la chaudière
- `error_text` — libellé du ou des défauts

> La structure exacte des entrées de défaut n'a pas pu être observée sur une chaudière
> sans défaut actif. Le plugin est volontairement tolérant sur ce point. Si un défaut
> réel s'affiche mal, le contenu brut est écrit dans les logs et le format pourra être
> ajusté.

## Dépannage

Les logs sont dans **Analyse → Logs → okofen**. Passez le niveau en *Debug* pour voir
chaque requête (le mot de passe y est masqué).

| Symptôme | Cause probable |
|---|---|
| « Mot de passe JSON refusé » | mot de passe de l'interface web utilisé à la place du mot de passe JSON |
| « Chaudière injoignable » | IP erronée, chaudière hors ligne, ou port JSON désactivé |
| « Réponse HTTP inattendue : 401 (après 3 tentatives) » | anomalie connue de la chaudière, normalement absorbée par les réessais ; si elle persiste, allongez l'intervalle d'interrogation |
| Une température absurde vers −3276 °C | ne devrait pas arriver : le plugin filtre la sentinelle −32768 des capteurs absents ; à signaler |
| Aucune commande créée | cliquez sur **Synchroniser les commandes** après avoir enregistré |

## Notes techniques

Particularités de l'API ÖkoFEN gérées par le plugin :

1. Un mot de passe invalide renvoie **HTTP 200 avec la page d'aide**, pas une erreur.
2. Les réponses sont encodées en **ISO-8859-1**, converties en UTF-8 à la réception.
3. La valeur **−32768** signale un capteur absent et n'est pas remontée comme mesure.
4. Une écriture répond par l'**écho de la commande** (`hk1_temp_heat=200`), pas par du JSON.
5. La chaudière renvoie **sporadiquement un `401 Unauthorized`** alors que le mot de passe
   est valide — environ une requête sur huit lors des mesures. Ce n'est pas un refus
   d'authentification : la requête suivante passe. Chaque requête est donc retentée
   jusqu'à trois fois. Le refus réel, lui, se reconnaît à la page d'aide (point 1).
6. Certaines écritures sont **silencieusement rejetées** : la chaudière renvoie l'écho
   attendu mais la valeur ne change pas. Observé sur `pe1_storage_fill_yesterday`. Le
   plugin journalise donc les écritures ; en cas de doute, vérifiez la valeur relue.
