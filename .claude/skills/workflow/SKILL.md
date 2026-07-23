---
name: workflow
description: "Comportamento do orquestrador (o cérebro), delegação a subagentes (read-only para investigação/revisão; implementadores para escrita delegada), ciclo de trabalho e execução paralela, regras de documentação/memória e git do jusprime. Carregue sempre no início de qualquer tarefa de implementação, refatoração ou revisão, antes de tocar em código."
---

# Workflow do projeto

## Comportamento do orquestrador (o "cérebro")

A sessão principal atua como orquestrador: analisa, decompõe, define contratos,
delega, integra e valida o trabalho final — sempre após investigar e planejar,
nunca pulando direto pro código. A implementação pode ficar no próprio
orquestrador (tarefa única) ou ser delegada a subagentes implementadores
(fan-out), conforme a seção **Delegação para subagentes**.

> **Nota operacional:** escrita feita por subagente implementador não passa pelos
> prompts de aprovação por arquivo do orquestrador. Por isso a delegação de
> escrita exige escopo exclusivo, worktree isolada em paralelo e revisão
> read-only antes da integração — o isolamento substitui a aprovação por arquivo
> como rede de segurança.

**Antes de agir:**
- Tarefa inequívoca e risco BAIXO → execute, informando o que fará.
- Ambiguidade real, decisão de arquitetura, ou risco ALTO/MÉDIO → pare, faça
  perguntas de escopo e proponha um plano antes de tocar em código.
- Honestidade técnica: se a abordagem pedida tiver problema, discorde com
  fundamento. Não dificulte por esporte; questione quando há motivo real.

**Plano (plan mode):**
- Obrigatório para mudança que toque múltiplos arquivos ou risco ALTO/MÉDIO.
- Trivial de um arquivo (risco BAIXO): pode propor e executar na sequência.

**Risco** (define o rigor acima): ALTO = ponto eletrônico, identidade
User/Tenant · MÉDIO = TenantRole/Permission/Profile · BAIXO = demais.

## Delegação para subagentes

Subagentes são divididos em duas categorias.

### 1. Subagentes read-only

Usados para:

- exploração;
- investigação;
- planejamento;
- revisão arquitetural;
- revisão de segurança;
- revisão multi-tenant;
- revisão de permissões;
- revisão adversarial;
- code review.

Eles apenas analisam e devolvem relatório. Nunca modificam o que revisam.

### 2. Subagentes implementadores

Podem criar e modificar arquivos quando o orquestrador delega explicitamente uma
tarefa de implementação.

Um trabalho só pode ser delegado para implementação paralela quando:

1. os contratos compartilhados necessários já estão definidos;
2. a tarefa possui entrada e resultado esperados claros;
3. o escopo de escrita é exclusivo;
4. nenhum outro agente está modificando os mesmos arquivos;
5. a tarefa pode ser validada isoladamente;
6. dependências com outras tarefas paralelas estão explicitamente declaradas.

Todo agente implementador deve receber:

- objetivo;
- contexto necessário;
- arquivos ou área sob sua responsabilidade;
- arquivos que não pode alterar;
- contratos que deve respeitar;
- testes que deve executar;
- critério objetivo de conclusão.

Agentes implementadores trabalham em worktrees isoladas quando executados
simultaneamente.

O agente implementador deve:

- alterar apenas seu escopo;
- seguir os padrões e skills do projeto;
- implementar testes da própria tarefa;
- executar validações relevantes;
- informar todos os arquivos alterados;
- informar testes executados e resultados;
- informar premissas, desvios e problemas encontrados.

O agente implementador não pode:

- alterar contratos compartilhados sem devolver a decisão ao orquestrador;
- expandir o escopo;
- corrigir problemas não relacionados;
- modificar arquivos pertencentes a outro agente;
- fazer merge, push ou integrar trabalho de terceiros.

### Ciclo de execução paralela (fan-out)

