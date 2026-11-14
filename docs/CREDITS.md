# Crédits & Licences des Visuels (`public/images/`)

Les visuels de **AtlasCovoit Maroc** sont stockés **localement** dans `public/images/` au format **WebP** (qualité `78`, `< 200 KB` par fichier) en double résolution (`1600×900` et `800×450` `-sm.webp` pour `srcset`). Aucun hotlink externe n'est utilisé.

## Origine & Prompts

- **Méthode de génération** : Générées avec le modèle d'image **Gemini** (`gemini-3.1-flash-image`), prompts complets dans [docs/IMAGE-PROMPTS.md](file:///c:/Users/OUALI/covoiturage-maroc/docs/IMAGE-PROMPTS.md).
- **Fallback dégradé sombre** : Pour les villes et véhicules dont la génération photoréaliste est en attente de réinitialisation du quota du modèle (`429 RESOURCE_EXHAUSTED`), les composants Twig ([city_image.html.twig](file:///c:/Users/OUALI/covoiturage-maroc/templates/components/city_image.html.twig) et [car_image.html.twig](file:///c:/Users/OUALI/covoiturage-maroc/templates/components/car_image.html.twig)) affichent automatiquement un dégradé sombre (`#0B0B0C` &rarr; `#1C1C1F`) avec le nom de la ville (aucune image cassée ni faux dessin procédural).

## Fichiers WebP photoréalistes présents

| Fichier | Dimensions | Poids | Description | Source |
| :--- | :--- | :--- | :--- | :--- |
| `public/images/cities/agadir.webp` | `1600×900` | `149,7 Ko` | Plage d'Agadir, promenade bordée de palmiers et colline d'Agadir Oufella à l'heure dorée | Modèle d'image Gemini (`gemini-3.1-flash-image`) |
| `public/images/cities/agadir-sm.webp` | `800×450` | `56,5 Ko` | Version `800w` pour `srcset` responsive | Modèle d'image Gemini (`gemini-3.1-flash-image`) |
