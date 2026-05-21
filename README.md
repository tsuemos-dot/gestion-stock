# Gestion de Stock — Atelier Rangement

Application web légère pour gérer un stock d'atelier, suivre les entrées/sorties, gérer fournisseurs, commandes et établir des devis.

**Principales caractéristiques**
- Gestion des références matérielles (ajout / modification / suppression)
- Enregistrement d'entrées et sorties de stock
- Historique et rapports simples
- Gestion des fournisseurs et commandes
- Création de devis et bons (sortie, commande, devis)
- Interface front-end unique : `gestion_stock_atelier.html`

**Prérequis**
- Windows, macOS ou Linux
- XAMPP / Apache + PHP (PHP 7.x ou supérieur recommandé)
- MySQL / MariaDB

**Installation**
1. Copier le dossier du projet dans le répertoire web de votre serveur (ex: `c:/xampp/htdocs/gestion-stock`).
2. Créer une base de données MySQL pour l'application.
3. Configurer les accès à la base de données dans `api/db.php` (hôte, utilisateur, mot de passe, nom de la base).
4. Démarrer Apache et MySQL (ex: via XAMPP Control Panel).
5. Ouvrir le navigateur à l'adresse : `http://localhost/gestion-stock/gestion_stock_atelier.html`.

**Configuration**
- Les paramètres d'initialisation et les données chargées côté client sont fournis par `api/bootstrap.php`.
- Pour modifier la configuration (nom d'atelier, devise, etc.), éditez les paramètres retournés par l'API ou mettez à jour la base de données directement.

**Authentification**
- La page front-end propose un formulaire de connexion; l'API d'authentification est `api/auth.php` (POST pour login, DELETE pour logout).
- Les rôles pris en charge incluent `admin`, `moderateur_stock` et `gestionnaire_projet`.

**Endpoints API (fichiers)**
- [api/auth.php](api/auth.php) — Authentification (login / logout)
- [api/bootstrap.php](api/bootstrap.php) — Point d'entrée qui renvoie données initiales (matériels, commandes, mouvements, fournisseurs, params, user)
- [api/db.php](api/db.php) — Connexion à la base de données
- [api/materials.php](api/materials.php) — CRUD des matériels / références
- [api/movements.php](api/movements.php) — Enregistrement des sorties / mouvements de stock
- [api/entries.php](api/entries.php) — Enregistrement des entrées directes de stock
- [api/orders.php](api/orders.php) — Gestion des commandes fournisseurs
- [api/suppliers.php](api/suppliers.php) — Gestion des fournisseurs
- [api/quotes.php](api/quotes.php) — Gestion des devis / projets
- [api/print-movement.php](api/print-movement.php) — Impression / export d'un mouvement
- [api/history.php](api/history.php) — Historique et journal des mouvements
- [api/settings.php](api/settings.php) — Réglages applicatifs
- [api/reset.php](api/reset.php) — Script de réinitialisation (utiliser avec précaution)
- [api/bon-commande.php](api/bon-commande.php) — Génération / gestion des bons de commande
- [api/bon-devis.php](api/bon-devis.php) — Génération / gestion des devis
- [api/bon-sortie.php](api/bon-sortie.php) — Bon de sortie simple
- [api/bon-sortie-groupee.php](api/bon-sortie-groupee.php) — Bon de sortie groupée

Consultez ces fichiers pour comprendre les paramètres POST/GET attendus et les réponses JSON.

**Front-end**
- Page principale : `gestion_stock_atelier.html` (JS dans `app.js`, styles dans `styles.css`).
- `app.js` contient la logique cliente, les appels à l'API (fonction `apiRequest`) et la navigation.

**Bonnes pratiques**
- Sauvegardez une copie de la base avant d'exécuter `api/reset.php`.
- Restreignez l'accès aux fichiers API si vous déployez en production (HTTPS, règles Apache/Nginx, pare-feu).

**Contribuer / Développement**
- Ouvrez une issue ou proposez une pull request avec des modifications claires.
- Gardez les changements isolés et testez localement via XAMPP.

**Licence**
- Licence à préciser par le propriétaire du projet (ex: MIT).

---

Fichiers importants :
- `gestion_stock_atelier.html` — interface utilisateur
- `app.js` — logique frontend
- `styles.css` — styles
- `api/` — backend PHP (endpoints)

Si vous voulez, je peux :
- Détailler les exemples d'appels API (ex: payload JSON pour `materials.php`).
- Ajouter un fichier SQL d'exemple pour créer les tables.
- Traduire le README en anglais.
