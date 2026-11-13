# Journal des décisions techniques et métier (`DECISIONS.md`)

Ce document recense de manière chronologique les décisions d'architecture, les hypothèses techniques retenues ainsi que les réponses argumentées aux **5 questions métier à trancher** du sujet PFE Challenge EMC (Sujet 3 — Covoiturage régional au Maroc).

---

## Partie 1 — Journal des décisions techniques et d'architecture

## 2026-11-13 — Stack Docker PHP 8.3-FPM, Nginx et MySQL 8.0
- **Contexte** : Le squelette Symfony initial proposait un `compose.yaml` orienté PostgreSQL sans serveur web dédié.
- **Décision** : Mise en place d'une stack multi-conteneurs (`mysql:8.0` avec `healthcheck`, `php:8.3-fpm-alpine` avec `composer` et script `entrypoint.sh`, `nginx:1.27-alpine`, et `phpmyadmin:5.2` dans `compose.override.yaml`). Les répertoires `vendor/` et `var/` sont isolés dans des volumes Docker nommés (`php_vendor`, `php_var`).
- **Justification** : L'isolation de `vendor/` et `var/` sur le système de fichiers Linux natif de Docker évite la pénalité d'I/O du montage bind Windows/WSL2 tout en conservant le rechargement à chaud sur `src/`, `templates/` et `config/`.

## 2026-11-13 — Valeurs publiques non secrètes (`app:app`) dans `.env`
- **Contexte** : Symfony charge `.env` par défaut dans tous les environnements et la règle de sécurité interdit de commiter le moindre secret dans Git.
- **Décision** : Utiliser des identifiants purement génériques de développement local (`MYSQL_USER=app`, `MYSQL_PASSWORD=app`, `DATABASE_URL="mysql://app:app@database:3306/covoiturage?serverVersion=8.0&charset=utf8mb4"`) dans `.env` et `.env.example`.
- **Justification** : Ces valeurs publiques non secrètes permettent à tout évaluateur de cloner le dépôt et de lancer `docker compose up --build` immédiatement, tandis que les secrets réels restent strictement confinés à `.env.local` (ignoré par `.gitignore`) ou aux variables d'environnement de production.

## 2026-11-13 — Choix du type `DECIMAL` pour les prix et les coordonnées GPS
- **Contexte** : Le système manipule des montants monétaires en dirhams (`priceFromPrevious`, `price`, `refundAmount`) et des coordonnées géographiques (`latitude`, `longitude`).
- **Décision** : Utiliser `DECIMAL(10, 2)` pour les montants financiers et `DECIMAL(9, 6)` pour les coordonnées GPS, mappés en `string` côté PHP par Doctrine (`Types::DECIMAL`).
- **Justification** : Les flottants binaires (`FLOAT` / `DOUBLE` IEEE-754) introduisent des imprécisions d'arrondi sur les fractions décimales (`0.1 + 0.2 != 0.3`), ce qui est inacceptable pour la somme des tarifs de segments ou le calcul d'un remboursement. `DECIMAL(10, 2)` garantit une exactitude au centime près, et `DECIMAL(9, 6)` assure une précision géographique constante (~11 cm) sans dérive.

## 2026-11-13 — Prix de la réservation (`Booking::price`) figé à l'achat
- **Contexte** : Le prix d'une portion découle de la somme des `priceFromPrevious` des étapes traversées multipliée par le nombre de places réservées (`seats`).
- **Décision** : Stocker explicitement la colonne `price` (`DECIMAL(10, 2)`) dans la table `booking` au moment de la création de la réservation.
- **Justification** : Une réservation validée constitue un engagement contractuel à prix ferme. Figer `booking.price` protège l'historique financier contre toute modification ultérieure du trajet et sert de base fiable et auditée pour calculer `refundAmount` en cas d'annulation.

## 2026-11-13 — Politique `ON DELETE CASCADE` limitée à `trip_stop`
- **Contexte** : Il faut définir le comportement des clés étrangères lors de la suppression d'enregistrements liés.
- **Décision** : Appliquer `ON DELETE CASCADE` **uniquement** sur `trip_stop.trip_id` (`Trip` → `TripStop`). Toutes les autres relations (`Booking` → `Trip`, `Booking` → `TripStop`, `Booking` → `User`, `Review` → `Booking`, `Trip` → `User`, `Trip` → `Vehicle`) conservent la restriction par défaut (`RESTRICT`).
- **Justification** : Un arrêt (`TripStop`) est un objet-valeur de composition pure qui n'a aucune existence propre en dehors de son `Trip`. À l'inverse, dès qu'un trajet possède des réservations (`Booking`) ou des avis (`Review`), la base de données doit bloquer toute suppression physique (`DELETE`) au profit d'une annulation logique (`status = CANCELLED`, `cancelledAt`), préservant ainsi l'intégrité référentielle et la traçabilité comptable.

