#!/bin/sh
# Entrypoint do container `voto` (BlueJus Voto, spec docs/specs/bluejus-voto.md, D1-B e fase A2).
#
# Mesma imagem do php, com duas diferenças de propósito:
#  - NÃO roda migrations: quem roda é o php (o voto só sobe depois dele saudável), e o usuário do banco do Voto
#    (voto_app) nem tem permissão para mudar o esquema;
#  - NÃO copia assets para o volume estático: é o php que copia, e o voto nem monta esse volume.
#
# A imagem já roda como www-data (Dockerfile), então o cache nasce com o dono certo.
set -e

# O compose passa vazias DATABASE_URL e APP_SECRET quando faltam VOTO_DATABASE_URL e VOTO_APP_SECRET no .env.prod
# (não dá para exigir lá sem derrubar o deploy do BlueJus). Sem elas o Voto não sobe: nunca entra no banco com outro
# usuário nem roda sem segredo próprio.
if [ -z "${DATABASE_URL:-}" ]; then
    echo "Voto: falta VOTO_DATABASE_URL no .env.prod (docs/runbooks/voto-subir-na-vps.md). O container não sobe." >&2
    exit 1
fi
if [ -z "${APP_SECRET:-}" ]; then
    echo "Voto: falta VOTO_APP_SECRET no .env.prod (docs/runbooks/voto-subir-na-vps.md). O container não sobe." >&2
    exit 1
fi

cd /var/www/app

echo "Voto: aquecendo o cache..."
php -d memory_limit=512M bin/console cache:warmup --env=prod --no-debug

echo "Voto: iniciando $*"
exec "$@"
