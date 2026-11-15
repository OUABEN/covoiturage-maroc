# Guide d'installation et de démarrage — AtlasCovoit Maroc

Ce guide explique comment installer, démarrer et tester la plateforme **AtlasCovoit Maroc** sur une machine propre en une seule commande, sans installer PHP, Composer, MySQL, Node.js ni XAMPP sur votre système hôte.

---

## 1. Prérequis

- **Docker Desktop** (Windows / macOS) ou **Docker Engine + Docker Compose v2** (Linux), démarré.
- **Git** pour cloner le dépôt.
- **Aucun prérequis local supplémentaire** : ni XAMPP, ni WAMP, ni PHP local, ni Composer local ne sont nécessaires.

---

## 2. Clonage et démarrage en une commande

```bash
# 1. Cloner le dépôt
git clone https://github.com/anasouali06-dev/covoiturage-maroc.git
cd covoiturage-maroc

# 2. Construire et démarrer toute la stack Docker
docker compose up -d --build
```

> **Note** : vous pouvez également lancer `docker compose up --build` (sans `-d`) pour observer en direct les journaux d'initialisation dans votre terminal.

### Ce qui s'exécute automatiquement au premier démarrage (`docker/php/entrypoint.sh`)
1. **Vérification de `APP_SECRET`** : génère automatiquement une clé aléatoire dans `.env.local` si aucune clé n'est définie (ou utilise la valeur publique de développement fournie par `compose.yaml`).
2. **Dépendances PHP** : exécute `composer install --no-interaction --prefer-dist`.
3. **Assets Frontend** : exécute `php bin/console importmap:install` (Stimulus / AssetMapper) et `php bin/console assets:install public` (EasyAdmin).
4. **Attente active de MySQL 8.0** : vérifie la disponibilité de `database:3306`.
5. **Migrations SQL** : applique les migrations Doctrine sur la base principale (`covoiturage`) et prépare automatiquement la base de test (`covoiturage_test`).
6. **Jeu de données de démonstration (Fixtures)** : si `APP_ENV=dev` et que la table `city` est vide (`0` ville), charge automatiquement les 10 villes marocaines, 8 comptes utilisateurs, 5 véhicules, 8 trajets multi-étapes, 14 réservations et avis croisés.

---

## 3. URLs des services

Une fois les conteneurs démarrés (environ 30 à 60 secondes lors du tout premier build) :

| Service | URL par défaut | Identifiants / Détails |
|---|---|---|
| **Application Web (Symfony 7.4)** | http://localhost:8088 | Accueil, recherche, réservation, espaces passager/conducteur |
| **Back-office EasyAdmin 4** | http://localhost:8088/admin | Réservé au compte `admin@covoiturage.ma` (`demo1234`) |
| **API publique Route / Tarif** | http://localhost:8088/api/route?from=1&to=2 | JSON (distance, durée, géométrie GeoJSON, prix suggéré) |
| **phpMyAdmin** | http://localhost:8081 | Utilisateur : `app` / Mot de passe : `app` (ou `root` / `root`) |
| **MySQL 8.0 (port hôte)** | `127.0.0.1:3308` | Base : `covoiturage` (`app` / `app`) |

---

## 4. Comptes de démonstration

Tous les comptes chargés par les fixtures utilisent le mot de passe unique : **`demo1234`**

| Rôle métier | Adresse e-mail | Nom complet | Rôle Symfony | Particularités |
|---|---|---|---|---|
| **Administrateur** | `admin@covoiturage.ma` | Karim El Mansouri | `ROLE_ADMIN` | Accès au back-office `/admin` + fonctionnalités `ROLE_USER` |
| **Conducteur 1** | `youssef.benali@covoiturage.ma` | Youssef Benali | `ROLE_USER` | 2 véhicules (Dacia Duster, Renault Clio 5), trajets publiés et terminés |
| **Conducteur 2** | `fatima.alaoui@covoiturage.ma` | Fatima-Zahra Alaoui | `ROLE_USER` | Peugeot 3008, trajets multi-étapes (Casablanca → Marrakech → Agadir) |
| **Conducteur 3** | `omar.aitbrahim@covoiturage.ma` | Omar Aït Brahim | `ROLE_USER` | Volkswagen Golf 8, trajets avec décalage horaire > 30 min |
| **Conducteur 4** | `salma.chraibi@covoiturage.ma` | Salma Chraïbi | `ROLE_USER` | Hyundai Tucson |
| **Passager 1** | `mehdi.tazi@covoiturage.ma` | Mehdi Tazi | `ROLE_USER` | Réservations confirmées à venir et trajets passés |
| **Passager 2** | `nadia.berrada@covoiturage.ma` | Nadia Berrada | `ROLE_USER` | Réservations sur segments intermédiaires |
| **Passager 3** | `hamza.idrissi@covoiturage.ma` | Hamza Idrissi | `ROLE_USER` | Réservations passées et annulées |

---

## 5. Lancer les tests automatisés (PHPUnit)

