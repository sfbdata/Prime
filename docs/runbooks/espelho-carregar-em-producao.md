# Runbook — carregar o espelho da contabilidade em produção

**Somente leitura sobre dívida.** Nenhum comando aqui cria, altera ou apaga obrigação, caso ou
pagamento (INV-G3, com teste que fotografa o banco antes e depois). Eles escrevem **apenas** nas três
tabelas do espelho.

**Quem executa é o dono.** Nenhuma sessão do Claude alcança a VPS. O Claude monta os comandos; o dono
roda e cola a saída de volta.

---

## 🔴 ANTES DE QUALQUER COISA: `APP_DEBUG=0`

**Isto não é ajuste de performance. É proteção de dado pessoal.**

Com o debug ligado, o middleware do Doctrine imprime **os parâmetros de cada consulta** na saída do
terminal. E os parâmetros incluem o que veio do relatório de dados cadastrais:

```
INSERT INTO cobranca_relatorio_linha (...) VALUES (?, ...) (parameters: array{
  "4":"<NOME DO CONDÔMINO>",
  "18":"[\"<UNIDADE>\",...,\"000.000.000-00\",...,\"fulano@example.com\",\"+55 (61) 90000-0000\"]"})
```

⚠️ **O bloco acima é SINTÉTICO.** A primeira versão deste documento trazia o dump real — nome, CPF
válido, e-mail e telefone de um condômino —, colado do log de produção **dentro do documento que
existe para impedir isso**. Foi pego na quarta revisão, antes de qualquer publicação. Exemplo aqui usa
`000.000.000-00` e `example.com` (RFC 2606). *Documentar o vazamento não autoriza reproduzi-lo.*

**CPF, e-mail e telefone de condômino, por extenso, numa saída que é rotineiramente colada em chat.**

Aconteceu em 13/08/2026, numa medição no ambiente de desenvolvimento: o comando rodou com
`APP_ENV=prod` mas **sem** `APP_DEBUG=0`, e o `.env` do projeto traz `APP_DEBUG=1`. `--env=prod`
sozinho **não basta**.

Desde então **todo comando de cobrança que lida com dado pessoal recusa rodar** com o log ligado
(`GuardaDeLogComPii`, código de saída `69`) e imprime o comando certo. São **nove**: os quatro do
espelho, os quatro importadores (`importar`, `importar-acordos`, `importar-receitas`,
`importar-cadastro`) e a reconciliação. A régua não é uma lista nem uma heurística: **todo** comando de `src/Cobranca/Command/` declara
`LidaComDadoPessoal` ou `NaoLidaComDadoPessoal`, e quem não declarar nenhuma quebra a suíte
(`ComandosComPiiPassamPelaGuardaTest`). Comando novo não nasce fora da régua porque não nasce sem
decidir.

✅ **O wrapper `scripts/vps/bluejus-importar` já passa `APP_DEBUG=0`** (desde `00bf1e55`, 11/08, que é
o commit que o criou) — então ligar a guarda nos importadores **não quebra o canal de importação**.

A recusa é a rede; o hábito continua sendo passar `APP_DEBUG=0` sempre.

⚠️ Se algum dia você vir esse tipo de linha na saída: **pare, não cole em lugar nenhum, e apague o
que já colou.**

---

## Sequência

Os lotes ficam em `/opt/jusprime/lotes/<data>` no **host**; o container não os enxerga.

