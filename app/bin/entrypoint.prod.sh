#!/bin/sh
set -e

echo "🚀 Iniciando container Symfony..."

cd /var/www/app

echo "📁 Garantindo estrutura de diretórios..."
mkdir -p var/cache var/log public/uploads

echo "🔐 Ajustando permissões..."
chmod -R 775 var
chown -R www-data:www-data var
# uploads é volume grande e persistido (arquivos já são www-data, criados pelo app): garante só o
# dir-raiz gravável, SEM -R. O chown/chmod recursivo sobre ~11k+ arquivos a cada boot era um dos
# motivos da lentidão do deploy — e é redundante (nada cria upload com outro dono).
chmod 775 public/uploads
chown www-data:www-data public/uploads

echo "🧹 Limpando cache..."
php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug || true

echo "🔥 Recriando cache..."
php -d memory_limit=512M bin/console cache:warmup --env=prod --no-debug

echo "📦 Copiando assets estáticos (SEM uploads)..."
# Copia só os assets buildados (build/bundles/css/js/index.php), NUNCA public/uploads: o nginx serve
# uploads do volume uploads_prod (montado por cima de public/uploads) e o bloqueio /uploads/ do C5.1
# ainda roteia tudo ao PHP — então copiar uploads p/ o static era trabalho 100% inútil (centenas de
# MB a cada deploy). Esse era o principal gargalo da lentidão do deploy.
find public -mindepth 1 -maxdepth 1 ! -name uploads -exec cp -rf {} /var/www/static/ \;

echo "🗄️ Rodando migrations..."
php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

echo "✅ Symfony pronto! Iniciando PHP-FPM..."

exec "$@"