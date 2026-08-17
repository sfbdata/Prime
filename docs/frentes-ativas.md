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

⚠️ A regra da casa manda **uma frente com migration por vez**. Hoje só a
`cobranca-acompanhamento-canonico` tem migration pendente (4), e está PARADA — então não há disputa;
quem revivê-la precisa ler o bloco dela antes de gerar a próxima versão.

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

`sync-sistema-manda` foi **integrada, publicada e deployada** antes disso (`d55ebf16`). ⚠️ **O cron e o
worker do Drive continuam pausados em produção** e o smoke da tela ainda não foi feito — religar é
decisão do dono, não consequência do próximo deploy.

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
