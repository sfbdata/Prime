#!/bin/bash

echo "🔄 Limpando caches e reiniciando Symfony..."

docker exec -it jusprime_php_dev bash -c "
cd app && \
php bin/console cache:clear && \
php bin/console doctrine:cache:clear-metadata && \
php bin/console doctrine:cache:clear-query && \
php bin/console doctrine:cache:clear-result && \
composer dump-autoload \
exit
"

docker restart jusprime_php_dev

echo "✅ Processo concluído!"