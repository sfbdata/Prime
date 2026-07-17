# Ajuste 10 — Handoff para o próximo chat

> **Estado em 2026-07-17 (fim do dia).** Branch `redesenho-objeto-cobranca`, **nada publicado**.
> `tests/Cobranca` **609/609** · global **1973/1973**.
> **T1–T8 COMPLETAS e revisadas.** T6 = bug de dinheiro §5.3 consertado; T7 = B1–B4; T8 = erros inline
> (B5) em **13 de 14 forms**, review adversarial sem bloqueante, achado A1 (comentário) corrigido, smoke
> real feito. **Falta só T9 (higiene) e T10 (opcional) — e o passe do `acordoCriar` (o 14º form da T8).**
>
> ⚠️ Ao reabrir: **confira o Git, não este cabeçalho** — versões anteriores deste arquivo já ficaram
> para trás do estado real. Fonte viva do detalhe de cada tarefa: `.superpowers/sdd/progress.md`.
>
> **PRÓXIMO PASSO NATURAL = T9 (formulários sob demanda).** Prompt pronto em `NEW_CHAT_PROMPT.md`.

## Leia nesta ordem

| # | Arquivo | O que é |
|---|---|---|
| 1 | `docs/specs/cobranca-ajuste10-redesenho-objeto-show.md` | **A spec.** Fonte de verdade do *o quê* e do *porquê*. |
| 2 | `docs/gestao-cobrancas/PLANO_AJUSTE10_REDESENHO_OBJETO_SHOW.md` | **O plano.** 10 tarefas em TDD, com o código. §11 = ordem. |
| 3 | `.superpowers/sdd/progress.md` | **O ledger.** O que foi feito, com os achados de cada revisão. **Tarefa marcada COMPLETE não se refaz.** |
| 4 | `docs/gestao-cobrancas/mockup-ajuste10-objeto-show.html` | O alvo visual aprovado (abra no navegador). |

## Como executar (o que funcionou)

Skill **`superpowers:subagent-driven-development`**. Por tarefa:

1. `scripts/task-brief PLANO N` → extrai o brief (o script vive em
   `~/.claude/plugins/cache/claude-plugins-official/superpowers/6.1.1/skills/subagent-driven-development/scripts/`)
2. `git rev-parse --short HEAD` → **guarde a BASE** (nunca use `HEAD~1`)
3. Despache **`feature-implementer`** (agente do projeto, tem trava de integração própria) com: onde a tarefa
   se encaixa · o caminho do brief · o que as tarefas anteriores entregaram · as ambiguidades já resolvidas ·
   o caminho do relatório
4. `scripts/review-package BASE HEAD` → despache **`feature-review-agent`** com brief + relatório + diff
5. Achado **Crítico/Importante** → **um** fix subagent com a lista completa → re-revisão (use `SendMessage`
   no mesmo revisor: ele já tem o contexto)
6. **Smoke visual é seu** — os subagentes não têm navegador. **Não aceite "testei" sem evidência.**
7. Marque no ledger e siga

**Modelos:** implementador de tarefa grande = opus; fix mecânico = sonnet/haiku; revisor de tarefa de dinheiro
ou de template = opus.

## Ambiente (não perca tempo com isso)

- **Tudo no container:** `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'`.
  Nunca `php` no host.
- **`docker exec` precisa de `-i` para heredoc** — sem ele o `psql` sai em silêncio e você acha que rodou.
  (Custou tempo: eu "criei" uma tabela que não foi criada e afirmei sucesso.)
- **Banco de teste = `saas_test`**, montado por `schema:create`, **não** por replay de migrations. Feature nova
  = tabela ausente. Já consertei a que faltava (`sync_drive_conexao`).
  🚫 **Nunca `doctrine:schema:update --force`**: ele quer **dropar 3 índices funcionais**
  (`idx_cobranca_pessoa_tenant_cpf_digitos`, `..._cnpj_digitos`, `uniq_cobranca_obrigacao_ref_externa` = a
  idempotência do import). Crie só o que falta, via psql.
- **Smoke:** `http://localhost:8080` · `farlei.rocha@gmail.com` / `Prime123!` · objetos bons: **297** (acordo
  vigente + substituída), **108** (95 obrigações acordáveis, bom p/ acordo), **296**.
  Gotcha: `#modalAlertaPonto` intercepta cliques → remova via `browser_evaluate`.
- **Dado do dev é dump de PROD** — não semeie/altere à toa.

## O que falta, na ordem

| Tarefa | O que é | Nota |
|---|---|---|
| ~~T6~~ | ~~Acordo sobre obrigação paga (spec §5.3)~~ | ✅ **COMPLETA.** Bug de dinheiro consertado e provado (objeto 296). |
| ~~T7~~ | ~~B1–B4 (data do pagamento, aba grudada, subnav, redirect)~~ | ✅ **COMPLETA.** Review sem bloqueante, smoke real nos 2 lados. |
| ~~T8~~ | ~~Erros inline (B5)~~ | ✅ **COMPLETA (13/14 forms).** SESSÃO+PRG+one-shot+fronteira CSRF+URL da ação (reutilizáveis). Review sem bloqueante. **Falta só `acordoCriar`** (passe próprio — colide com o reset-on-close da T5). |
| **T9** | Formulários sob demanda | **➡️ PRÓXIMO.** Higiene, não UX (a página não está lenta). Meça **antes** e depois. Segunda a cair se o tempo apertar. |
| **T10** | (opcional) "o que este pagamento abateu" | **Primeira a cair.** Foi ideia minha, não pedido do humano. |
| ⏳ `acordoCriar` | O 14º form da T8, adiado | Parcelas são CollectionType (reidratável), MAS o auto-open dispara o reset-on-close (T5, `351dcf8`/`906af4c`) e apaga o reidratado. Precisa de flag "não resetar ao reabrir por erro". Passe cuidadoso — mexe no fluxo de acordo já em prod. |

