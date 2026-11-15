#!/bin/sh
set -e

if [ -z "${APP_SECRET}" ]; then
    if [ -f .env.local ] && grep -q '^APP_SECRET=.\+' .env.local; then
        export APP_SECRET=$(grep '^APP_SECRET=' .env.local | tail -n 1 | cut -d= -f2-)
        echo "==> [0/4] APP_SECRET chargé depuis .env.local."
    else
        GENERATED_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
        echo "APP_SECRET=${GENERATED_SECRET}" >> .env.local
        export APP_SECRET="${GENERATED_SECRET}"
        echo "==> [0/4] APP_SECRET vide : secret aléatoire généré automatiquement dans .env.local."
    fi
fi

echo "==> [1/4] Installation des dépendances Composer & Importmap..."
composer install --no-interaction --prefer-dist
if [ ! -d "assets/vendor" ]; then
    php bin/console importmap:install --no-interaction || true
fi

echo "==> [2/4] Attente de la disponibilité de MySQL..."
php -r '
$host = getenv("MYSQL_HOST") ?: "database";
$port = getenv("MYSQL_PORT") ?: "3306";
$user = getenv("MYSQL_USER") ?: "app";
$pass = getenv("MYSQL_PASSWORD") ?: "app";
$maxTries = 30;
for ($i = 1; $i <= $maxTries; $i++) {
    try {
        new PDO("mysql:host={$host};port={$port}", $user, $pass);
        echo "MySQL est prêt !\n";
        exit(0);
    } catch (PDOException $e) {
        echo "En attente de MySQL ($i/$maxTries)...\n";
        sleep(2);
    }
}
fwrite(STDERR, "Erreur : MySQL injoignable après plusieurs tentatives.\n");
exit(1);
'

echo "==> [3/4] Exécution des migrations Doctrine..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

if [ "${APP_ENV:-dev}" = "dev" ]; then
    CITY_COUNT=$(php -r '
        $host = getenv("MYSQL_HOST") ?: "database";
        $port = getenv("MYSQL_PORT") ?: "3306";
        $db   = getenv("MYSQL_DATABASE") ?: "covoiturage";
        $user = getenv("MYSQL_USER") ?: "app";
        $pass = getenv("MYSQL_PASSWORD") ?: "app";
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass);
        echo (int) $pdo->query("SELECT COUNT(*) FROM city")->fetchColumn();
    ')
    if [ "$CITY_COUNT" = "0" ]; then
        echo "==> [4/4] Table city vide (APP_ENV=dev) : chargement automatique des fixtures..."
        php bin/console doctrine:fixtures:load --no-interaction
    else
        echo "==> [4/4] Données déjà présentes ($CITY_COUNT villes), fixtures ignorées."
    fi
fi

mkdir -p var/cache var/log
chown -R www-data:www-data var 2>/dev/null || chmod -R 777 var

exec docker-php-entrypoint "$@"
