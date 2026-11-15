# AtlasCovoit Maroc — Plateforme de Covoiturage Régional Multi-Étapes (PFE Challenge EMC · Sujet 3)

Plateforme web complète de covoiturage interurbain et régional au Maroc développée avec **Symfony 7.4**, **PHP 8.3**, **Doctrine ORM 3.7**, **MySQL 8.0**, **Stimulus / AssetMapper** et **Leaflet / OpenStreetMap**.

La particularité cœur du projet repose sur la **modélisation de trajets multi-étapes découpés en segments élémentaires** (ex. *Agadir → Marrakech → Casablanca*), permettant :
- de calculer dynamiquement les places disponibles et le tarif exact sur n'importe quelle sous-portion $[i, j[$ ;
- de **revendre automatiquement une place libérée** à une étape intermédiaire sur les tronçons suivants ;
- de garantir **zéro surréservation** grâce au verrouillage pessimiste SQL (`PESSIMISTIC_WRITE` / `SELECT ... FOR UPDATE`) dans `BookingService`.

---

## 1. Niveaux atteints (Niveaux 1 à 5)

| Niveau | Périmètre | Statut |
|---|---|---|
| **Niveau 1 — Socle** | Entités, migrations SQL, fixtures réalistes marocaines, authentification, véhicules, publication et recherche de trajets par segment | **100 % Terminé** |
| **Niveau 2 — Cœur métier** | `AvailabilityService`, `BookingService` (`PESSIMISTIC_WRITE`), `CancellationService` (règles 100 % / 50 % / 0 % / décalage > 30 min), `ReviewService`, `TripService`, 31 tests métier | **100 % Terminé** |
| **Niveau 3 — Application web complète** | Contrôleurs HTTP, sécurité (`form_login`, `remember_me`, CSRF, Voters `BookingVoter`/`VehicleVoter`/`TripVoter`), aperçu du remboursement avant confirmation, profils publics anonymisés (sans email/téléphone), back-office EasyAdmin 4 | **100 % Terminé** |
| **Niveau 4 — API externes & Cartographie** | Clients HTTP `NominatimClient` (rate limiter 1 req/s + cache 30j) et `OsrmClient` (cache 7j), `RoutePricingService` avec fallback Haversine hors-ligne, endpoint JSON `/api/route`, carte interactive Leaflet | **100 % Terminé** |
| **Niveau 5 — Livraison & Reproductibilité** | Stack Docker clé en main (`docker compose up -d --build`), auto-configuration (`APP_SECRET`, migrations dev/test, assets, fixtures), [`INSTALL.md`](INSTALL.md), [`.env.example`](.env.example), 45 tests PHPUnit verts | **100 % Terminé** |

---

## 2. Démarrage rapide (1 commande)

Aucun prérequis local autre que **Docker Desktop** et **Git** (ni PHP local, ni Composer, ni XAMPP).

```bash
git clone https://github.com/anasouali06-dev/covoiturage-maroc.git
cd covoiturage-maroc
docker compose up -d --build
```

- **Application Web** : http://localhost:8088 *(configurable via `NGINX_PORT`)*
- **Back-office EasyAdmin** : http://localhost:8088/admin
- **API Route OSRM** : http://localhost:8088/api/route?from=1&to=2
- **phpMyAdmin** : http://localhost:8081 *(`app` / `app`)*

Consultez le guide détaillé **[INSTALL.md](INSTALL.md)** pour la liste complète des comptes de démonstration (mot de passe commun : **`demo1234`**), les commandes utiles et le guide de dépannage.

---

## 3. Ce qui fonctionne (Vérifié par 45 tests automatisés)

- **Recherche et réservation par segment** : recherche sur les sous-portions $[fromStop, toStop]$ avec filtre de capacité minimale par segment et prix cumulé exact.
- **Anti-surréservation transactionnelle** : `BookingService` est le **seul point d'entrée** pour créer une réservation, protégé par `LockMode::PESSIMISTIC_WRITE` sur l'entité `Trip`.
- **Annulation et remboursement déterministe (`CancellationService`)** :
  - **100 %** si annulée par le conducteur, si annulée > 24h avant le départ du segment, ou si le trajet a subi un décalage horaire > 30 min par rapport à `originalDepartureTime` / `originalArrivalTime`.
  - **50 %** entre 2h et 24h avant le départ.
  - **0 %** à moins de 2h du départ.
  - **Aperçu obligatoire avant confirmation** sur `GET /mes-reservations/{id}/annuler`.
