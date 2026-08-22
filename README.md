# Plugin ÖkoFEN pour Jeedom

Supervision et pilotage des chaudières à granulés ÖkoFEN (Pellematic / Pelletronic Touch)
depuis Jeedom, via l'**API JSON locale** de la chaudière (port 4321).

Testé sur une **Pellematic SMART XS** avec Jeedom 4.6.

## Fonctionnalités

- **Découverte automatique** des composants réellement présents : chaudière (`pe`),
  circuits de chauffage (`hk1..6`), eau chaude sanitaire (`ww1..3`), ballons tampon
  (`pu`), circuits solaires (`sk`). Rien n'est codé en dur.
- Températures, états, compteurs (heures de fonctionnement, nombre de démarrages),
  modulation, défauts.
- **Commandes de pilotage** : marche/arrêt de la chaudière, mode du circuit de chauffage,
  mode ECS, chauffe ECS ponctuelle, consignes de température.
- **Suivi du stock de pellets** avec estimation de consommation et calcul d'autonomie.
- **Suivi de maintenance** : historique des remplissages de silo et des vidanges du
  cendrier, alertes issues de l'état de la chaudière.
- **Widget de tableau de bord dédié et pilotable** : état de la chaudière, températures,
  circuit de chauffage, ECS, niveau du silo et défauts sur une seule tuile — avec les
  modes en boutons, les consignes réglables et les actions de maintenance.
- **Deux modes d'affichage** : *Basique* pour l'usage quotidien (températures, état,
  modes chauffage et ECS, stock, alertes), *Expert* pour l'intégralité des variables.
  Le mode ne fait que masquer : toutes les commandes restent créées, alimentées et
  utilisables en scénario, et le basculement est réversible sans rien perdre.

## Installation

### Depuis GitHub

Dans Jeedom : **Réglages → Système → Configuration → Mises à jour/Market**, activer la
source **GitHub**. Puis **Plugins → Gestion des plugins → Ajouter**, source *GitHub*, en
indiquant l'utilisateur et le dépôt.

Le plugin est ensuite à **activer** manuellement (désactivé par défaut).

### Prérequis sur la chaudière

L'API JSON doit être activée et protégée par un mot de passe, à relever sur l'écran
tactile : **Menu → Réglages généraux → Touch / JSON**.

> Ce mot de passe est **distinct** de celui de l'interface web / Okovision.

## Configuration

| Champ | Rôle |
|---|---|
| Adresse IP | adresse locale de la chaudière |
| Port JSON | 4321 par défaut |
| Mot de passe JSON | code relevé sur l'écran tactile |
| Mode d'affichage | *Basique* n'affiche que l'essentiel, *Expert* toutes les variables |
| Intervalle d'interrogation | fréquence de relève, en minutes |
| Capacité du silo | en kg |
| Source du niveau de stock | comptabilité du plugin, ou compteur de la chaudière |
| Puissance nominale | plafond servant à l'estimation de consommation |
| Facteur de correction | recalage de l'estimation |

La documentation complète est dans [`docs/fr_FR/index.md`](docs/fr_FR/index.md).

## Particularités de l'API ÖkoFEN

Ces comportements ont été constatés sur matériel réel et sont gérés par le plugin :

1. Un **mot de passe invalide** renvoie `HTTP 200` avec la page d'aide, pas une erreur.
2. Les réponses sont encodées en **ISO-8859-1**, pas en UTF-8.
3. La valeur **−32768** signale un **capteur absent**.
4. Une **écriture** répond par l'**écho de la commande**, pas par du JSON.
5. La chaudière renvoie **sporadiquement un `401 Unauthorized`** avec un mot de passe
   pourtant valide (environ une requête sur huit) — chaque requête est retentée.
6. Certaines écritures sont **silencieusement rejetées** : écho conforme, valeur
   inchangée. L'écho ne prouve pas la prise en compte.

Seules les variables **sans préfixe `L_`** sont inscriptibles ; les `L_` sont les mesures
en lecture seule. Unités, facteurs d'échelle et bornes sont lus depuis la chaudière.

## Licence

GPL
