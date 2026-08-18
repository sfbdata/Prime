# Frentes ativas

Registro das frentes de trabalho abertas em paralelo. **É o que permite duas sessões saberem uma da
outra** — sem ele não há coordenação possível, só descoberta tardia no merge.

Quem abre uma frente acrescenta a linha. Quem integra tira.

| Frente (branch) | Domínio | Migration? | Arquivos compartilhados que toca | Estágio | Base |
|---|---|---|---|---|---|
| `cobranca-acompanhamento-canonico` | Cobrança (modelo objeto/caso) | **sim — 4** | `docs/gestao-cobrancas/` | 🛑 **PARADA** (ver abaixo) | `origin/master` @ `0bb1f29` |
| `expediente-ux` | Expediente + Pasta (telas) | não | `app/templates/expediente/`, `app/templates/pasta/` | implementando, **28 commits atrás do master** | `origin/codex/colaboracao-cobrancas` |

`pasta-valor-causa` foi **integrada em 2026-08-17** (fast-forward para `3629b19a`). Trouxe a migration
`Version20260814120000` — uma coluna `valor_causa` em `pasta`, aditiva e anulável, que não toca nenhuma
tabela existente. O segundo passo que todo mundo pula foi feito: master trazido para dentro da frente
(1 arquivo em comum, `PastaController.php`, sem conflito) e **suíte rodada de novo no master depois do
merge** — 3828/3828.

🔴 **Se a sua frente tem banco de teste próprio, ele NÃO tem a coluna `valor_causa`.** Os bancos das
frentes são clones do `saas_test` feitos *antes* desta migration; ao trazer o master para dentro da sua
frente, a suíte vai explodir com centenas de `column "valor_causa" of relation "pasta" does not exist`.
**Não é quebra de código** — aconteceu exatamente isso no master em 17/08. O conserto é uma linha:

```bash
docker exec -e TEST_TOKEN=<sua-frente> jusprime_php_dev bash -c \
  'cd /var/www/.claude/worktrees/<sua-frente>/app && php bin/console doctrine:migrations:execute \
   --up "DoctrineMigrations\Version20260814120000" --env=test --no-interaction'
```

Use `migrations:execute` (uma versão), **não** `migrations:migrate`: o ledger dos bancos clonados só
tem as migrations recentes, então o `migrate` tenta rodar as antigas e morre em `relation "tenant"
already exists`.

⚠️ `expediente-ux` estava **fora deste registro** e só apareceu num `git worktree list` de 14/08 — o
mesmo defeito que a `cobranca-acompanhamento-canonico` já tinha cometido. Ela tem 2 commits e 4
arquivos alterados sem commit (`expediente/index.html.twig`, `expediente/_acervo_geral.html.twig`,
`pasta/_filtros.html.twig` e o teste do filtro). Sem migration. **Está 28 commits atrás do master** —
quem a retomar traz o master para dentro antes de escrever qualquer linha.

⚠️ **Worktree não herda `app/.env.local`.** O `app/.env` versionado aponta para o banco `saas`, onde
`cobranca_relatorio_importado` **não existe** — o dev de verdade usa `saas_ux`. Qualquer conferência
rodada de dentro de uma worktree sem sobrescrever `DATABASE_URL` falha, ou pior, mede o banco errado.
Para os testes isso não vale: `scripts/frente-testar.sh` usa o banco clonado da frente.

⚠️ A regra da casa manda **uma frente com migration por vez**. Hoje **nenhuma frente ativa tem
migration pendente** — a `cobranca-acompanhamento-canonico` tem 4, mas está PARADA. Quem revivê-la
precisa ler o bloco dela antes de gerar versão.

