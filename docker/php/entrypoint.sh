#!/bin/sh
set -e

echo "==> [1/6] Vérification de APP_SECRET..."
if [ -z "${APP_SECRET}" ] || [ "${APP_SECRET}" = "dev_public_secret_covoiturage_maroc_2026" ]; then
    if [ -f .env.local ] && grep -q '^APP_SECRET=.\+' .env.local; then
        export APP_SECRET=$(grep '^APP_SECRET=' .env.local | tail -n 1 | cut -d= -f2-)
        echo "APP_SECRET chargé depuis .env.local."
    else
        GENERATED_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
        echo "APP_SECRET=${GENERATED_SECRET}" >> .env.local
        export APP_SECRET="${GENERATED_SECRET}"
        echo "APP_SECRET aléatoire généré automatiquement dans .env.local."
    fi
fi

if [ -d /usr/local/etc/php-fpm.d ]; then
    cat <<EOF > /usr/local/etc/php-fpm.d/zz-app-env.conf
[www]
env[APP_SECRET] = ${APP_SECRET}
EOF
fi

echo "==> [2/6] Installation des dépendances Composer..."
composer install --no-interaction --prefer-dist

echo "==> [3/6] Installation des assets frontend (Importmap & Bundles)..."
php bin/console importmap:install --no-interaction || true
php bin/console assets:install public --no-interaction || true

echo "==> [4/6] Attente de la disponibilité de MySQL..."
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

echo "==> [5/6] Exécution des migrations Doctrine (dev + base de test)..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

if [ "${APP_ENV:-dev}" = "dev" ]; then
    php -r '
        $host = getenv("MYSQL_HOST") ?: "database";
        $port = getenv("MYSQL_PORT") ?: "3306";
        $db   = getenv("MYSQL_DATABASE") ?: "covoiturage";
        $user = getenv("MYSQL_USER") ?: "app";
        $rootPass = getenv("MYSQL_ROOT_PASSWORD") ?: "root";
        try {
            $pdo = new PDO("mysql:host={$host};port={$port}", "root", $rootPass);
            $testDb = $db . "_test";
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$testDb}`.* TO '\''{$user}'\''@'\''%'\''");
            $pdo->exec("FLUSH PRIVILEGES");
        } catch (Throwable $e) {
            // Ignoré si root non disponible
        }
    '
    php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration || true

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
        echo "==> [6/6] Table city vide (APP_ENV=dev) : chargement automatique des fixtures..."
        php bin/console doctrine:fixtures:load --no-interaction
    else
        echo "==> [6/6] Données déjà présentes ($CITY_COUNT villes), fixtures ignorées."
    fi
fi

mkdir -p var/cache var/log
chown -R www-data:www-data var 2>/dev/null || chmod -R 777 var

exec docker-php-entrypoint "$@"