Toute la suite de tests (45 tests unitaires, d'intégration, de concurrence et fonctionnels HTTP) s'exécute directement dans le conteneur `php` :

```bash
# Lancer l'intégralité de la suite PHPUnit (45 tests, 285 assertions)
docker compose exec php php bin/phpunit

# Lancer spécifiquement le test d'anti-surréservation et verrouillage PESSIMISTIC_WRITE
docker compose exec php php bin/phpunit tests/Service/BookingConcurrencyTest.php
```

---

## 6. Commandes utiles au quotidien

```bash
# Consulter l'état des conteneurs et leurs healthchecks
docker compose ps

# Suivre les logs d'initialisation du conteneur PHP
docker compose logs -f php

# Recharger proprement les fixtures de démonstration
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction

# Vérifier la validité du schéma Doctrine par rapport aux entités
docker compose exec php php bin/console doctrine:schema:validate

# Vider le cache Symfony
docker compose exec php php bin/console cache:clear
```

---

## 7. Dépannage courant (Troubleshooting)

### 1. Conflit de port (`Bind for 0.0.0.0:8088 failed: port is already allocated`)
Les ports exposés sur la machine hôte sont configurables via variables d'environnement ou dans un fichier `.env.local` :
- `NGINX_PORT` (défaut : `8088`, choisi pour éviter les conflits fréquents sur `8080`)
- `PMA_PORT` (défaut : `8081`)
- `MYSQL_HOST_PORT` (défaut : `3308`)

Exemple pour lancer l'application sur le port `8090` :
```bash
NGINX_PORT=8090 docker compose up -d --build
```
*(Sous PowerShell Windows : `$env:NGINX_PORT=8090; docker compose up -d --build`)*

### 2. Docker Desktop n'est pas démarré
Si vous obtenez l'erreur `error during connect: ... open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified`, lancez **Docker Desktop** et attendez que l'icône de la baleine soit stable avant de relancer `docker compose up -d --build`.

### 3. Fins de ligne Windows (`CRLF`) sur `docker/php/entrypoint.sh`
Sous Windows, Git peut convertir les fins de ligne LF en CRLF lors du clonage (`exec /usr/local/bin/entrypoint.sh: no such file or directory`).
Deux protections sont déjà intégrées au projet :
1. Le fichier [`.gitattributes`](.gitattributes) force `eol=lf` sur tous les scripts `*.sh`.
2. Le [`docker/php/Dockerfile`](docker/php/Dockerfile) exécute automatiquement `sed -i 's/\r$//' /usr/local/bin/entrypoint.sh` lors du build de l'image.
Si vous modifiez `entrypoint.sh` manuellement sous Windows, reconstruisez simplement l'image :
```bash
docker compose build --no-cache php && docker compose up -d
```

### 4. Réinitialiser complètement les volumes (base de données ou vendor corrompus)
Pour repartir de zéro comme sur une machine neuve (supprime les volumes MySQL, `vendor/` et `var/`) :
```bash
docker compose down -v
docker compose up -d --build
```

### 5. Page temporairement indisponible (`502 Bad Gateway`) dans les premières secondes
Lors du tout premier `docker compose up --build`, le conteneur `php` installe les paquets Composer, les assets Stimulus, applique les migrations et charge les fixtures avant de démarrer `php-fpm`. Surveillez la progression avec :
```bash
docker compose logs -f php
```
Dès que le message `==> [6/6] ...` puis `ready to handle connections` apparaît, l'application est prête sur http://localhost:8088.

---

## 8. Note sur les API externes Nominatim et OSRM

Le projet intègre deux services publics OpenStreetMap :
- **Nominatim** (`https://nominatim.openstreetmap.org`) pour le géocodage des villes marocaines (`countrycodes=ma`).
- **OSRM** (`https://router.project-osrm.org`) pour le calcul d'itinéraire routier (distance, durée, tracé GeoJSON) via l'endpoint interne `/api/route`.

Conformément aux conditions d'utilisation de la fondation OpenStreetMap :
1. **Usage léger et identifié** : chaque requête envoie un en-tête `User-Agent` explicite (`AtlasCovoitMaroc/1.0`) et applique un **rate limiter** de 1 requête/seconde (`NominatimClient`).
2. **Mise en cache automatique** : les réponses Nominatim sont mises en cache pendant **30 jours** et les itinéraires OSRM pendant **7 jours** dans `cache.app`.
3. **Mode dégradé automatique (Fallback Haversine)** : si la machine n'a pas d'accès Internet ou si le serveur public OSRM/Nominatim est indisponible (timeout > 4 s ou erreur HTTP 5xx/429), `RoutePricingService` bascule automatiquement sur une formule de **distance à vol d'oiseau Haversine majorée d'un coefficient routier de 1,25×** et génère une ligne directe GeoJSON. L'application et la publication de trajet ne sont **jamais bloquées** par une panne réseau externe, et les tests automatisés utilisent exclusivement `MockHttpClient`.
