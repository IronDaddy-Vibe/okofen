# Plugin ÖkoFEN

Supervision et pilotage des chaudières à granulés ÖkoFEN (Pellematic / Pelletronic Touch)
depuis Jeedom, via l'**API JSON locale** de la chaudière.

Développé et validé sur une **Pellematic SMART XS** avec Jeedom 4.6.

## Prérequis

L'API JSON doit être activée sur la chaudière et protégée par un mot de passe :

> Écran tactile → **Menu → Réglages généraux → Touch / JSON**

Relevez-y le **mot de passe JSON** et le **port** (4321 par défaut).

> **Attention :** ce mot de passe est distinct de celui de l'interface web / Okovision.
> Utiliser le mauvais donne une erreur d'authentification.

## Installation

Deux voies, au choix.

**Depuis GitHub** (recommandé, mises à jour en un clic) — activer la source *Github* dans
*Réglages → Système → Configuration → Mises à jour/Market*, puis ajouter le plugin avec :

| Champ | Valeur |
|---|---|
| Nom logique | `okofen` |
| Utilisateur | `IronDaddy-Vibe` |
| Repository | `okofen` |
| Branche | `main` |

**Depuis un fichier** — télécharger `okofen.zip` depuis les *Releases* du dépôt, puis
*Plugins → Gestion des plugins → Market → Ajouter une source → Fichier local*.

Le plugin arrive **désactivé** : pensez à l'activer.

> Un plugin installé depuis une source Git affiche le **hash du commit** à la place du
> numéro de version. C'est le comportement normal de Jeedom, pas une anomalie.

## Configuration d'un équipement

| Champ | Rôle |
|---|---|
| Adresse IP | adresse locale de la chaudière |
| Port JSON | 4321 par défaut |
| Mot de passe JSON | code relevé sur l'écran tactile |
| Mode d'affichage | *Basique* ou *Expert* (voir plus bas) |
| Intervalle d'interrogation | fréquence de relève, en minutes |
| Capacité du silo | en kg ; vide = reprend la valeur déclarée par la chaudière |
| Source du niveau de stock | compteur de la chaudière, ou comptabilité du plugin |
| Puissance nominale | sert à estimer la consommation |
| Facteur de correction | recalage de l'estimation |

Le bouton **Tester la connexion** valide l'accès et affiche le modèle détecté ainsi que le
compteur d'heures du brûleur.

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

Chaque écriture est **vérifiée par relecture**. Si la chaudière ne retient pas la valeur
demandée, un message le signale en indiquant ce qui a réellement été retenu — la chaudière
borne en effet les consignes hors plage, un écart n'est donc pas toujours un échec.

### Principales commandes d'action

| Commande | Effet |
|---|---|
| `pe1_mode` | Arrêt / Auto / Marche forcée de la chaudière |
| `hk1_mode_auto` | Arrêt / Auto / Confort / Réduit du circuit de chauffage |
| `ww1_mode_auto` | Arrêt / Auto / Marche forcée de l'eau chaude sanitaire |
| `ww1_heat_once` | déclenche une chauffe ECS ponctuelle |
| `hk1_temp_heat` | consigne de confort |
| `ww1_temp_min_set` / `ww1_temp_max_set` | seuils d'enclenchement et d'arrêt ECS |

## Modes d'affichage

La découverte automatique produit une **centaine de commandes** : c'est riche pour
explorer, mais illisible au quotidien.

- **Basique** (par défaut) — n'affiche que l'essentiel : températures, état, modulation,
  compteur brûleur, modes de chauffage et d'ECS, consignes, stock de pellets, autonomie
  et alertes.
- **Expert** — affiche l'intégralité des variables remontées par la chaudière.

Le mode ne fait que **masquer** : toutes les commandes restent créées, alimentées et
utilisables en scénario. Le basculement est réversible dans les deux sens, et les
historiques ne sont jamais perdus.

Le réglage s'applique à la synchronisation suivante.

> Corollaire : masquer ou afficher une commande à la main sera écrasé à la
> synchronisation suivante. Pour un affichage sur mesure, restez en mode Expert.

## Widget de tableau de bord

L'équipement dispose d'un widget dédié réunissant six panneaux — état de la chaudière,
chaudière, circuit de chauffage, eau chaude sanitaire, silo à pellets, défauts — et un
pied de page technique.

Il est **pilotable** :

| Contrôle | Emplacement |
|---|---|
| Modes chaudière, chauffage, ECS | boutons ; celui du mode courant est mis en évidence |
| Consignes confort, réduit, seuils ECS | boutons **−** et **+**, bornés par la chaudière |
| Chauffe ECS ponctuelle | bouton dédié |
| Déclarer un remplissage, corriger le stock | boutons du panneau Silo, avec saisie |
| Signaler une vidange de cendrier | bouton du panneau Silo |
| Forcer une relève | bouton du pied de page |

Les jauges portent une information : l'anneau d'état se remplit à la **modulation réelle**,
l'anneau ECS se remplit **entre les deux seuils configurés** — et non de 0 à 100 °C, ce qui
ne bougerait presque jamais.

Les couleurs encodent l'état : vert en fonctionnement, **orange sur une demande de
maintenance** (cendres ou pellets), rouge sur défaut, gris si la chaudière est injoignable.

> Le widget **remplace la tuile standard**, donc les champs de saisie habituels de Jeedom
> n'y figurent plus. Utilisez ses propres boutons.

## Stock de pellets

Deux modes, au choix dans la configuration de l'équipement.

