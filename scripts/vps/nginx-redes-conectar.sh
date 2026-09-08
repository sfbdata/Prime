#!/bin/sh
# Conecta o nginx compartilhado às redes Docker dos sistemas co-hospedados na VPS.
#
# ── O PROBLEMA ────────────────────────────────────────────────────────────────
# `docker network connect` NÃO persiste quando o container do nginx é recriado.
# Cada sistema co-hospedado (Connect, Sign, Videoconferência, Condomínio) roda na
# sua própria rede e só recebe tráfego porque o nginx tem uma perna nela. Sem a
# reconexão, o vhost responde 502 — silenciosamente, sem erro no deploy.
#
# Até 08/09/2026 isso era comando manual para 3 dos 4 sistemas; o único caso
# automatizado (condomínio) estava hardcoded em dois lugares: no bloco de
# reconexão do deploy-prod-tls.sh e na unit condominio-nginx-reconnect.service.
#
# ── COMO DESCOBRE AS REDES (união de duas fontes, sem hardcode por produto) ────
#   1. LABEL `bluejus.nginx=conectar` na rede. É o caminho oficial para sistema
#      NOVO: o compose do próprio sistema declara o label e nada precisa mudar
#      aqui. Um sistema novo entra sozinho.
#   2. REGISTRO em $REGISTRO, uma rede por linha. Ponte para as redes LEGADAS,
#      criadas antes da convenção: pôr label em rede existente exige recriá-la,
#      o que derrubaria o sistema que está no ar. Conforme cada sistema legado
#      for naturalmente recriado com o label, sai deste arquivo.
#
# ── GARANTIAS ─────────────────────────────────────────────────────────────────
# Idempotente: conectar rede já conectada é no-op silencioso.
# Nunca desconecta nada — só adiciona. O pior caso é não fazer nada.
#
# Uso:
#   nginx-redes-conectar.sh            → uma passada e sai (deploy, execução manual)
#   nginx-redes-conectar.sh --daemon   → passada inicial + reage a eventos do Docker
set -u

NGINX=${NGINX_CONTAINER:-jusprime_nginx_prod}
REGISTRO=${REGISTRO_REDES:-/opt/bluejus-infra/redes-conectar.conf}
LABEL=${LABEL_REDE:-bluejus.nginx=conectar}

log() { echo "[nginx-redes $(date +%FT%T)] $*"; }

redes_por_label() {
	docker network ls --filter "label=$LABEL" --format '{{.Name}}' 2>/dev/null
}

# Uma rede por linha; `#` inicia comentário; espaços e linhas vazias ignorados.
redes_do_registro() {
	[ -f "$REGISTRO" ] || return 0
	sed 's/#.*//' "$REGISTRO" | tr -d ' \t' | grep -v '^$'
}

conectar_todas() {
	if ! docker inspect -f '{{.State.Running}}' "$NGINX" >/dev/null 2>&1; then
		log "container $NGINX ausente — nada a fazer nesta passada"
		return 0
	fi

	{ redes_por_label; redes_do_registro; } | sort -u | while read -r rede; do
		[ -n "$rede" ] || continue
		if ! docker network inspect "$rede" >/dev/null 2>&1; then
			# Sistema desligado ou ainda não publicado: esperado, não é erro.
			log "rede $rede não existe — ignorada"
			continue
		fi
		# Só loga quando REALMENTE conectou; "já conectado" sai por este mesmo if.
		if docker network connect "$rede" "$NGINX" 2>/dev/null; then
			log "conectado: $NGINX -> $rede"
		fi
	done
}

conectar_todas

[ "${1:-}" = "--daemon" ] || exit 0

log "modo daemon: escutando eventos do Docker"

# Dois gatilhos cobrem todos os casos de perda de conexão:
#   container:start do nginx  → o nginx foi recriado (deploy, reboot, troca de imagem)
#   network:create            → um sistema subiu/recriou a rede dele (compose down/up)
# Os filtros de tipo e de evento são OR entre si, então chega também `container:create`;
# ele é ignorado no case abaixo.
docker events \
	--filter 'type=container' --filter 'type=network' \
	--filter 'event=start' --filter 'event=create' \
	--format '{{.Type}}|{{.Action}}|{{.Actor.Attributes.name}}' |
	while IFS='|' read -r tipo acao nome; do
		case "$tipo:$acao" in
		container:start)
			[ "$nome" = "$NGINX" ] && {
				log "evento: $NGINX iniciou"
				conectar_todas
			}
			;;
		network:create)
			log "evento: rede $nome criada"
			conectar_todas
			;;
		esac
	done
