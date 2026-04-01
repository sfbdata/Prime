#!/bin/sh
set -e

echo "🚀 Iniciando container Symfony..."

cd /var/www/app

echo "📁 Garantindo estrutura de diretórios..."
mkdir -p var/cache var/log public/uploads

echo "🔐 Ajustando permissões..."
chmod -R 775 var public/uploads
chown -R www-data:www-data var public/uploads

echo "🧹 Limpando cache..."
php bin/console cache:clear --env=prod --no-debug || true

echo "🔥 Recriando cache..."
php bin/console cache:warmup --env=prod --no-debug

echo "📦 Copiando assets estáticos..."
cp -rf public/. /var/www/app/public/

echo "🗄️ Rodando migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

echo "✅ Symfony pronto! Iniciando PHP-FPM..."

exec "$@"