```bash
# ═══ 1. BACKUP — ANTES DO DEPLOY, e a ordem é o ponto ═══════════════════════════
#
# 🔴 O DEPLOY JÁ RODA A MIGRATION. `app/bin/entrypoint.prod.sh:34` executa
#    `doctrine:migrations:migrate` na subida do container, e `scripts/deploy-prod.sh:59`
#    roda de novo. Quem faz o backup depois do deploy está fotografando o schema JÁ
#    ALTERADO — backup que não serve para o que foi feito para servir.
#
# ⚠️ O nome vai numa VARIÁVEL: `docker cp` NÃO expande curinga (o caminho com prefixo
#    `container:` não passa pelo shell). Um `*` aqui falha justamente no passo mais
#    crítico, e backup que falha é pior que backup nenhum — cria a ilusão de que existe.
DUMP="pre-espelho-4relatorios-$(date +%Y%m%d-%H%M).dump"
mkdir -p /opt/jusprime/backups
docker exec jusprime_db_prod pg_dump -U jusprime -d prime -Fc -f "/tmp/$DUMP"
docker cp "jusprime_db_prod:/tmp/$DUMP" "/opt/jusprime/backups/$DUMP"
ls -la "/opt/jusprime/backups/$DUMP"   # CONFIRA o tamanho — 0 byte é falha
# `ls` prova existência, não restaurabilidade. `pg_restore -l` lê o índice do dump:
docker exec jusprime_db_prod pg_restore -l "/tmp/$DUMP" | head -5   # tem de listar objetos
docker exec jusprime_db_prod rm -f "/tmp/$DUMP"                     # não deixe cópia no container

# ═══ 2. DEPLOY (rebuild) — aplica a migration junto ════════════════════════════
#
# Prod é imagem baked: `git pull` + migrate num container antigo NÃO aplica nada, e os
# comandos abaixo estouram com TypeError no construtor (medido no dev: rc=255).
bash scripts/deploy-prod.sh
#
# 📌 Por que o passo 3 existe, sem exagero: `deploy-prod.sh:59` roda a migration
#    SEM `|| true`, sob `set -euo pipefail` — migration quebrada aborta o deploy
#    ruidosamente. E o DDL do Postgres é transacional, então falha no entrypoint
#    (`entrypoint.prod.sh:34`, esse sim com `|| true`) não deixa a versão marcada, e
#    a segunda passada refaz. O caminho em que uma falha silenciosa sobrevive é
#    ESTREITO: o deploy abortar no healthcheck do php (linha 52) antes de chegar na
#    59, ou o container ser subido fora do `deploy-prod.sh`. O passo 3 é barato e
#    cobre justamente esse caminho.

# ═══ 3. CONFERIR que a migration entrou ════════════════════════════════════════
#
# `migrations:status` NÃO serve aqui: ele imprime contadores agregados e as linhas
# Previous/Current/Next/Latest, sem listar versão por versão. `migrations:list` traz
# UMA LINHA POR VERSÃO, com o estado e o timestamp.
docker exec -w /var/www/app -e APP_DEBUG=0 jusprime_php_prod \
  php bin/console doctrine:migrations:list --env=prod --no-ansi | grep 20260813210000
#
# COMO LER: a linha tem de existir, e a SEGUNDA COLUNA tem de ser `migrated`.
# ⚠️ Leia a coluna, não procure a substring: `not migrated` CONTÉM "migrated".
#    Um `grep migrated` daria verde sobre a migration que não rodou.
# 📌 No dev a mesma linha pode sair como `migrated, not available` — é o arquivo da
#    migration não estar naquele checkout. Em prod, depois do deploy, o arquivo está
#    lá e o sufixo não aparece. Texto diferente, mesmo estado.
#
# Se a linha não existir, ou a coluna não for `migrated`: PARE. Rode a migration à
# mão e só então siga.

# ═══ 4. levar o lote para dentro do container ══════════════════════════════════
docker exec jusprime_php_prod mkdir -p /tmp/espelho
docker cp /opt/jusprime/lotes/<data> jusprime_php_prod:/tmp/espelho/<data>
# `docker cp` de DIRETÓRIO leva permissão restrita junto — sem isto o PHP não lê os arquivos
docker exec -u 0 jusprime_php_prod chmod -R a+rX /tmp/espelho

# ═══ 5. carregar os QUATRO relatórios das três carteiras ═══════════════════════
docker exec -w /var/www/app -e APP_DEBUG=0 jusprime_php_prod \
  php -d memory_limit=512M bin/console app:cobranca:espelho:carregar \
  --tenant-id=1 --diretorio=/tmp/espelho/<data> --env=prod

# ═══ 6. os três instrumentos, cada um declarando a própria cobertura ═══════════
docker exec -w /var/www/app -e APP_DEBUG=0 jusprime_php_prod \
  php -d memory_limit=512M bin/console app:cobranca:espelho:conferir --tenant-id=1 --env=prod

docker exec -w /var/www/app -e APP_DEBUG=0 jusprime_php_prod \
  php -d memory_limit=512M bin/console app:cobranca:espelho:calibrar --tenant-id=1 --env=prod

docker exec -w /var/www/app -e APP_DEBUG=0 jusprime_php_prod \
  php -d memory_limit=512M bin/console app:cobranca:espelho:encargos --tenant-id=1 --env=prod
```

## `-d memory_limit=512M` — o número é medido, não chutado

Carga dos **15 arquivos reais** (4 tipos × 3 carteiras, os acordos em 2 arquivos cada) contra o banco
de desenvolvimento, `--env=prod`: **512M bastou**, 49 s, saída 0, 12 lotes gravados.

⚠️ **O que essa medição NÃO cobre:** reexecuções gastam menos porque a idempotência corta antes de
gravar — elas passaram com 256M. Esse número **não vale** para a primeira carga. Use 512M.