- **Espaces Passager & Conducteur sécurisés par Voters** : `BookingVoter`, `VehicleVoter`, `TripVoter`.
- **Profils publics respectueux de la vie privée** (`/utilisateurs/{id}`) : affiche prénom, nom, note moyenne et avis reçus, sans jamais exposer l'e-mail ni le numéro de téléphone.
- **Back-office EasyAdmin 4** (`/admin`) : réservé à `ROLE_ADMIN`, hachage automatique des mots de passe dans `UserCrudController`, création manuelle de `Booking` désactivée (`Action::NEW` retirée) et action personnalisée d'annulation déléguant à `CancellationService`.
- **Géocodage Nominatim, routage OSRM & carte Leaflet** : endpoint `/api/route?from=ID&to=ID`, mode dégradé Haversine automatique si hors-ligne, carte Leaflet avec tuiles CartoDB Dark Matter / Positron basculant dynamiquement selon le thème.
- **Design Midnight Black `#0B0B0C` / Pure White `#FFFFFF` / Lime `#C6F432`** : sélecteur de thème clair/sombre sans flash (`localStorage` + `prefers-color-scheme`), hero 3D interactif, calculateur de prix en temps réel via Stimulus sur la fiche trajet, pages connexion/inscription en split-screen.

---

## 4. Ce qui reste à faire (État des lieux honnête)

1. **Finitions visuelles du Groupe 2b (reportées pour prioriser la livraison)** :
   - Les pages du **Groupe 1** (Accueil, Hero 3D, Recherche, Navbar sticky verre, Mode Clair/Sombre) et du **Groupe 2a** (Détail d'un trajet avec timeline verticale, carte Leaflet `/api/route` et calculateur Stimulus ; Connexion et Inscription en split-screen avec validation champ par champ) sont intégralement refondues.
   - Les pages du **Groupe 2b** (Espace passager, Espace conducteur, Profil public, Pages d'erreur HTTP personnalisées 403/404/500 et surcouche CSS dédiée EasyAdmin) héritent du système de design global (`assets/styles/app.css` en mode sombre/clair) et sont **100 % fonctionnelles et validées par PHPUnit**, mais n'intègrent pas encore les composants visuels avancés prévus au Groupe 2b (stepper Stimulus multi-étapes d'ajout dynamique d'arrêts sur `/trajets/nouveau`, barres horizontales de répartition 5★→1★ sur le profil, gabarits d'erreur personnalisés `bundles/TwigBundle/Exception/`).
2. **Génération des 13 photographies IA restantes** :
   - La photographie photoréaliste d'**Agadir** (`public/images/cities/agadir.webp` et `agadir-sm.webp`) a été générée avec le modèle d'image Gemini et intégrée avec `srcset` responsive.
   - En raison de la limite de quota de l'API de génération d'images (`429 RESOURCE_EXHAUSTED`), les 9 autres villes, les 3 véhicules et le visuel hero utilisent le composant de fallback propre (`templates/components/city_image.html.twig` et `car_image.html.twig` : dégradé sombre `#0B0B0C` avec badge Lime `#C6F432` et nom de la ville, garantissant zéro image cassée). Tous les prompts prêts à être exécutés sont documentés dans **[docs/IMAGE-PROMPTS.md](docs/IMAGE-PROMPTS.md)**.
3. **Paiement en ligne et envoi d'e-mails Transactionnels** :
   - Le calcul des prix et des remboursements (`totalPrice`, `refundAmount`) est complet en base de données, mais aucun prestataire de paiement bancaire (Stripe/CMI) n'est branché. `MAILER_DSN` est configuré sur `null://null` par défaut.

---

## 5. Documentation complète (`docs/`)

- **[INSTALL.md](INSTALL.md)** : Guide d'installation pas à pas, comptes de démo, tests et dépannage.
- **[docs/MATRICE-FONCTIONNELLE.md](docs/MATRICE-FONCTIONNELLE.md)** : Matrice de traçabilité complète (Fonctionnalité → Route → Contrôleur → Service → Test PHPUnit → Statut).
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** : Architecture en couches, services métier, algorithme de disponibilité par segment, verrouillage `PESSIMISTIC_WRITE` et règles de remboursement.
- **[docs/MODELE.md](docs/MODELE.md)** : Diagramme Entité-Association (`Mermaid erDiagram`), dictionnaire de données et index SQL.
- **[docs/DECISIONS.md](docs/DECISIONS.md)** : Journal des décisions architecturales et réponses aux 5 questions métier du sujet.
- **[docs/TESTS.md](docs/TESTS.md)** : Stratégie de test et couverture des 45 tests automatisés (285 assertions).
- **[docs/IMAGE-PROMPTS.md](docs/IMAGE-PROMPTS.md)** & **[docs/CREDITS.md](docs/CREDITS.md)** : Prompts photoréalistes et crédits des ressources visuelles et cartographiques.