`cobranca-data-acordo-espelho` foi **integrada em 2026-08-18** (fast-forward para `3605188f`).
Trouxe a migration `Version20260817180000` — `cobranca_acordo.data_acordo` passa a aceitar **nulo**,
porque se a contabilidade tem acordo sem data o espelho grava sem data. Matou o `dataAcordoPadrao()`
dos dois importadores que derivavam a data do 1º dia da competência (violação #3: **375 de 395
acordos com data chutada**, R$ 203.265,07 de encargo calculado sobre ela, 256 dívidas zeradas por
data impossível), o `now()` do construtor de `Acordo` (#7) e a ausência de reescrita no ramo de
atualização (#6).

**Quatro revisões adversariais**, cada uma achando o que a anterior não podia ver: tela exibindo
resíduo, correção declarada como provada sem estar (duas vezes), catch inalcançável, badge
prometendo o que não cumpre, e uma **prévia que podia gravar** (`prever()` sem transação). Suíte
**3853/3853** na frente com o master dentro **e** de novo no master depois do merge. Smoke do dono
feito e aprovado em 18/08.

⚠️ **`scripts/frente-abrir.sh` aborta no meio e mente o sucesso.** O `composer install` sai com erro
(o `cache:clear` do post-install estoura os 128M) e o `set -e` mata o script **antes** de criar os
dirs de upload e de clonar o banco. **Não canalize a saída do script por `tail`/`head`** — o código
de saída lido vira o do `tail`. Confira `saas_test<nome>` no fim. Conserto do script: fatia própria.

🔴 **A worktree NÃO herda `app/.env.local`.** Rodar `doctrine:migrations:execute` de dentro dela
aplica no banco `saas` (parado no tempo), não no `saas_ux` que a aplicação usa. Aconteceu em 18/08,
com o aviso já escrito neste arquivo. Passe `DATABASE_URL` explicitamente.

🔴 **O nginx do dev serve só `app/` — a worktree não é publicada.** Não dá para fazer smoke do código
de uma frente sem antes integrá-la no master local. Preparar banco novo com código velho quebra a
tela (`Typed property $dataAcordo must not be accessed before initialization`).

`deploy-resiliente` foi **integrada e publicada em 2026-08-18** (fast-forward para `1a8271dc`).
Sem migration. O segundo passo que todo mundo pula foi feito: master trazido para dentro da frente
(1 arquivo em comum, `docs/frentes-ativas.md`, conflito mecânico) e **suíte rodada de novo no master
depois do merge** — 3853/3853 nos dois pontos.

🔴 **A causa de todo deploy rebaixar as 122 dependências era o próprio deploy.** O
`docker builder prune -f` do fim do `deploy-prod-tls.sh` zerava 100% do cache de build — medido,
1,719 GB → 0 B, e ter a imagem no store não protege. Agora a poda tem teto. Medição de dois deploys
consecutivos sem mudança de código: **209,9 s → 1,26 s**, 122 → **0** pacotes baixados. E o build
deixou de depender do GitHub: `composer install` completa com `--network=none`.

🔑 **O conserto que impede o site de cair é a ORDEM:** o build passa a vir **antes** do modo
manutenção. Build que falha não derruba mais nada — foi isso que custou ~40 min em 17/08. A
credencial também passou a ser validada por **forma** (o `grep "github-oauth"` antigo deixava passar
o texto de exemplo do modelo, que foi o que derrubou a produção). Prova:
`bash scripts/testar-deploy-guardas.sh` — 42 asserções, cobre os **dois** scripts.

✅ **As duas medições que faltavam foram feitas NA VPS em 18/08, e as duas deram a favor:**
`docker builder prune --help | grep -c max-used-space` → **1** (a poda usa o teto de verdade, não o
fallback por idade) e `command -v python3` → **`/usr/bin/python3`** (a validação da credencial roda
completa, com parser JSON, em vez de avisar e seguir).

🔴 **Medido na VPS em 18/08: o `.composer-auth.json` de lá é `{}` — NUNCA houve token em produção.**
O commit `37399179` montou o encanamento inteiro (secret do BuildKit, compose, Dockerfile) e a
credencial nunca foi instalada. Todos os builds desde então, inclusive o que funcionou em 18/08,
rodaram **anônimos** — ou seja, o teto de 5.000/hora que aquele commit foi buscar nunca valeu. O
deploy de 18/08 passou porque o GitHub se recuperou, não porque o token foi corrigido.

Isso explica a sequência de 17/08 sem mistério: placeholder → `401` → o arquivo foi esvaziado para
`{}` → anônimo → funcionou quando o GitHub voltou. **É por isso que a validação nova trata ausência
como AVISO e não como erro** (anônimo funciona), mas o aviso agora aparece.

🔴 **O DEPLOY AINDA NÃO FOI FEITO — e o primeiro vai ser LENTO, de propósito.** O cache da VPS foi
zerado pelo `prune` cego do último deploy antigo, então o primeiro build com o script novo ainda
começa frio: ~4 min e os 122 pacotes baixados do GitHub. É o último que faz isso. **O ganho aparece
do segundo deploy em diante** (`CACHED`, poucos segundos), e a proteção contra pane do GitHub também
só vale a partir daí, porque ela depende do cache já estar quente. Quem esperar deploy rápido na
primeira vez vai achar que a mudança não funcionou.

### ✅ `pasta-cliente-principal` — DESTRAVADA, projetada e ainda não aberta

Marcar explicitamente qual cliente é o principal da pasta, para a **"Média por CPF"** da aba
Financeiro parar de trocar de número quando alguém vincula um cliente mais antigo no cadastro.

Estava esperando a vaga de migration. **Com a `cobranca-data-acordo-espelho` integrada em 18/08, a
vaga abriu** — esta fatia pode ser aberta a qualquer momento. Nenhuma linha foi escrita ainda.

| | |
|---|---|
| **Spec** | [specs/pasta-cliente-principal.md](specs/pasta-cliente-principal.md) — desenho, migration com backfill, testes e a colisão prevista |
| **Padrão a seguir** | `PastaProcesso` — o domínio `Pasta` já tem "marcar como principal" |
| **Migration** | **sim — 1** (promove `pasta_cliente`, hoje ManyToMany pura, a entidade de vínculo) |

🔴 **Três achados que fariam a fatia nascer morta** (verificados no código, estão na spec):
`PastaController::syncClientes()` remove todos os clientes e re-adiciona a cada edição de pasta —
zeraria a marcação; `PastaType` liga `clientes` como campo **mapeado**, e coleção derivada não é
gravável pelo form; e ManyToMany + OneToMany na mesma tabela duplica chave no flush.

### 🛑 `cobranca-acompanhamento-canonico` — parada, NÃO apagar

Registrada de volta em **2026-08-13** por decisão do dono. Ela estava fora deste arquivo desde que a
sessão que a abriu terminou, e virou o que este registro existe para impedir: **um piloto invisível no
mesmo domínio** — 23 commits de trabalho real que nenhuma outra sessão de cobrança enxergava.

| | |
|---|---|
| **Worktree** | `.claude/worktrees/cobranca-acompanhamento-canonico` |
| **HEAD** | `8ac4d1b6` · árvore limpa · **nada publicado** |
| **Posição** | 23 commits à frente da base, **286 atrás** de `origin/master` de hoje |
| **Suíte** | 2550/2550 na época (`scripts/frente-testar.sh`, banco `saas_testcobranca-acompanhamento-canonico`) |
| **Entregou** | fatias **A0, A1, A1b, A2, A3** + **etapa 1 de 3 da A4** — 5 de 26 |
| **Falta** | A4 etapas 2–3 (partials Twig, CSRF por id, upload — o ponto mais arriscado), A5, B1, B2, C1, C2 até o portão E0 |
| **Retomada** | [gestao-cobrancas/RETOMADA_CANONICA.md](gestao-cobrancas/RETOMADA_CANONICA.md) — leia a entrada "A4 etapa 1/3" do diário antes de escrever uma linha; ela contradiz o PLAN em pontos onde a execução provou o PLAN errado |

⚠️ **Ela tem 4 migrations** (`Version20260725120000` … `20260725180000`). Medido em 13/08: elas estão
aplicadas em **`saas`**, e o banco que o dev de fato usa é **`saas_ux`** (`app/.env.local`), onde há
**zero**. Frente com migration vai uma de cada vez — enquanto esta estiver parada mas viva, qualquer
outra frente de cobrança com migration precisa saber disso antes de gerar a sua.

📌 *Uma versão anterior desta linha dizia "aplicadas no banco de dev", sem dizer qual — e estava
errada. Este arquivo é o que informa outras sessões se elas podem criar migration: informação errada
aqui faz outra pessoa decidir errado sem nunca saber por quê.*

⚠️ **Não apagar a branch** (decisão do dono, 13/08): são 23 commits de trabalho real. Ele decide
depois se revive ou descarta. Até lá ela fica aqui, parada e visível.

`cobranca-espelho-quatro-relatorios` foi **integrada em 2026-08-14** (fast-forward para `0194be63`).
Ela trouxe a migration `Version20260813210000` (5 colunas em `cobranca_relatorio_linha`, 1 em
`cobranca_relatorio_totalizador`, e o `tipo` entrando no índice único `uniq_cobranca_relatorio_arquivo`
— nenhuma toca tabela de dívida). O segundo passo que todo mundo pula foi feito: master trazido para
dentro da frente (limpo, zero conflitos) e **suíte rodada de novo no master depois do merge** —
3771/3771, 14.239 asserções. A carga em produção segue
[runbooks/espelho-carregar-em-producao.md](runbooks/espelho-carregar-em-producao.md).

`sync-sistema-manda` foi **integrada, publicada e deployada** antes disso (`d55ebf16`). ✅ **Fechada
ponta a ponta em 2026-08-18:** cron religado (`0 * * * *`, wrapper da VPS já com `--modo=enviar`),
worker `jusprime_worker_prod` de pé consumindo `async` sem restart-loop, e **smoke da tela feito e
aprovado**. O R2 está provado em produção pelo próprio log: 95 rodadas marcadas `[modo: enviar]` e a
última com `Pastas criadas no sistema: 0` / `Arquivos baixados do Drive: 0`.

⚠️ **Não leia `Divergências de nome: 0` desse log como "o Drive está alinhado".** Sob `--modo=enviar`
o contador vive dentro de `driveParaSistema()`, que não roda — o zero é ausência de medição, não
ausência de divergência (mesma armadilha do §12.4 da spec). Medir exigiria `--modo=ambos --dry-run`;
pelo D12.7 o acervo legado não se alinha mesmo, então provavelmente nunca será preciso.

`cobranca-dupla-contagem` foi **integrada, publicada e deployada em 2026-08-13** (`99948524`), e a
reconciliação **rodou em produção**: 25 dívidas corrigidas, R$ 1.429,55 fora do saldo do devedor, e a
régua confirma **zero** dívidas com assinatura de dupla contagem nas três carteiras. Conferido por
`git cherry master cobranca-dupla-contagem` (vazio) antes de tirar daqui — a worktree pode ser fechada.

`cobranca-importar-cadastro-acordos` foi **removida deste registro em 2026-08-13**: conferido por
`git merge-base --is-ancestor`, ela já está inteiramente em `origin/master`. A linha tinha ficado para
trás — *quem integra tira*, e não tirou. Registro desatualizado é pior que registro nenhum: ele fez
esta sessão considerar um conflito de domínio que não existia mais.

`ponto-horas-pagas` foi **integrada e publicada** em 2026-07-31 (merge `8b6ce5fd`), com migration
própria (`ponto_lancamento_horas_pagas`) — por isso a frente de cobrança, que também tem migration,
esperou a vez, como manda a regra abaixo.

`feature/cobranca-ux-rapida` foi **integrada e publicada** em 2026-07-26 (merge `bbc1724`, sem
migration), com a suíte em 2612/2612 no master **depois** do merge. O que ela entregou e a decisão de
rótulo que ficou em aberto: [gestao-cobrancas/HANDOFF_UX_RAPIDA.md](gestao-cobrancas/HANDOFF_UX_RAPIDA.md).

**Estágios:** `implementando` · `em revisão` · `pronta para integrar` · `travada` (diga em quê).

## Como preencher

- **Domínio** — um por frente. Duas frentes no mesmo domínio conflitam quase sempre.
- **Migration?** — sim/não. **Frentes com migration vão uma de cada vez.** Não por impossibilidade:
  o custo de fazer certo (renomear a versão, recriar o banco do zero, ler as duas migrations, rodar
  de novo) supera o de esperar a outra entrar. Frentes de tela, relatório ou lógica paralelizam à
  vontade.
- **Arquivos compartilhados** — neste projeto os que doem: `app/templates/base.html.twig`, CSS
  global, rotas, enums de `app/src/Shared/`, `docs/`. Quem toca um desses vai sozinho ou por último.
- **Base** — `origin/master` no caso normal. Outra branch só no empilhamento declarado (ver abaixo).

## Empilhar uma frente sobre outra

O padrão é cortar de `origin/master`. Empilhar é legítimo em um caso: **a frente A é pré-requisito
da B e A está travada num portão humano** (uma decisão de produto, uma ratificação). Aí B espera
ociosa ou empilha — e empilhar costuma ser a escolha certa.

Quando empilhar, anote a base na tabela e assuma o que vem junto: **o deploy será A+B**, e quem
segura o portão de A segura a pilha inteira.

O que não pode é empilhar por inércia — herdar a história de outra frente sem ninguém ter decidido
isso. Era o que acontecia quando `worktree.baseRef` era `head`; hoje é `fresh` (parte de
`origin/master`), então o empilhamento virou ato explícito.

## Comandos

```bash
scripts/frente-abrir.sh <nome>            # worktree + vendor + uploads + banco de teste isolado
scripts/frente-abrir.sh <nome> <base>     # empilhamento declarado
scripts/frente-testar.sh <nome>           # suíte DA frente, no banco DA frente
scripts/frente-fechar.sh <nome>           # ritual de migration + suíte; para antes do merge
```

Contexto e medições que sustentam este fluxo: [worktrees-frentes-paralelas.md](worktrees-frentes-paralelas.md).
