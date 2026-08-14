# Frentes ativas

Registro das frentes de trabalho abertas em paralelo. **É o que permite duas sessões saberem uma da
outra** — sem ele não há coordenação possível, só descoberta tardia no merge.

Quem abre uma frente acrescenta a linha. Quem integra tira.

| Frente (branch) | Domínio | Migration? | Arquivos compartilhados que toca | Estágio | Base |
|---|---|---|---|---|---|
| `cobranca-espelho-quatro-relatorios` | Cobrança (espelho) | **sim — 1** | `docs/specs/` | em revisão | `origin/master` @ `4ff88ee6` |
| `cobranca-acompanhamento-canonico` | Cobrança (modelo objeto/caso) | **sim — 4** | `docs/gestao-cobrancas/` | 🛑 **PARADA** (ver abaixo) | `origin/master` @ `0bb1f29` |
| `sync-sistema-manda` | Sync (Drive) + Pasta (numeração) | **não** | `docs/specs/fase2-import-export-sincronizacao.md` | implementando | `master` local @ `3702452f` |

⚠️ **Worktree não herda `app/.env.local`.** O `app/.env` versionado aponta para o banco `saas`, onde
`cobranca_relatorio_importado` **não existe** — o dev de verdade usa `saas_ux`. Qualquer conferência
rodada de dentro de uma worktree sem sobrescrever `DATABASE_URL` falha, ou pior, mede o banco errado.
Para os testes isso não vale: `scripts/frente-testar.sh` usa o banco clonado da frente.

⚠️ `cobranca-espelho-quatro-relatorios` **tem migration** (`Version20260813210000`): 5 colunas em
`cobranca_relatorio_linha`, 1 em `cobranca_relatorio_totalizador` e a troca do índice único
`uniq_cobranca_relatorio_arquivo` (o `tipo` entra na chave). Nenhuma toca tabela de dívida. A regra da
casa manda **uma frente com migration por vez** — `cobranca-acompanhamento-canonico` também tem 4, mas
está PARADA e não avança, então não há disputa; quem revivê-la precisa ler esta linha antes de gerar a
próxima versão.

⚠️ `sync-sistema-manda` tocará `src/Pasta/UseCase/CriarPastaUseCase.php`, `PastaController::criar()` e o
modal `_partials/modal_nova_pasta.html.twig` (numeração automática), além de `src/Sync/` inteiro. Frente de
Cobrança que mexa em Pasta precisa saber. Base é o `master` **local** (1 commit à frente de `origin/master`:
só o commit docs da spec `3702452f`) — não é empilhamento sobre outra frente.

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
