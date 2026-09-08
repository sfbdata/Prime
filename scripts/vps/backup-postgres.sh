#!/bin/sh
# Backup padronizado de qualquer Postgres em container nesta VPS.
#
# Uso:  backup-postgres.sh <container> <nome> [retencao_dias]
#   ex: backup-postgres.sh bluejus_sign_db bluejus-sign 14
#
# ── POR QUE ASSIM ─────────────────────────────────────────────────────────────
# Cada sistema co-hospedado resolvia (ou não) o backup do seu jeito: o Condomínio
# tem um sidecar dedicado, o Connect tem um script em TypeScript que não roda na
# imagem de produção (falta `tsx`, e `dist/` não inclui `scripts/`), o Sign e a
# Videoconferência não tinham nada. Este script é o mecanismo único.
#
# ── DECISÕES ──────────────────────────────────────────────────────────────────
# * Credenciais NUNCA aparecem em linha de comando, arquivo ou log: o `pg_dump`
#   roda DENTRO do container, lendo POSTGRES_USER/POSTGRES_DB do próprio ambiente
#   e conectando pelo socket local. Nada trafega, nada é digitado.
# * Dump e verificação acontecem DENTRO do container, onde estão as ferramentas
#   na versão certa do servidor. A VPS não tem cliente Postgres instalado, e
#   `pg_restore --list` lendo de stdin não funciona com formato custom (o índice
#   fica no fim do arquivo e exige seek) — por isso o arquivo é gerado em /tmp do
#   container, verificado lá, e só então copiado para o host.
# * Destino fora do volume do Postgres (/opt/backups/<nome>): perder o volume não
#   pode significar perder o backup junto.
# * Formato custom (-Fc): comprimido e restaurável seletivamente com pg_restore.
# * `pg_restore --list` lê o índice do dump e falha se estiver truncado ou
#   corrompido. NÃO restaura nada — é a checagem mais forte possível sem tocar
#   em banco nenhum.
# * Dump vazio ou ilegível é DESCARTADO com erro, para não criar a ilusão de
#   backup e ainda empurrar o bom para fora pela retenção.
set -u

CONTAINER=${1:?uso: backup-postgres.sh <container> <nome> [retencao_dias]}
NOME=${2:?uso: backup-postgres.sh <container> <nome> [retencao_dias]}
RETENCAO=${3:-14}

DESTINO=/opt/backups/$NOME
CARIMBO=$(date +%Y%m%d-%H%M%S)
ARQUIVO=$DESTINO/$NOME-$CARIMBO.dump
NO_CONTAINER=/tmp/backup-corrente.dump
MINIMO_BYTES=1000

log() { echo "[backup-$NOME $(date +%FT%T)] $*"; }

limpar_container() { docker exec "$CONTAINER" rm -f "$NO_CONTAINER" 2>/dev/null; }

if ! docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
	log "ERRO: container $CONTAINER não está rodando — backup não realizado"
	exit 1
fi

mkdir -p "$DESTINO"

# `sh -c` com aspas simples: a expansão acontece DENTRO do container.
if ! docker exec "$CONTAINER" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f '"$NO_CONTAINER" 2>/tmp/backup-$NOME.err; then
	log "ERRO: pg_dump falhou: $(head -c 300 /tmp/backup-$NOME.err)"
	limpar_container
	exit 1
fi

if ! docker exec "$CONTAINER" pg_restore --list "$NO_CONTAINER" >/dev/null 2>&1; then
	log "ERRO: dump ilegível por pg_restore --list — descartado"
	limpar_container
	exit 1
fi

if ! docker cp "$CONTAINER:$NO_CONTAINER" "$ARQUIVO" 2>/dev/null; then
	log "ERRO: falha ao copiar o dump do container para $ARQUIVO"
	limpar_container
	exit 1
fi
limpar_container

BYTES=$(stat -c %s "$ARQUIVO" 2>/dev/null || echo 0)
if [ "$BYTES" -lt "$MINIMO_BYTES" ]; then
	log "ERRO: dump com $BYTES bytes (mínimo $MINIMO_BYTES) — descartado"
	rm -f "$ARQUIVO"
	exit 1
fi

log "OK: $ARQUIVO ($BYTES bytes, verificado)"

# Retenção: só remove DEPOIS de o novo dump ter passado em todas as checagens.
REMOVIDOS=$(find "$DESTINO" -name "$NOME-*.dump" -type f -mtime +"$RETENCAO" -print -delete | wc -l)
[ "$REMOVIDOS" -gt 0 ] && log "retenção: $REMOVIDOS arquivo(s) com mais de $RETENCAO dias removido(s)"

exit 0
