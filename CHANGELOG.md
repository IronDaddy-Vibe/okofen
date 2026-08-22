# Journal des versions

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
