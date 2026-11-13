# Covoiturage Régional au Maroc — PFE Challenge EMC (Sujet 3)

Plateforme web de covoiturage interurbain et régional au Maroc développée avec **Symfony 7.4**, **PHP 8.3**, **Doctrine ORM 3** et **MySQL 8.0**.

La particularité métier du projet repose sur la **gestion de trajets multi-étapes découpés en segments** (ex. *Casablanca → Settat → Marrakech → Agadir*), permettant de calculer dynamiquement les places disponibles et le tarif exact sur n'importe quelle portion du trajet et de revendre une place libérée à une étape intermédiaire.

---

## 1. Sujet choisi et niveaux visés

- **Sujet** : **Sujet 3 — Plateforme de covoiturage régional** (PFE Challenge EMC).
- **Niveaux visés** :
  - **Niveau 1 (Socle fonctionnel)** : Modélisation par segments (`Trip`, `TripStop`, `Booking`), authentification, gestion des véhicules, publication de trajets multi-étapes et recherche de trajets par ville de départ, ville d'arrivée et date.
  - **Niveau 2 (Gestion avancée des réservations & annulations)** : Réservation d'une ou plusieurs places sur une portion `[fromStop, toStop]`, calcul des disponibilités par segment, annulation par le passager ou le conducteur avec calcul automatique du remboursement (`refundAmount`) selon le préavis.
  - **Niveau 3 (Fiabilité, Avis croisés & Qualité logicielle)** : Système d'évaluation mutuelle conducteur/passager après trajet terminé (`Review`), verrouillage et intégrité transactionnelle, jeu de données réaliste (villes et axes routiers marocains), suite de tests unitaires et fonctionnels PHPUnit.

---

## 2. Stack technique

| Composant | Technologie / Version |
|---|---|
| Framework Backend | Symfony 7.4 (Webapp Pack) |
| Langage | PHP 8.3 (`strict_types=1`, Enums Backed, Attributs PHP 8) |
| ORM & Migrations | Doctrine ORM 3.7 & Doctrine Migrations 3.7 |
| Base de données | MySQL 8.0 (`utf8mb4`, types `DECIMAL` exacts) |
| Serveur Web | Nginx 1.27 (Alpine) + PHP-FPM 8.3 (Alpine) |
| Conteneurisation | Docker & Docker Compose (`compose.yaml` + `compose.override.yaml`) |
| Tests | PHPUnit 11.5 |

---

## 3. Lancement rapide avec Docker

### Prérequis
- **Docker** et **Docker Compose** installés et démarrés.

### Démarrer l'application
```bash
docker compose up --build -d
```

Au démarrage, le conteneur `php` exécute automatiquement ([docker/php/entrypoint.sh](docker/php/entrypoint.sh)) :
1. `composer install --no-interaction --prefer-dist`
2. L'attente active de la disponibilité de MySQL 8.0 (`database:3306`)
3. L'exécution des migrations Doctrine (`php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration`)
4. En environnement `dev` uniquement, si la table `city` est vide : le chargement automatique des fixtures (`php bin/console doctrine:fixtures:load --no-interaction`)

### Accès aux services
- **Application Symfony** : http://localhost:8080 *(surchargeable via la variable d'environnement `NGINX_PORT` si le port 8080 est déjà utilisé sur la machine hôte)*
- **phpMyAdmin** (dev) : http://localhost:8081 *(utilisateur : `app`, mot de passe : `app`)*
- **MySQL 8.0** (depuis l'hôte) : `127.0.0.1:3308` *(base : `covoiturage`, utilisateur : `app`, mot de passe : `app`)*

### Comptes de démonstration (Fixtures)
Tous les comptes de démonstration partagent le mot de passe commun : **`demo1234`**

| Rôle métier | Email | Nom complet | Rôle Symfony | Véhicule(s) |
|---|---|---|---|---|
| **Administrateur** | `admin@covoiturage.ma` | Karim El Mansouri | `ROLE_ADMIN` | — |
| **Conducteur 1** | `youssef.benali@covoiturage.ma` | Youssef Benali | `ROLE_USER` | Dacia Duster (4 pl.), Renault Clio 5 (3 pl.) |
| **Conducteur 2** | `fatima.alaoui@covoiturage.ma` | Fatima-Zahra Alaoui | `ROLE_USER` | Peugeot 3008 (4 pl.) |
| **Conducteur 3** | `omar.aitbrahim@covoiturage.ma` | Omar Aït Brahim | `ROLE_USER` | Volkswagen Golf 8 (4 pl.) |
| **Conducteur 4** | `salma.chraibi@covoiturage.ma` | Salma Chraïbi | `ROLE_USER` | Hyundai Tucson (4 pl.) |
| **Passager 1** | `mehdi.tazi@covoiturage.ma` | Mehdi Tazi | `ROLE_USER` | — |
| **Passager 2** | `nadia.berrada@covoiturage.ma` | Nadia Berrada | `ROLE_USER` | — |
| **Passager 3** | `hamza.idrissi@covoiturage.ma` | Hamza Idrissi | `ROLE_USER` | — |

### Commandes utiles (via Docker)
```bash
# Recharger les données de démonstration (idempotent)
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction

# Valider le mapping Doctrine et la synchronisation du schéma SQL
docker compose exec php php bin/console doctrine:schema:validate

# Générer puis appliquer une migration
docker compose exec php php bin/console doctrine:migrations:diff
docker compose exec php php bin/console doctrine:migrations:migrate -n

# Lancer la suite de tests PHPUnit
docker compose exec php php bin/phpunit
```

---

## 4. Documentation d'architecture et métier

- **[docs/MODELE.md](docs/MODELE.md)** : Diagramme Entité-Association (`Mermaid erDiagram`), formule de disponibilité par segments, justification du prix figé, des enums PHP 8.1, de `seatsTotal` et des index SQL.
- **[docs/DECISIONS.md](docs/DECISIONS.md)** : Journal daté des décisions techniques et réponses argumentées aux **5 questions métier à trancher** du sujet.

---

## 5. État d'avancement

- [x] **Étape 0** : Stack Docker complète (PHP 8.3-FPM + Nginx + MySQL 8.0 + phpMyAdmin) avec auto-installation Composer et migrations au démarrage.
- [x] **Étape 1** : Modèle de données complet (`User`, `Vehicle`, `City`, `Trip`, `TripStop`, `Booking`, `Review`), enums `TripStatus` et `BookingStatus`, contraintes de validation et migrations SQL validées.
- [x] **Étape 2** : Documentation du modèle (`docs/MODELE.md`), journal des décisions et réponses aux 5 questions métier (`docs/DECISIONS.md`), et `README.md`.
- [x] **Étape 3** : Fixtures réalistes et idempotentes (10 villes marocaines, 8 utilisateurs, 5 véhicules, 8 trajets multi-étapes, 14 réservations et avis croisés).
- [ ] **Prochaines étapes** : Services métier (calcul de disponibilité par segment, tarification, réservation, annulation/remboursement), Authentification, Contrôleurs/Vues Twig et Tests PHPUnit.
