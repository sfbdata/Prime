#!/bin/bash

set -e  # Exit on error

echo "⚠️  ATENÇÃO: Este script vai dropar e recriar o banco de dados!"
echo "🗑️  Todos os dados serão perdidos. Deseja continuar? (s/n)"
read -r confirm

if [[ "$confirm" != "s" && "$confirm" != "S" ]]; then
    echo "❌ Operação cancelada."
    exit 0
fi

echo ""
echo "🗑️  Dropando banco de dados..."
docker exec -it jusprime_php_dev bash -c "
cd /var/www/app && \
php bin/console doctrine:database:drop --force --if-exists
"

echo "✅ Banco dropado!"

echo ""
echo "🗄️  Criando novo banco de dados..."
docker exec -it jusprime_php_dev bash -c "
cd /var/www/app && \
php bin/console doctrine:database:create
"

echo "✅ Banco criado!"

echo ""
echo "🔄 Executando migrations..."
docker exec -it jusprime_php_dev bash -c "
cd /var/www/app && \
php bin/console doctrine:migrations:migrate --no-interaction
"

echo "✅ Migrations executadas!"

echo ""
echo "📦 Carregando fixtures..."
docker exec -it jusprime_php_dev bash -c "
cd /var/www/app && \
php bin/console doctrine:fixtures:load --purge --no-interaction
"

echo "✅ Fixtures carregadas!"

echo ""
echo "✨ Banco de dados resetado com sucesso!"
echo ""
echo "📊 Dados loaded:"
echo "   - Tenant: 'Escritório Almeida & Associados'"
echo "   - Users: 6 (admin + 5 staff)"
echo "   - Clientes PF: 5"
echo "   - Clientes PJ: 3"
echo "   - Pré-cadastros: 5"
echo "   - Processos: 3 (com partes, movimentações e documentos)"
echo "   - Tarefas: 5"
echo "   - Chamados: 5"
echo "   - Eventos: 6"
