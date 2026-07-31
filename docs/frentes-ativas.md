# Frentes ativas

Registro das frentes de trabalho abertas em paralelo. **É o que permite duas sessões saberem uma da
outra** — sem ele não há coordenação possível, só descoberta tardia no merge.

Quem abre uma frente acrescenta a linha. Quem integra tira.

| Frente (branch) | Domínio | Migration? | Arquivos compartilhados que toca | Estágio | Base |
|---|---|---|---|---|---|
| `cobranca-importar-cadastro-acordos` | Cobrança (importação) | **sim** (coluna `competencia` + backfill) | `.gitignore` (regra de PII das planilhas) | pronta para integrar | `origin/master` |

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