O volume cresceu muito nesta fatia: antes o maior relatório tinha 4.207 linhas (inadimplência da
TL1); agora o maior tem **21.333** (receitas da TL1), e os de acordo somam ~26 mil linhas por
carteira. O `flush()` a cada 500 **não desanexa** as entidades — elas ficam na UnitOfWork até o fim
da transação.

## O que esperar na saída

**Os três instrumentos vão sair com AVISO, não com caixa verde.** Isso é o desenho, não falha: eles
leem 1 dos 3 relatórios com dinheiro, e o aviso lista o que falta cobrir.

> 🎯 **A caixa verde é a barra de progresso da meta.** O dia em que ela voltar é o dia em que o
> sistema está batendo com a contabilidade de verdade. Até lá, aviso é a resposta honesta.

Também sai, quando houver, a linha da **tolerância de rateio** dos acordos:

```
top_life_1_Acordos_detalhados_LIQUIDADO.xlsx: 9 aba(s) fecharam só dentro da tolerância
de rateio, consumindo R$ 0,18.
```

Medido nas três carteiras: **11 abas, R$ 0,27** no total. Número muito maior que isso merece
investigação antes de seguir.

## Códigos de saída

| código | significa |
|---:|---|
| `0` | ok |
| `1` | **recusa controlada OU exceção não capturada** — os dois saem `1`, então leia a tela |
| `67` | `espelho:encargos`: **cobertura incompleta** — é o esperado hoje, não é falha |
| `69` | 🔴 **recusado: o log de SQL está ligado** e despejaria PII |

📌 **O passo 6 vai devolver `67` no `espelho:encargos`, e isso é o esperado.** Ele significa "rodei,
não achei nada divergente, e não conferi tudo" — a mesma verdade que a caixa de aviso diz em texto.
Não é falha e não bloqueia nada.

⚠️ **`1` é ambíguo de propósito neste comando**: é o `Command::FAILURE` de toda recusa limpa (tenant
inexistente, `--tipo` junto de `--diretorio`, arquivo não reconhecido, recorte errado) **e** o que o
Symfony devolve numa exceção não capturada. Ver `1` e procurar stack trace onde houve recusa
explicada é perda de tempo — a mensagem na tela é que distingue.

⚠️ Os comandos irmãos (`espelho:encargos`, `reconciliar-dupla-contagem`) têm **outros** significados
para `65`–`68`. Um wrapper não pode tratar código sem saber qual comando rodou.

## Se algo der errado

🔴 **Falha no deploy deixa o site FORA DO AR, e isso é de propósito.**
`scripts/deploy-prod.sh:26` liga `nginx/maintenance/maintenance.on` (o nginx passa a servir 503 com
página amigável) e só a **linha 63** desliga. Qualquer aborto no meio — espera do banco (39–41),
healthcheck do php (52–55) ou a própria migration (59) — sai antes da 63 e **a manutenção fica
ligada**. O comentário da linha 23 diz que é intencional: melhor 503 do que meio deploy no ar.

Para sair da manutenção depois de resolver a causa:

```bash
rm -f nginx/maintenance/maintenance.on
```

⚠️ **Resolva a causa primeiro.** Apagar a flag com o php quebrado troca a página de manutenção por
erro 500.

🔑 **Nem toda recusa acontece no mesmo momento — e isso muda o que sobrou gravado:**

| recusa | quando | o que ficou no banco |
|---|---|---|
| nome não reconhecido | **antes** de gravar qualquer arquivo | **nada** |
| recorte errado, portão do layout | **durante**, arquivo a arquivo | os arquivos ANTERIORES já entraram |

- **Recusa com "planilha não reconhecida"** — o lote tem um `.xlsx` cujo nome não identifica nenhum
  dos quatro relatórios. **Nada foi carregado, de propósito**: lote pela metade é espelho incompleto
  com cara de completo. Corrija o nome e rode de novo.
- ⚠️ **Recusa no meio do lote deixa o espelho parcial.** A mensagem do comando diz "Nada foi gravado
  *para eles*" — para os recusados. Os que passaram antes estão gravados. Corrija a causa e rode de
  novo: a carga é idempotente, os já gravados saem como "já estava".
- **Recusa com "não fecham entre a soma das parcelas e o Valor final acordado"** — o arquivo de
  acordos não fecha consigo mesmo além do arredondamento de rateio. É achado para levar à
  contabilidade, não para contornar.
- **Recusa de recorte** — o relatório foi emitido com filtro (uma unidade, um período). Reemita sem
  filtro; espelho envenenado é pior que espelho vazio.