**Comptabilité du plugin** (par défaut) — le plugin tient seul son décompte : vous
déclarez chaque livraison dans l'onglet **Maintenance**, par le bouton *Remplissage* du
widget, ou via la commande `pellet_add_delivery`. La consommation estimée est déduite au
fil de l'eau, et `pellet_set_stock` permet de recaler le stock à tout moment.

**Compteur de la chaudière** — le plugin lit `pe1.L_storage_fill`, que la chaudière
calcule à partir du fonctionnement de la vis d'alimentation.

> **Attention :** ce compteur décompte, mais ne se recharge pas tout seul. Il n'est
> exploitable que si votre installation offre un moyen de le remettre à niveau après une
> livraison (saisie du remplissage sur l'écran tactile ou dans l'interface web). Faute de
> quoi il reste bloqué à zéro une fois le silo vidé, et ce mode ne sert à rien. En cas de
> doute, restez sur la comptabilité du plugin.

Le mode « chaudière » ignore un compteur à zéro et retombe alors sur la comptabilité
interne, de sorte qu'un mauvais choix ne fait rien perdre.

### Saisir une quantité

Les commandes `pellet_add_delivery` et `pellet_set_stock` **attendent une valeur**. Selon
le point d'appel :

- **Onglet Maintenance** — champ dédié, puis *Déclarer*
- **Widget** — le bouton ouvre une fenêtre de saisie
- **Tuile de commande sur un dashboard** — tapez la quantité **dans le champ** de la
  commande avant de valider

> Un simple clic sur la commande, ou sur le bouton de test de l'onglet *Commandes*,
> l'exécute **sans valeur** : le plugin refuse alors l'opération avec un message
> explicite. C'est le fonctionnement normal de Jeedom pour une commande de type message.

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

> La structure exacte des entrées de défaut n'a pas pu être observée : l'objet est vide
> tant qu'aucun défaut n'est actif, et la documentation communautaire de l'API ne la
> connaît pas davantage. Le plugin est volontairement tolérant, et journalise le **JSON
> brut** au premier défaut réel afin que le format puisse être ajusté.

## Dépannage

Les logs sont dans **Analyse → Logs → okofen**. Passez le niveau en *Debug* pour voir
chaque requête (le mot de passe y est masqué). Une erreur fatale PHP, elle, n'apparaît que
dans le log `http.error`.

| Symptôme | Cause probable |
|---|---|
| « Mot de passe JSON refusé, ou commande non reconnue » | mot de passe de l'interface web utilisé à la place du mot de passe JSON. La chaudière répond la même page d'aide dans les deux cas |
| « Chaudière injoignable » | IP erronée, chaudière hors ligne, ou port JSON désactivé |
| Quelques « Tentative 1 échouée (401) » dans le log | normal si un **autre client** interroge la chaudière — second plugin, application mobile, ou interface web ouverte dans un navigateur. La limite de cadence est globale à la chaudière (voir Notes techniques) |
| « aucune quantité reçue » sur un remplissage | la commande a été déclenchée sans saisie ; voir *Saisir une quantité* |
| Une température absurde vers −3276 °C | ne devrait pas arriver : le plugin filtre la sentinelle −32768 des capteurs absents ; à signaler |
| Aucune commande créée | cliquez sur **Synchroniser les commandes** après avoir enregistré |
| Des commandes ont disparu de l'affichage | mode *Basique* actif : passez en *Expert* |
| Consommation qui reste à zéro | normal chaudière à l'arrêt : la modulation vaut 0, rien n'est décompté |

## Notes techniques

Particularités de l'API ÖkoFEN constatées sur matériel réel, et gérées par le plugin :

1. Un mot de passe invalide — ou une commande non reconnue — renvoie **HTTP 200 avec la
   page d'aide**, jamais une erreur HTTP. Le marqueur n'est pas en tête de réponse.
2. **La chaudière n'émet que de l'ASCII** : chaque caractère accentué est remplacé par
   `?` avant l'envoi. Ce n'est pas un problème d'encodage — l'information est perdue à la
   source, et aucune conversion ne la récupère. Le plugin restitue les accents du
   vocabulaire connu par table de correspondance.
3. La valeur **−32768** signale un capteur absent et n'est pas remontée comme mesure.
4. Une écriture répond par l'**écho de la commande** (`hk1_temp_heat=200`), pas par du
   JSON.
5. **La chaudière limite la cadence des requêtes** : il faut au moins 2,5 s entre deux
   appels, faute de quoi elle répond `401 Unauthorized` bien que le mot de passe soit
   valide. Le plugin respecte une fenêtre de 2,6 s.
   > Cette limite est **globale à la chaudière, pas propre à un client**. Un autre plugin,
   > l'application mobile ou une interface web ouverte consomment la même fenêtre. Des
   > réessais sont donc conservés pour cohabiter avec eux.
6. Certaines écritures sont **silencieusement rejetées** : la chaudière renvoie l'écho
   attendu mais la valeur ne change pas. Observé sur `pe1_storage_fill_yesterday`. C'est
   pourquoi chaque écriture est vérifiée par relecture.
7. Le suffixe `?`, qui demande les métadonnées, **n'est accepté que sur `all`**. Sur un
   composant seul (`pe1?`), la chaudière répond par sa page d'aide.
8. Certaines variables sont des **impulsions** (`ww1_heat_once`) : la chaudière les remet
   à zéro dès l'ordre pris en compte. Elles sont exclues de la vérification par relecture,
   qui produirait un faux avertissement à chaque usage.