**Pré-requisito (worktree × baseRef).** Os implementadores paralelos rodam em
worktrees isoladas, ramificadas do HEAD da branch atual (`worktree.baseRef=head`
em `.claude/settings.json`). Isso carrega o **committed** da branch — inclusive
commits não-enviados —, mas **não** o uncommitted. Por isso os contratos
compartilhados necessários ao fan-out **devem estar committados antes da
delegação**; alterações uncommitted não chegam aos implementadores isolados.

1. Orquestrador analisa a etapa.
2. Orquestrador identifica dependências.
3. Contratos compartilhados são definidos e **committados** primeiro.
4. Trabalho é dividido em blocos com propriedade exclusiva.
5. Implementadores executam blocos independentes em paralelo, cada um na sua
   worktree. **Não rodam testes que dependem do container `jusprime_php_dev`** — ele
   monta o checkout principal, não a worktree; eles apenas **escrevem** os testes
   do próprio bloco. Ao terminar, **entregam o próprio trabalho como commit local
   na sua worktree** (revisando o diff antes; só arquivos do seu escopo; nunca
   push/merge).
6. Cada resultado é revisado pelo `feature-review-agent` (read-only, isolado).
7. O orquestrador integra **um commit aprovado por vez** na branch principal da
   feature, via `git cherry-pick` (ver **Git na execução paralela** abaixo).
8. Após cada `cherry-pick`, roda **imediatamente os testes direcionados** no
   container principal; só integra o próximo se o anterior estiver estável.
9. Ao final da onda, roda a **suíte completa** e as verificações transversais.
10. A próxima onda só é liberada após a estabilização da anterior.

Quando houver dúvida sobre independência entre duas tarefas, executar
sequencialmente.

**Validação centralizada no checkout principal é o fluxo oficial atual** —
implementadores isolados escrevem testes, mas quem os executa é o orquestrador,
após integrar. Um container por worktree **não** faz parte do fluxo e **não** é
pendência.

### Git na execução paralela

- Subagentes implementadores criam commits **apenas nas próprias worktrees** e
  somente com alterações do **escopo delegado** — e **nunca integram**. O hook
  por-agente `feature-implementer-no-integration.py` bloqueia tecnicamente
  cherry-pick/merge/rebase/reset/push para eles.
- O orquestrador integra **autonomamente**, sem aprovação humana entre
  integrações válidas, aplicando os commits **aprovados** via `git cherry-pick`.
- **Forma segura obrigatória do cherry-pick** (é o que o hook global permite ao
  orquestrador): **um único commit** por chamada, ou `--continue`, ou `--abort`.
  Ficam **bloqueados** `-n`/`--no-commit`, ranges (`A..B`), múltiplos hashes,
  `--stdin`, `--skip` e qualquer forma que acumule vários commits numa operação.
- **Invariante:** `commit aprovado → cherry-pick individual → testes direcionados
  → estabilização → próximo commit`. Um commit por vez; só integra o próximo com o
  anterior estável. O orquestrador repete esse ciclo sozinho.
- **Push, deploy, merge em branch protegida e Git destrutivo continuam
  proibidos.** `git checkout` também é barrado — em conflito, resolva editando o
  arquivo + `git add` + `git cherry-pick --continue` (sem checkout).

### Tratamento de conflitos no cherry-pick

**Conflito textual ou mecânico simples** — o orquestrador resolve **autonomamente**:
1. analisa o conflito;
2. resolve apenas o conflito textual/mecânico;
3. revisa o diff resultante;
4. `git add` nos arquivos resolvidos;
5. `git cherry-pick --continue`;
6. roda os testes direcionados;
7. continua o fluxo se estável.

Exemplos de conflito simples: mesma linha alterada sem mudança de regra;
import/`use` duplicado; ajuste mecânico de namespace; conflito de formatação;
duas alterações compatíveis que só precisam ser combinadas.

**Conflito semântico** — envolve regra de negócio, arquitetura, modelo de domínio,
contrato compartilhado, interface entre tarefas, decisão da spec, expansão de
escopo ou comportamento incompatível entre implementações. O orquestrador deve:
1. interromper o fan-out;
2. **não** escolher silenciosamente uma das implementações;
3. **não** executar `--skip`;
4. abortar o cherry-pick (`--abort`) se preciso para recuperar um estado estável;
5. documentar o conflito, a causa e as tarefas afetadas;
6. resolver a decisão no contexto principal;
7. estabilizar e commitar o contrato corrigido;
8. replanejar ou redistribuir as tarefas dependentes;
9. só então retomar a execução autônoma.