## 2026-11-13 — Règle métier `trip.seatsTotal <= vehicle.seats` vérifiée dans le service
- **Contexte** : La capacité maximale d'un véhicule est portée par `Vehicle::seats`, tandis que le nombre de places proposées sur un trajet est porté par `Trip::seatsTotal`.
- **Décision** : Conserver `seatsTotal` sur `Trip` et vérifier la règle `1 <= trip.seatsTotal <= trip.vehicle.seats` (ainsi que `trip.vehicle.owner === trip.driver`) dans le service métier de création/modification de trajet (et via validation Symfony).
- **Justification** : Le conducteur peut légitimement proposer moins de places que la capacité physique de son véhicule (ex. 2 places mises en vente sur une voiture de 4 places passagers pour laisser de l'espace aux bagages).

## 2026-11-13 — Renommage de `trip_stop.position` en `stop_order` (`stopOrder`)
- **Contexte** : Le terme `position` initialement utilisé pour numéroter les étapes d'un trajet (`0, 1, 2...`) entre en collision avec la fonction SQL standard `POSITION(substr IN str)` de MySQL.
- **Décision** : Renommer la colonne SQL en `stop_order` et la propriété PHP en `stopOrder` via une migration dédiée (`Version20261113223701`), avec la contrainte d'unicité `uniq_trip_stop_trip_order (trip_id, stop_order)`.
- **Justification** : Évite toute ambiguïté dans les requêtes SQL/DQL et exprime plus clairement la sémantique d'ordre séquentiel des étapes.

---

## Partie 2 — Les 5 « Questions à trancher » du sujet (propositions justifiées)

### Question 1 — Une place libérée à une étape intermédiaire peut-elle être revendue pour la suite ?
- **Réponse retenue : OUI.**
- **Justification et mise en œuvre** :
  - C'est l'intérêt majeur du découpage d'un trajet en **segments élémentaires** $[k, k+1[$ entre arrêts consécutifs (`stopOrder`).
  - Exemple concret : sur un trajet **Casablanca (`0`) → Marrakech (`1`) → Agadir (`2`)** avec `seatsTotal = 3`, si un passager réserve 1 place sur **Casablanca → Marrakech** (segment $[0, 1[$), il descend à Marrakech. Cette place est donc libre sur le segment suivant **Marrakech → Agadir** ($[1, 2[$) et peut être réservée par un second passager montant à Marrakech.
  - De même, si une réservation `CONFIRMED` est annulée (`CANCELLED_BY_PASSENGER` ou `CANCELLED_BY_DRIVER`), elle est immédiatement exclue du calcul d'occupation des segments, ce qui remet automatiquement ses places en disponibilité sur les portions concernées.

### Question 2 — Comment se calcule le prix d'une portion ?
- **Réponse retenue : Somme additive des tarifs des segments élémentaires traversés, multipliée par le nombre de places réservées.**
- **Justification et formule** :
  - Lors de la publication du trajet, le conducteur fixe le prix unitaire de chaque segment élémentaire `priceFromPrevious` sur chaque arrêt d'ordre $k \ge 1$ (pour l'arrêt de départ `stopOrder = 0`, `priceFromPrevious = 0.00`).
  - Pour une réservation de $s$ places (`booking.seats`) entre `fromStop` (ordre $i$) et `toStop` (ordre $j$, avec $i < j$), le prix est calculé de manière déterministe :
    $$\text{Prix}(i, j, s) = s \times \sum_{k = i+1}^{j} \text{stop}_k.\text{priceFromPrevious}$$
  - **Pourquoi ce modèle ?** Il garantit la cohérence tarifaire (pas de paradoxe où `Casablanca → Marrakech` + `Marrakech → Agadir` coûterait moins cher ou plus cher que `Casablanca → Agadir`), évite au conducteur de saisir $\frac{n(n+1)}{2}$ prix manuellement pour toutes les paires d'arrêts, et reste stocké en valeur figée dans `booking.price` lors de la réservation.

### Question 3 — Que se passe-t-il si le conducteur modifie un horaire ou supprime une étape après réservations ?
- **Réponse retenue : Politique de verrouillage progressif selon l'existence de réservations confirmées (`CONFIRMED`).**
- **Justification et règles métier** :
  1. **Tant qu'aucune réservation `CONFIRMED` n'existe sur le trajet** : le conducteur peut librement modifier les horaires, les prix des segments, ou ajouter/supprimer des étapes intermédiaires.
  2. **Dès qu'au moins une réservation `CONFIRMED` existe** :
     - **Suppression d'une étape** :
       - Il est **strictement interdit** de supprimer une étape qui est référencée comme `fromStop` ou `toStop` par une réservation (protégé à la fois par la couche Service et par la clé étrangère SQL `RESTRICT`).
       - Plus généralement, dès qu'une réservation est confirmée sur le trajet, la structure des étapes (ajout/suppression/réordonnancement) et les tarifs des segments sont **verrouillés** afin de ne pas altérer le contrat passé avec les passagers inscrits.
     - **Modification d'un horaire** :
       - Une modification mineure d'horaire (ajustement avant le départ) reste autorisée uniquement si la chronologie stricte des étapes est respectée (`stop[k].departureTime < stop[k+1].arrivalTime`) et que le trajet n'a pas encore débuté ; les passagers concernés en sont informés.
     - **Si le conducteur ne peut plus assurer une étape réservée** : il doit annuler le trajet complet (passage du trajet à `TripStatus::CANCELLED` et des réservations confirmées à `BookingStatus::CANCELLED_BY_DRIVER` avec remboursement intégral `refundAmount = booking.price`).

### Question 4 — Un passager peut-il réserver plusieurs places en une fois ?
- **Réponse retenue : OUI, via le champ `Booking::seats` ($1 \le \text{seats} \le \text{placesDisponibles}(i, j)$).**
- **Justification et règles métier** :
  - Un passager voyageant en famille ou avec des collègues doit pouvoir réserver plusieurs places (ex. 2 ou 3 places) en une seule transaction atomique, sans créer plusieurs comptes ni multiplier les réservations identiques.
  - **Conditions de validation** :
    1. Le passager ne peut pas être le conducteur du trajet (`booking.passenger !== trip.driver`).
    2. `booking.seats >= 1` et `booking.seats <= availableSeats(fromStop, toStop)` (vérifié sur **chacun** des segments élémentaires entre `fromStop` et `toStop` au sein d'une transaction).
    3. Le prix figé `booking.price` correspond au prix unitaire de la portion multiplié par `booking.seats`.
    4. En cas d'annulation, l'annulation porte sur la réservation entière (`booking.seats` places libérées simultanément).

### Question 5 — Qui note qui, et quand ?
- **Réponse retenue : Évaluation croisée (passager $\leftrightarrow$ conducteur) uniquement après la réalisation effective du trajet (`TripStatus::COMPLETED`), limitée à un avis par réservation et par auteur.**
- **Justification et règles métier** :
  - **Qui note qui ?**
    - Pour chaque réservation ayant abouti (statut `BookingStatus::CONFIRMED` sur un trajet terminé), **deux avis au maximum** peuvent être déposés :
      1. Le **passager** (`booking.passenger`) peut noter le **conducteur** (`trip.driver`).
      2. Le **conducteur** (`trip.driver`) peut noter le **passager** (`booking.passenger`).
    - Tout utilisateur tiers ou non impliqué dans cette réservation est rejeté. L'auto-évaluation (`author === target`) est interdite.
  - **Quand ?**
    - Uniquement lorsque le trajet est passé au statut **`TripStatus::COMPLETED`** (ou après la date/heure d'arrivée effectuée) ET que la réservation est restée **`BookingStatus::CONFIRMED`** (les réservations annulées ne donnent pas droit à un avis).
  - **Garantie d'unicité** :
    - La contrainte SQL `UNIQUE (booking_id, author_id)` (`uniq_review_booking_author`) et la contrainte Symfony `#[UniqueEntity(fields: ['booking', 'author'])]` garantissent techniquement qu'un auteur ne peut déposer qu'un seul avis par réservation.
