# Guide d'installation et d'exécution — AtlasCovoit Maroc

Ce guide permet d'installer, lancer et vérifier l'application **AtlasCovoit Maroc** sur n'importe quelle machine propre (Windows, macOS ou Linux) en une seule commande, **sans XAMPP, WAMP, PHP ni MySQL installés localement**.

---

## 1. Prérequis

Seuls deux outils sont nécessaires sur la machine hôte :

1. **Docker Desktop** (incluant Docker Engine et Docker Compose v2) — démarré et actif.
2. **Git** — pour cloner le dépôt.

> [!NOTE]
> Aucune installation locale de PHP, Composer, Node.js ou MySQL n'est requise : l'ensemble de l'environnement d'exécution est encapsulé dans les conteneurs Docker.

---

## 2. Clonage du dépôt

```bash
git clone https://github.com/OUABEN/covoiturage-maroc.git
cd covoiturage-maroc
```

---

## 3. Lancement en une seule commande

```bash
docker compose up --build -d
```

*(Vous pouvez également lancer `docker compose up --build` au premier plan pour observer les journaux d'initialisation en direct.)*

### Ce qui s'exécute automatiquement au premier démarrage (`docker/php/entrypoint.sh`) :
1. **Génération de `APP_SECRET`** : si `APP_SECRET` est vide, un secret aléatoire de 64 caractères hexadécimaux est généré et inscrit automatiquement dans `.env.local` (aucun secret réel n'est stocké dans Git).
2. **Dépendances PHP & Frontend** : exécution de `composer install --no-interaction --prefer-dist` et installation des assets Importmap (`importmap:install`).
3. **Attente active de MySQL 8.0** : vérification par healthcheck Docker et boucle PDO jusqu'à ce que le serveur MySQL accepte les connexions.
4. **Migrations SQL** : exécution automatique de `php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration`.
5. **Fixtures de démonstration** : si `APP_ENV=dev` et que la table `city` est vide, chargement automatique des 10 villes marocaines, 8 utilisateurs, 5 véhicules, 8 trajets multi-étapes, 14 réservations et avis croisés (`doctrine:fixtures:load --no-interaction`).

Pour suivre la progression de l'initialisation :
```bash
docker compose logs -f php
```
Lorsque le message `ready to handle connections` apparaît, l'application est prête.

---

## 4. URLs d'accès

| Service | URL par défaut | Identifiants / Détails |
|---|---|---|
| **Application Web (Accueil)** | http://localhost:8088 | Port configurable via `NGINX_PORT` (défaut `8088` pour éviter les conflits sur `8080`) |
| **Back-office EasyAdmin** | http://localhost:8088/admin | Réservé au compte `ROLE_ADMIN` (`admin@covoiturage.ma` / `demo1234`) |
| **API Itinéraire & Tarif OSRM** | http://localhost:8088/api/route?from=1&to=2 | Retourne distance, durée, géométrie GeoJSON et prix suggéré |
| **phpMyAdmin** | http://localhost:8081 | Serveur : `database` · Utilisateur : `app` · Mot de passe : `app` |
| **MySQL 8.0 (depuis l'hôte)** | `127.0.0.1:3308` | Base : `covoiturage` · Utilisateur : `app` · Mot de passe : `app` |

---

## 5. Comptes de démonstration

Tous les comptes chargés par les fixtures partagent le mot de passe unique : **`demo1234`**

> Chaque utilisateur inscrit (`ROLE_USER`) possède un **profil unifié** lui permettant à la fois de réserver des places comme **passager** et de publier des trajets comme **conducteur**.

| Rôle principal | Adresse e-mail | Mot de passe | Nom complet | Rôle Symfony | Véhicules associés |
|---|---|---|---|---|---|
| **Administrateur** | `admin@covoiturage.ma` | `demo1234` | Karim El Mansouri | `ROLE_ADMIN` | — |
| **Conducteur 1** | `youssef.benali@covoiturage.ma` | `demo1234` | Youssef Benali | `ROLE_USER` | Dacia Duster (4 pl.), Renault Clio 5 (3 pl.) |
| **Conducteur 2** | `fatima.alaoui@covoiturage.ma` | `demo1234` | Fatima-Zahra Alaoui | `ROLE_USER` | Peugeot 3008 (4 pl.) |
| **Conducteur 3** | `omar.aitbrahim@covoiturage.ma` | `demo1234` | Omar Aït Brahim | `ROLE_USER` | Volkswagen Golf 8 (4 pl.) |
| **Conducteur 4** | `salma.chraibi@covoiturage.ma` | `demo1234` | Salma Chraïbi | `ROLE_USER` | Hyundai Tucson (4 pl.) |
| **Passager 1** | `mehdi.tazi@covoiturage.ma` | `demo1234` | Mehdi Tazi | `ROLE_USER` | — |
| **Passager 2** | `nadia.berrada@covoiturage.ma` | `demo1234` | Nadia Berrada | `ROLE_USER` | — |
| **Passager 3** | `hamza.idrissi@covoiturage.ma` | `demo1234` | Hamza Idrissi | `ROLE_USER` | — |

---

## 6. Lancer les tests automatisés (PHPUnit)

La suite complète comporte **45 tests et 285 assertions** (tests unitaires, intégration Doctrine, concurrence `PESSIMISTIC_WRITE`, sécurité, contrôleurs Web et API externe mockée) :

```bash
# Exécuter toute la suite de tests (crée et peuple automatiquement la base covoiturage_test)
docker compose exec php php bin/phpunit

# Exécuter spécifiquement le test de concurrence et verrouillage pessimiste
docker compose exec php php bin/phpunit tests/Service/BookingConcurrencyTest.php
```

---

## 7. Commandes utiles au quotidien

```bash
# Voir l'état de santé des conteneurs
docker compose ps

# Consulter les journaux du conteneur PHP
docker compose logs -f php

# Valider le schéma Doctrine par rapport aux entités PHP
docker compose exec php php bin/console doctrine:schema:validate

# Recharger manuellement les fixtures de démonstration (réinitialise les données)
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction

# Vider le cache Symfony
docker compose exec php php bin/console cache:clear

# Ouvrir un terminal interactif dans le conteneur PHP
docker compose exec php sh

# Arrêter les conteneurs (en conservant les données MySQL)
docker compose stop
```

---

## 8. Dépannage courant (Troubleshooting)

### 1. Port déjà occupé (`Bind for 0.0.0.0:8088 failed: port is already allocated`)
Les ports exposés sont configurables via variables d'environnement ou dans un fichier `.env.local` :
```bash
# Exemple en créant un fichier .env.local :
echo "NGINX_PORT=8090" >> .env.local
echo "PMA_PORT=8091" >> .env.local
echo "MYSQL_HOST_PORT=3309" >> .env.local
docker compose up -d
```

### 2. Docker Desktop n'est pas démarré (`error during connect` / `open //./pipe/dockerDesktopLinuxEngine`)
Lancez l'application **Docker Desktop** et attendez que l'icône de la baleine soit stable avant de relancer `docker compose up --build -d`.

### 3. Fins de ligne CRLF sur `entrypoint.sh` sous Windows (`exec /usr/local/bin/entrypoint.sh: no such file or directory`)
Le projet inclut un fichier `.gitattributes` forçant `eol=lf` sur les scripts `*.sh`, et le `Dockerfile` exécute automatiquement `sed -i 's/\r$//' /usr/local/bin/entrypoint.sh` lors du build. Si toutefois vous modifiez `docker/php/entrypoint.sh` sous Windows avec un éditeur en CRLF, reconstruisez l'image :
```bash
docker compose build --no-cache php
docker compose up -d
```

### 4. Réinitialiser complètement les volumes (base de données ou `vendor` corrompus)
Pour repartir d'un état 100 % vierge (supprime les volumes `database_data`, `php_vendor` et `php_var`, puis relance migrations + fixtures) :
```bash
docker compose down -v
docker compose up --build -d
```

### 5. MySQL pas encore prêt ou page temporairement indisponible (`502 Bad Gateway` dans les premières secondes)
Au tout premier lancement sur une machine vierge, le conteneur `php` installe les paquets Composer puis exécute les migrations et les fixtures avant de démarrer `php-fpm`. Surveillez `docker compose logs -f php` : dès que l'étape `[4/4]` est terminée, Nginx sert immédiatement l'application.

---

## 9. Note sur les API externes (Nominatim & OSRM)

L'application interroge deux services publics gratuits (sans clé API) :
- **Nominatim (OpenStreetMap)** pour le géocodage des villes marocaines (`NominatimClient`).
- **OSRM (`router.project-osrm.org`)** pour le calcul de la distance routière réelle, de la durée, du tracé GeoJSON et du prix suggéré (`OsrmClient` + `RoutePricingService` via `GET /api/route`).

**Bonnes pratiques intégrées :**
- En-tête `User-Agent` explicite conforme à la politique d'utilisation OSM.
- Limitation de débit (**1 requête/seconde**) et mise en cache Symfony (**30 jours** pour Nominatim, **7 jours** pour OSRM).
- **Mode dégradé automatique (Fallback Haversine)** : si la machine n'a pas d'accès Internet ou si le serveur public OSRM ne répond pas dans le délai de 4 secondes, `RoutePricingService` bascule automatiquement sur un calcul trigonométrique Haversine (`distance à vol d'oiseau × 1,25`, vitesse moyenne `80 km/h`, tarif `0,60 MAD/km` arrondi aux `5 MAD`). Aucune fonctionnalité ni aucun test ne dépend d'une connexion externe obligatoire.
