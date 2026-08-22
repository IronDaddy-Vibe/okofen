# Journal des versions

## 1.4.4 — 22/08/2026

### Documentation utilisateur remise à jour

`docs/fr_FR/index.md` datait de la 1.0.0 et contenait des affirmations **devenues
fausses** — d'autant plus gênant que `info.json` pointe dessus depuis la 1.2.0 : le
bouton « Documentation » de Jeedom affichait donc une doc en retard de quatre versions.

Corrigé :

- L'encodage n'est **pas** de l'ISO-8859-1 : la chaudière n'émet que de l'ASCII et
  détruit les accents à la source.
- Les 401 ne sont **pas** une anomalie sporadique « d'une requête sur huit » : c'est une
  **limitation de cadence**, globale à la chaudière et non propre à un client.
- Les écritures sont désormais vérifiées par relecture, ce que la doc ignorait.

Ajouté : installation depuis GitHub ou par fichier, modes d'affichage Basique/Expert,
widget de tableau de bord et ses contrôles, comment saisir une quantité selon le point
d'appel, deux particularités d'API non documentées (suffixe `?` réservé à `all`,
variables impulsion), et cinq entrées de dépannage.

## 1.4.3 — 22/08/2026

### Les boutons du widget fonctionnent enfin

Diagnostic complet, établi par les traces : **toute commande ayant besoin d'une valeur
échouait** — consignes, modes, stock, remplissage — avec systématiquement des options
réduites au contexte utilisateur. Seules les commandes à valeur fixe passaient
(`ww1_heat_once`, écrite et acceptée).

La cause : `jeedom.cmd.execute()` ne transmettait pas les options qu'on lui confiait.
Et comme le widget remplace la tuile entière, il avait supprimé les champs de saisie
standard de Jeedom — ses boutons étaient devenus le seul chemin possible.

Les boutons appellent désormais **le point d'entrée ajax du plugin**, celui de l'onglet
Maintenance, éprouvé depuis la 1.0.x. Les options y sont construites côté PHP à partir
du sous-type réel de la commande, là où les deux extrémités sont maîtrisées.

### Plus de faux avertissement sur la chauffe ponctuelle

`ww1_heat_once` était écrite à « true », relue à 0, et signalée comme non appliquée —
alors que l'ordre avait bien été pris en compte. C'est une **variable impulsion** : la
chaudière la remet à zéro aussitôt. Ces variables sont désormais exclues de la
vérification par relecture, qui n'a pas de sens pour elles.

## 1.4.2 — 22/08/2026

L'instrumentation ajoutée en 1.4.1 a livré son verdict : les options transmises ne
contenaient que `{"user_login":…,"user_id":…}`. **Aucune valeur n'était envoyée** — la
commande était déclenchée d'un simple clic, alors qu'une commande de type message
n'envoie sa valeur que si celle-ci a été saisie dans son champ.

Le refus était donc le bon comportement ; c'est le message qui était inutile.

- **Message d'erreur actionnable** : il indique désormais où saisir la quantité, et
  distingue une saisie absente d'une saisie non numérique (« 500 kg » au lieu de
  « 500 »).
- **Boutons de la tuile** : la valeur est transmise sous plusieurs clés à la fois, la
  clé retenue par Jeedom dépendant du point d'appel.

## 1.4.1 — 22/08/2026

### Une saisie vide ne peut plus remettre le stock à zéro

Défaut le plus sérieux rencontré jusqu'ici, parce qu'il **détruisait une donnée sans
rien signaler**. `setStock('')` faisait `floatval('') = 0`, et 0 passait la validation
`>= 0` comme une valeur parfaitement légitime : une correction de stock dont la saisie
n'arrivait pas remettait donc le compteur à zéro, en journalisant un succès.

Une saisie vide ou non numérique est désormais **refusée explicitement**, avec un
message citant la valeur reçue. Même traitement pour la déclaration de remplissage.

### La valeur saisie arrive enfin

Les commandes « Déclarer un remplissage » et « Corriger le stock » ne lisaient la
saisie que sous la clé `message`. Selon le point d'appel — widget de commande du
tableau de bord, tuile du plugin, scénario, API — Jeedom ne l'y place pas toujours.
Plusieurs clés sont maintenant acceptées.

Les options brutes sont tracées en niveau debug, pour établir la forme réellement
transmise plutôt que d'accumuler les hypothèses.

## 1.4.0 — 22/08/2026

### Le widget devient pilotable

La 1.3.0 n'affichait que des valeurs. Le widget porte désormais les commandes :

