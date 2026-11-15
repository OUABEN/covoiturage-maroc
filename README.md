# AtlasCovoit Maroc — Plateforme de Covoiturage Régional Multi-Étapes

Plateforme web de covoiturage interurbain et régional au Maroc développée avec **Symfony 7.4**, **PHP 8.3**, **Doctrine ORM 3.7** et **MySQL 8.0** dans le cadre du **PFE Challenge EMC (Sujet 3)**.

La particularité cœur du projet est la **modélisation de trajets multi-étapes découpés en segments** (ex. *Agadir → Marrakech → Casablanca*), permettant :
- de réserver uniquement une sous-portion d'un trajet (`[fromStop, toStop]`),
- de calculer dynamiquement les places restantes segment par segment et de **revendre une place libérée à une étape intermédiaire**,
- de garantir l'**absence totale de surréservation** grâce à un verrouillage transactionnel pessimiste (`PESSIMISTIC_WRITE`),
- de calculer automatiquement les remboursements en cas d'annulation (`100 %`, `50 %` ou `0 %`, et `100 %` si le trajet a été décalé de plus de 30 minutes).

---

## 1. Démarrage rapide (Docker clé en main)

Aucune installation locale de PHP, Composer ou MySQL n'est requise. Consultez **[INSTALL.md](INSTALL.md)** pour le guide complet et le dépannage.

```bash
git clone https://github.com/OUABEN/covoiturage-maroc.git
cd covoiturage-maroc
docker compose up --build -d
```

- **Application Web** : http://localhost:8088 *(configurable via `NGINX_PORT`)*
- **Back-office EasyAdmin** : http://localhost:8088/admin
- **phpMyAdmin** : http://localhost:8081 *(utilisateur `app` / mot de passe `app`)*
- **Suite de tests (45 tests, 285 assertions)** :
  ```bash
  docker compose exec php php bin/phpunit
  ```

### Comptes de démonstration (mot de passe commun : `demo1234`)
| Rôle | Email | Nom | Détails |
|---|---|---|---|
| **Admin** | `admin@covoiturage.ma` | Karim El Mansouri | Accès `/admin` (`ROLE_ADMIN`) |
| **Conducteur** | `youssef.benali@covoiturage.ma` | Youssef Benali | Dacia Duster (4 pl.), Renault Clio 5 (3 pl.) |
| **Conducteur** | `fatima.alaoui@covoiturage.ma` | Fatima-Zahra Alaoui | Peugeot 3008 (4 pl.) |
| **Passager** | `mehdi.tazi@covoiturage.ma` | Mehdi Tazi | Réservations actives et terminées |
| **Passager** | `nadia.berrada@covoiturage.ma` | Nadia Berrada | Réservations actives et terminées |

---

## 2. Niveaux atteints et bilan honnête

### Ce qui fonctionne et est vérifié par tests automatisés (Niveaux 1 à 5)

| Niveau | Fonctionnalités livrées | État |
|---|---|---|
| **Niveau 1 — Socle** | Modèle à 7 entités (`User`, `Vehicle`, `City`, `Trip`, `TripStop`, `Booking`, `Review`), authentification unifiée conducteur/passager, inscription/connexion CSRF, gestion des véhicules, publication de trajets multi-étapes, recherche par portion et date | **100 % opérationnel** |
| **Niveau 2 — Segments & Concurrence** | Calcul exact de disponibilité par segment (`AvailabilityService`), réservation atomique sous verrou `PESSIMISTIC_WRITE` (`BookingService`), figeage de `totalPrice`, politique d'annulation et remboursement calculé avant confirmation (`CancellationService`), avis croisés après trajet terminé (`ReviewService`) | **100 % opérationnel** |
| **Niveau 3 — Sécurité, Voters & Admin** | Hiérarchie `ROLE_ADMIN > ROLE_USER`, `BookingVoter`, `VehicleVoter`, `TripVoter`, profils publics sans fuite d'e-mail/téléphone, back-office **EasyAdmin 5** (réservations en lecture seule avec action d'annulation déléguée à `CancellationService`) | **100 % opérationnel** |
| **Niveau 4 — Géolocalisation & UI** | Clients `NominatimClient` (rate limit 1 req/s, cache 30 j) et `OsrmClient` + `RoutePricingService` (`GET /api/route`, fallback Haversine hors-ligne), refonte UI **Midnight Black `#0B0B0C` / Pure White `#FFFFFF` / Lime `#C6F432`** avec bascule **mode sombre / mode clair** sans flash (Accueil, Résultats, Détail du trajet avec carte Leaflet & calculateur live Stimulus, Connexion & Inscription split-screen) | **Opérationnel** |
| **Niveau 5 — Livraison & Qualité** | Stack Docker auto-initialisée (`APP_SECRET` auto-généré, migrations + fixtures au premier boot, healthchecks), **45 tests PHPUnit (285 assertions)** verts, documentation technique complète | **100 % opérationnel** |

### Ce qui reste à faire (Transparence)
1. **Harmonisation visuelle du Groupe 2b** :
   - Les pages *Espace passager* (`/mes-reservations`), *Espace conducteur* (`/mes-trajets`, `/trajets/nouveau`, `/mes-vehicules`), *Profil public* (`/profil/{id}`) et *Back-office EasyAdmin* (`/admin`) sont **100 % fonctionnelles et testées** et héritent déjà de la palette sombre/claire globale (`assets/styles/app.css`), mais n'ont pas encore reçu la refonte graphique avancée du Groupe 2b (onglets animés, stepper de publication assisté par OSRM en temps réel, barres de distribution des notes et pages d'erreur 403/404/500 dédiées).
2. **Photographies IA des villes et véhicules (12 sur 13 restantes)** :
   - La photographie réaliste d'**Agadir** (`public/images/cities/agadir.webp` et `agadir-sm.webp`) a été générée et intégrée. En raison du quota du modèle d'image, les 9 autres villes et les 3 véhicules utilisent actuellement le **fallback dégradé sombre avec badge Lime** prévu par `templates/components/city_image.html.twig` et `car_image.html.twig` (aucune image cassée ni lien externe). Les prompts exacts sont prêts dans **[docs/IMAGE-PROMPTS.md](docs/IMAGE-PROMPTS.md)**.

---

## 3. Documentation technique (`docs/`)

- **[INSTALL.md](INSTALL.md)** : Guide d'installation pas à pas, comptes de démo, commandes et dépannage.
- **[docs/MODELE.md](docs/MODELE.md)** : Diagramme Entité-Association (`erDiagram`), formule mathématique de disponibilité par segments et index SQL.
- **[docs/DECISIONS.md](docs/DECISIONS.md)** : Journal des décisions d'architecture et réponses aux 5 questions métier du sujet.
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** : Architecture en couches, responsabilités des services métier et flux de données.
- **[docs/CONCURRENCE.md](docs/CONCURRENCE.md)** : Explication détaillée du verrouillage pessimiste (`SELECT ... FOR UPDATE`) contre la surréservation.
- **[docs/MATRICE-FONCTIONNELLE.md](docs/MATRICE-FONCTIONNELLE.md)** : Matrice de traçabilité complète (routes, services, voters et tests PHPUnit associés).
- **[docs/TESTS.md](docs/TESTS.md)** : Organisation de la suite de tests unitaires, d'intégration et fonctionnels.
- **[docs/IMAGE-PROMPTS.md](docs/IMAGE-PROMPTS.md)** & **[docs/CREDITS.md](docs/CREDITS.md)** : Prompts photographiques et crédits des ressources locales/open-source.