**Na dúvida entre conflito simples e semântico, tratar como semântico e
interromper o fan-out.**

## Frentes paralelas (worktrees)

Isto é diferente do fan-out acima. Lá, subagentes dividem **uma** tarefa e as worktrees
saem do HEAD da branch (`baseRef=head`), para carregar os contratos committados localmente.
Aqui, **frentes** independentes correm em paralelo, possivelmente em sessões e dias diferentes,
e cada uma sai de `origin/master`.

A base de cada frente é definida pelo **script**, não pelo `worktree.baseRef`:
`scripts/frente-abrir.sh` faz `git worktree add ... origin/master` explicitamente. Por isso o
setting continua `head` (que o fan-out precisa) sem prejudicar as frentes.

Registro obrigatório: `docs/frentes-ativas.md`. É o que permite duas sessões saberem uma da
outra. Fatos medidos do ambiente: `docs/worktrees-frentes-paralelas.md`.

```bash
scripts/frente-abrir.sh <nome>          # worktree + vendor + uploads + banco de teste isolado
scripts/frente-testar.sh <nome>         # suíte DA frente, no banco DA frente
scripts/frente-fechar.sh <nome>         # ritual de migration + suíte; para antes do merge
```

**Princípio:** implementação em paralelo, **integração em série**. Um piloto de git por vez.

1. **Um domínio por frente.** Duas frentes no mesmo domínio conflitam quase sempre.
2. **Arquivos compartilhados declarados na abertura.** Os que doem aqui:
   `app/templates/base.html.twig`, CSS global, rotas, enums de `app/src/Shared/`, `docs/`.
   Quem toca um desses vai sozinho ou por último.
3. **Frentes com migration, uma de cada vez** — não por impossibilidade, mas porque o custo de
   fazer certo (renomear a versão, ler as duas, rodar de novo) supera o de esperar. Frentes de
   tela, relatório ou lógica paralelizam à vontade. *Mantenha essa justificativa ao repetir a
   regra: sem o porquê ela vira dogma e alguém contorna sem entender o que perde.*
4. **Verde na branch não basta.** Uma branch cortada de `origin/master` prova `master + A`;
   nenhuma prova `master + A + B`. Antes de integrar, traga o master para dentro da frente e
   rode de novo; **depois** do merge, rode a suíte no master. É esse segundo passo que pega a
   quebra cruzada, e é o que todo mundo pula.
5. **Smoke serializado.** `nginx.conf` fixa `root /var/www/app/public` e só a 8080 é publicada:
   `localhost:8080` sempre serve o repositório principal. Duas frentes não podem ser conferidas
   no navegador ao mesmo tempo.

### Cortar do master, e quando empilhar

O padrão é cada frente sair de `origin/master`. Mas a regra tem três ramos, não um:

1. **A pronta e liberável** → publique A, corte B do master. Caso comum.
2. **A travada num portão humano** (decisão de produto, ratificação) → empilhar B sobre A é
   legítimo: `scripts/frente-abrir.sh <b> <a>`. Declare a base em `docs/frentes-ativas.md` e
   assuma o que vem junto — **o deploy será A+B**, e quem segura o portão de A segura a pilha.
3. **Empilhar sem nenhum dos dois motivos** → é o acidente. Contra ele agem o hook
   `pre-commit` (recusa commit na branch errada) e o registro em `docs/frentes-ativas.md`.

Já aconteceu de a camada de baixo ficar dias parada num portão de ratificação e a de cima
empilhar por cima; quando subiu, um deploy virou 36 commits com uma decisão de dinheiro no meio.
O erro não foi cortar da branch errada — a dependência era real. Foi não declarar a pilha.

### Armadilhas medidas (não repita)

- **`cd app && php bin/phpunit` testa o repositório PRINCIPAL**, não a worktree — verde falso.
  Use `scripts/frente-testar.sh`.