- **Modes** en rangées de boutons — chaudière, circuit de chauffage, ECS. Le bouton
  correspondant au mode courant est mis en évidence, ce qui rend inutile son affichage
  en ligne séparée.
- **Consignes réglables** par boutons − et + : confort, réduit, seuils ECS. Les bornes
  proviennent de la chaudière ; un bouton qui sortirait de la plage est désactivé.
- **Chauffe ECS ponctuelle**, en bouton dédié.
- **Silo** : déclarer un remplissage, corriger le stock, signaler une vidange de
  cendrier — les deux premiers demandent la valeur avant d'agir.
- **Rafraîchir** dans le pied de page, pour forcer une relève.

Choix technique : **les valeurs cibles des boutons sont calculées en PHP au rendu**,
pas en JavaScript. Un bloc `<script>` inséré dynamiquement dans un tableau de bord ne
s'exécute pas de façon garantie, alors qu'un gestionnaire `onclick` fonctionne
toujours. Le widget se re-rend à chaque relève, les cibles restent donc à jour.

Une commande action absente fait disparaître son contrôle plutôt que d'afficher un
bouton inopérant — cohérent avec la découverte automatique, où rien n'est garanti.

## 1.3.0 — 22/08/2026

### Widget de tableau de bord

Widget complet remplaçant l'affichage standard, inspiré d'une maquette de tableau de
bord ÖkoFEN : six panneaux sur une grille adaptative — état de la chaudière, chaudière,
circuit de chauffage, ECS, silo à pellets, défauts — plus un pied de page portant la
connexion, l'hôte JSON, l'heure de la dernière relève et la source du stock.

Choix de conception :

- **Les jauges portent une information, elles ne décorent pas.** L'anneau d'état se
  remplit à la modulation réelle ; l'anneau ECS se remplit entre les deux seuils
  configurés, et non de 0 à 100 °C — c'est la plage qui a un sens pour l'utilisateur.
- **Les couleurs encodent l'état** : vert en fonctionnement, orange sur une demande de
  maintenance (états 8 cendres et 9 pellets), rouge sur défaut, gris si la chaudière
  est injoignable.
- **Aucun composant n'est codé en dur.** Le widget cherche le premier circuit et le
  premier ballon présents (`hk1..6`, `ww1..3`), conformément au principe de découverte
  automatique. Une donnée absente s'affiche en tiret cadratin plutôt que de casser.
- **Repli systématique.** Toute erreur de rendu est interceptée — `Throwable`, donc
  les `Error` PHP 7+ comprises — et rend le widget standard de Jeedom. Un tableau de
  bord cassé serait un prix disproportionné pour un affichage d'agrément.

## 1.2.0 — 21/08/2026

Version issue d'une comparaison avec le plugin MyOkoTouch, qui a révélé la vraie
nature de nos erreurs 401.

### Limitation de débit — le correctif principal

Les 401 n'étaient pas « sporadiques » : la chaudière **limite la cadence des
requêtes**. Deux sources indépendantes le confirment — MyOkoTouch gère « le délai
minimum obligatoire de 2500 ms entre deux appels », et la bibliothèque `pyokofen`
documente « a soft limitation of 1 request per 10 seconds ». Nos logs concordaient
sans qu'on l'ait vu : une requête refusée deux fois n'aboutissait qu'environ 2,5 s
après la précédente.

Réessayer toutes les 800 ms était donc exactement la mauvaise réponse — on réussissait
par accident, à la troisième tentative, une fois le délai écoulé.

- Fenêtre minimale de **2,6 s** entre deux requêtes, respectée par attente calculée.
- L'horodatage transite par le **cache de Jeedom** : le cron, les pages web et les
  appels ajax sont des processus distincts sans mémoire commune.
- `MAX_ATTEMPTS` ramené de 5 à 3 : les réessais deviennent un filet de sécurité pour
  les vraies pannes, plus un contournement de cadence.
- Un 401 subsistant est désormais journalisé comme tel — il signalerait une fenêtre
  encore trop courte pour ce modèle.

### Vérification des écritures

L'écho renvoyé par la chaudière ne prouve rien : constaté sur
`pe1_storage_fill_yesterday`, qui répond l'écho attendu et laisse la valeur inchangée.
Chaque écriture est maintenant **contrôlée par relecture**, et un écart est signalé
dans le log et par un message Jeedom.

Le message se garde de conclure : la chaudière **borne** légitimement les consignes
hors plage, un écart n'est donc pas toujours un échec. Il dit ce qui a été retenu.

### Moins de requêtes

Avec une fenêtre de 2,6 s, chaque appel superflu se paie en temps de réponse.