### T6 tem uma dívida herdada da T5 — não esqueça

A barra de seleção soma `data-valor-centavos` = **`restante`** (`_divida.html.twig:82`), mas o modal soma o
**seu** `data-valor-centavos` = **`valorExigivel` cheio** (`AcordoCriarType.php:110`). Obrigação de R$1.200 com
R$400 pagos: **a barra diz R$800 e o modal abre dizendo R$1.200**, auto-preenchendo o total com 1.200.

Isso **é** o vetor do §5.3 — e a T6 já vai fazer `valoresObrigacoes` usar o remanescente, o que faz os dois
convergirem. **A T6 deve incluir um teste de que a barra e o modal concordam.**

## Gotchas que já custaram caro (todos comprovados)

1. **`parseCentavos` é pt-BR: ponto = milhar.** Valor escrito em input **usa vírgula**. `1320.00` vira
   R$ 132.000,00. Já aconteceu no ajuste 7; a T5 acertou usando `fmtReais`.
2. **Nomes de método de teste em `camelCase`.** A suíte tem 489 camelCase × 0 snake_case. (Meu plano trazia
   snake_case por engano e contaminou T1–T3 antes de ser pego; já corrigido em toda parte.)
3. **`bi-handshake` NÃO existe** no Bootstrap Icons 1.13.1. O ícone de acordo do módulo é
   **`bi-file-earmark-text`** (usado em `acordo/show:13`, `_acoes_modais:234`, `_divida:44`,
   `_movimentos:126`). **Não troque só um botão** — ou troca o módulo inteiro, em commit próprio.
4. **PHPUnit: o primeiro matcher registrado vence.** Um `willReturn` posterior no mesmo método é
   **silenciosamente ignorado** (provado em `vendor/phpunit/.../InvocationHandler.php:100-137`). Por isso o
   `setUp()` de `MontarDetalheCasoUseCaseTest` **não** stuba `doCaso` por padrão.
5. **`id="pastaTabs"` EXISTE** (`pasta/show.html.twig:320`) — o JS é **compartilhado** com Pastas.
6. **A chave do file manager é `fmTab_<casoId>`, não `<objetoId>`** (`data-pasta-id="{{ casoId }}"`).
   Objeto 296 → caso 295.

## Invariantes que ninguém pode afrouxar

- **INV-U1:** parcela de acordo (`acordoOrigemId !== null`) **nunca** oferece checkbox nem "Acordar" — acordo
  sobre acordo **duplica dívida no saldo** ao romper (ajuste 9).
- **INV-U2:** o prefill do "Receber" é o **bruto** (`brutoParaRecuperar`), nunca o restante cru.
- **`gerenciar` × `movimentacao_financeira` são capacidades SEPARADAS.** Nunca unifique.
- **Assimetria deliberada (spec ajuste 9 §5.1.1):** o render filtra **substituíveis**, o POST valida contra
  **exigíveis**. Parece bug. **NÃO iguale.**
- **`GrupoAcordoObrigacoesOutput.valorTotal` soma só as parcelas vivas** — é o que bate com o saldo derivado.
- **Nunca somar os `restante` da tela** esperando reproduzir `caso.saldoExigivel` (a aba usa `doCaso`, o saldo
  usa `doCasoExigiveis`).
- **Não mexer em `CalculadoraSaldo`.** A regra está certa e alimenta o batch do Dashboard.

## Cadência com o humano

Implementar → **MOSTRAR o smoke** → ele aprova → suíte + `/review` + commit. **MÉDIO/ALTO:** investigar → spec
→ ele aprova → implementar. **Push/merge/deploy são dele.** Commit local é permitido.

## Follow-ups abertos (não são desta rodada)

- Alocação **manual** não tem teto por obrigação → dá para gerar **saldo negativo**, e aí o caso não alerta
  "pronto para encerrar" nem pode ser encerrado (`AlertasCobranca:203` e `EncerrarCasoUseCase:56` usam `=== 0`).
  O FIFO bloqueia; o manual, não. **Pré-existente.**
- `MontarDetalheCasoUseCase` está com **10 dependências** e `AlertasCobranca` é `final`/não-mockável (o teste
  instancia ela real sobre os mesmos mocks). Candidato a extrair interface — **não** nesta rodada.
- `CasoDetalheOutput::$obrigacoes` hoje tem **zero leitores** (o badge da aba que a consumia foi deletado).
- Menores no ledger, para a revisão final triar.
- 4 bancos de teste órfãos de worktrees antigas: `saas_testwt`, `saas_testint`, `saas_testf2`.