- **Worktree nova nasce sem `app/vendor/`** (gitignored, 299M): a suíte falha seca até rodar
  `composer install`. Idem `app/public/uploads/` — sem os diretórios o upload quebra por
  permissão, não por código. O `frente-abrir.sh` cobre os dois.
- **O banco de teste da frente é um CLONE do `saas_test` (`CREATE DATABASE … TEMPLATE`).** As duas
  receitas óbvias produzem banco errado: `migrations:migrate` num banco vazio nem completa (parte
  das migrations supõe dados de dump), e `schema:create` completa mas entrega banco **incompleto** —
  sem a extensão `unaccent`, sem as 4 funções do schema `public` e com 2 índices a menos, porque
  isso vem de SQL cru das migrations, não do mapeamento das entidades. Sintoma: ~22 testes de busca
  livre/acento falhando contra um master verde. O `frente-abrir.sh` já faz o clone.
- **Ao decidir se uma branch pode ser apagada, confira por CONTEÚDO contra `origin/master`**
  (`git cherry`) e use `git branch -d`, nunca `-D`. `git status --porcelain` mostra arquivo não
  commitado e **não** mostra commit não publicado — checagem incompatível com comando destrutivo.

## Ciclo de trabalho (sequencial, tarefa única)

Para trabalho que não se decompõe em blocos paralelos, o orquestrador conduz o
ciclo direto; subagentes read-only investigam e revisam.

1. **Investigar** → subagente investigador (read-only) mapeia impacto e devolve resumo.
2. **Planejar** → orquestrador monta o plano (ver plan mode acima). Em tarefas
   **ALTO/MÉDIO, registra a spec** em `docs/specs/` — ela é o alvo contra o qual
   a revisão confere. No **BAIXO trivial**, a descrição da tarefa basta.
3. **Implementar** → o **orquestrador** aplica as mudanças (controle por arquivo),
   ou delega a subagentes implementadores no fan-out (ver **Delegação**).
4. **Revisar** → `feature-review-agent` (read-only) revisa o diff contra a spec
   (ALTO/MÉDIO) ou contra a descrição (BAIXO): aponta divergências entre o pedido
   e o feito, edge cases e violações dos padrões do CLAUDE.md. Só aponta — não
   conserta. Devolve relatório.
5. **Corrigir** → o **orquestrador** aplica as correções apontadas.
6. **Conferir** → orquestrador confere. Em risco ALTO, devolve ao
   `feature-review-agent` para nova revisão antes de seguir.

**Disparo da revisão:** o passo 4 é acionado pelo comando `/review`, não por
auto-delegação. CLAUDE.md inclina o comportamento, mas não garante; em contexto
longo a auto-delegação falha. Para disciplina ALTO-risco, dispare explicitamente.

## Documentação / Memória

Subagente de docs mantém o estado do projeto (feito, pendente, urgente, prioridades):
- Documentos de estado/progresso (a Memória) → atualiza livremente.
  *(Exceção consciente à regra read-only: escrita permitida por ser risco BAIXO.)*
- Documentos de arquitetura/decisão → **propõe** a mudança ao orquestrador para
  revisão humana; nunca reescreve sozinho.

## Git

Ver regra no root CLAUDE.md. Resumo: **staging e commit local são permitidos** —
o orquestrador roda `git add`/`git commit`, revisando `git status` + diff +
arquivos staged antes, sem `git add .` cego. **Push, merge, rebase, reset e
integração continuam do humano**: o orquestrador monta e explica esses comandos e
entrega em bloco `# Execute manualmente no terminal externo`, você aprova sim/não.
`block-git-writes.py` permanece ativo — libera `add`/`commit` (e `cherry-pick`
**individual**, um commit por vez, só para a integração do orquestrador no
fan-out), bloqueia push/merge/rebase/reset e não aceita `--no-verify`. Subagentes
implementadores podem commitar o próprio trabalho (na própria worktree, no
fan-out), mas nunca fazem merge, push, rebase, reset, cherry-pick nem integram —
o hook por-agente `feature-implementer-no-integration.py` trava isso tecnicamente.