- **Test de connexion** : une requête au lieu de deux — `all?` contient déjà `system`.
- **Enregistrement d'un équipement** et **synchronisation manuelle** : `syncCommands()`
  renvoie sa lecture, `refresh()` sait l'accepter. Une requête au lieu de deux.

### Instrumentation de l'objet `error`

Sa structure reste inconnue, faute de défaut réel depuis le début du projet. Au
premier défaut, le **JSON brut** sera journalisé. C'est la méthode qui avait tranché
la question des accents en un aller-retour.

### Qualité

- **Contrôle de syntaxe automatique** sur GitHub Actions : `php -l` sur tous les
  fichiers, en PHP 7.4 et 8.2, plus une validation de `info.json`. C'est la réponse à
  la cause racine des dix défauts des versions 1.0.x — l'impossibilité d'exécuter PHP
  sur le poste de développement.
- **Publication automatique** du zip à chaque tag `v*`.
- Champs `documentation` et `changelog` renseignés dans `info.json`.

---

## 1.1.1 — 21/08/2026

- Icône du plugin et des cartes d'équipement (`plugin_info/okofen_icon.png`). Le
  fichier était déjà référencé par la vue desktop mais n'avait jamais été créé.
- Icône par défaut du widget, posée uniquement si le champ est vide.
- Auteur du plugin : `IronDaddy-Vibe`.

## 1.1.0 — 21/08/2026

- **Modes d'affichage Basique / Expert.** La découverte automatique produit 104
  commandes : riche pour explorer, illisible au quotidien.
- Le mode **masque sans supprimer** — historiques, scénarios et widgets survivent au
  basculement, dans les deux sens.
- Le mode Expert restaure la visibilité d'origine, et non « tout visible » : les
  valeurs brutes des énumérations restent masquées.

## 1.0.10 — 21/08/2026

- **Accents restitués.** Ce n'était pas un problème d'encodage : la chaudière n'émet
  que de l'ASCII et remplace chaque accent par `?` avant l'envoi (vérifié en
  hexadécimal, aucun octet ≥ 0x80). Seule une table de correspondance peut les rendre.

## 1.0.9 — 21/08/2026

- Détection d'encodage élargie et **trace hexadécimale** du premier octet non-ASCII.
  C'est cette instrumentation qui a réfuté l'hypothèse ISO-8859-1.

## 1.0.8 — 21/08/2026

- Commande info et commande action portaient le **même nom** : la table `cmd` impose
  l'unicité de (eqLogic_id, name), et toute la synchronisation échouait.
- `uniqueCmdName()` appliquée aux cinq points de nommage.

## 1.0.7 — 21/08/2026

- Page d'aide détectée sur l'ensemble du corps : le marqueur n'est pas en tête, un `{`
  le précède.
- Le suffixe `?` n'est accepté que sur `all` — `pe1?` renvoie la page d'aide.
- `MAX_ATTEMPTS` porté de 3 à 5.

## 1.0.6 — 21/08/2026

- L'autoloader Jeedom résout la classe d'équipement mais pas la classe utilitaire
  `okofenApi` : include explicite dans le fichier ajax.

## 1.0.5 — 21/08/2026

- Méthode `humanLabel()` appelée mais jamais écrite — HTTP 500 à la sauvegarde. Une
  `Error` PHP 7+ n'étant pas une `Exception`, elle échappait au `try/catch`.

## 1.0.4 — 21/08/2026

- Les **propriétés de classe** étaient prises pour des colonnes SQL par `DB::save()`.
  Converties en constantes et en variable statique locale à une méthode.

## 1.0.3 — 20/08/2026

- Garde `isConnect()` retiré de `install.php`, exécuté en CLI par Jeedom : sans
  session, il échouait toujours et les valeurs par défaut n'étaient jamais écrites.

## 1.0.2 — 20/08/2026

- `preSave()` exigeait l'adresse IP **à la création**, alors que le formulaire n'est
  affiché qu'après le premier enregistrement : les champs étaient exigés avant d'être
  accessibles.

## 1.0.1 — 20/08/2026

- `$plugin` n'existe pas dans une vue desktop — erreur fatale `getId() on null`.
- `sendVarToJS('eqType', …)` était absent, ce dont dépend `plugin.template.js`.

## 1.0.0 — 20/08/2026

Version initiale. Découverte automatique, unités et facteurs lus depuis la chaudière,
suivi du stock de pellets et de la maintenance.

> Écrite sans avoir jamais été exécutée : ni PHP ni Jeedom sur le poste de
> développement. D'où les dix versions correctives qui suivent, chaque défaut en
> masquant un autre — et la mise en place d'un contrôle de syntaxe en 1.2.0.
