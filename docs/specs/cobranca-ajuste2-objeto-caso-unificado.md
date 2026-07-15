# Spec — Ajuste 2: Objeto e Caso viram UMA COISA (página unificada do objeto)

> Módulo **App\Cobranca**, já em produção. Rodada de ajustes 2026-07-13.
> Risco: **MÉDIO** (navegação + camada de leitura + redirects). **NÃO toca dado financeiro.**
> Abordagem escolhida: **A** — página do objeto vira a canônica; `CasoCobranca` continua como âncora invisível (1 por objeto). Zero migração de dados.

## Objetivo

Unificar, na experiência do usuário, as três coisas que hoje ficam em telas separadas — **Objeto**, **Pessoas** e **Caso** — numa **única página do objeto**. Abrir um objeto passa a mostrar a cobrança inteira: pessoas envolvidas, obrigações, pagamentos, acordos, documentos e histórico. O "caso" some da interface; o objeto passa a se comportar como o caso. O `CasoCobranca` permanece intacto por baixo, ancorando saldo/honorários/acordos/pagamentos/alocações/judicialização/alertas.

## Decisões fechadas (brainstorming 2026-07-13)

1. **1 caso invisível por objeto** (Modo Único). A "pessoa cobrada atual" é um ponteiro que se troca (ação `cobranca_caso_alterar_pessoa`, já existente). **Modo Múltiplo da carteira aposentado** — remover a opção do form da carteira num passo posterior (ver §9, fora do escopo mínimo).
2. **Objeto nasce com identificação + NOME do cobrado (decisão revista 2026-07-13).** A criação do objeto pede o objeto (identificação/descrição) **e o nome de quem será cobrado**. Na mesma transação cria-se uma `Pessoa` enxuta (só `nome` — os demais campos são opcionais no modelo), o **caso âncora** com essa pessoa como `pessoaCobradaAtual` e honorários herdados da carteira, e o **vínculo** pessoa↔objeto. **Sem migração** — a `pessoaCobradaAtual` continua obrigatória (`nullable: false`) e o caso sempre nasce válido. Reusa o `AbrirCasoUseCase` (que já cria o caso + snapshot de honorários + evento "CasoAberto"). Obrigações e o resto entram depois, dentro da página; os dados da pessoa (CPF/telefone/e-mail) podem ser completados na aba Pessoas.
   - *Sub-decisão (resolver na Fatia 3):* nome digitado que já existe no tenant → **criar nova** (recomendado, criação sem fricção; dedup/reuso fica na aba Pessoas) ou autocomplete busca-ou-cria já na criação.
   - *Descartado:* "objeto nasce 100% vazio + caso sem pessoa" exigiria tornar `pessoaCobradaAtual` nullable (migração + trocar o INNER JOIN de `baseFiltro` por LEFT) — evitado por esta decisão.
3. **Layout:** pessoa cobrada atual em destaque no cabeçalho (com botão trocar) + **aba "Pessoas"** para os demais envolvidos. Se não há cobrada ainda: estado "Nenhuma pessoa cobrada · [definir]".
4. **Vincular pessoa:** autocomplete busca por nome/CPF/CNPJ entre as `Pessoa` do tenant → vincula a existente (sem duplicar); se não achar, "criar nova" ali mesmo.

## Invariantes de compatibilidade (NÃO violar)

- **Zero migração de dados.** Nenhuma coluna/FK muda. `Obrigacao.caso`, `Pagamento`, `Acordo`, `AlocacaoPagamento`, `Liquidacao`, `ProximaAcao`, `CobrancaSecao`, `CobrancaDocumento`, `EventoHistorico` continuam presos ao `CasoCobranca`.
- **`cobranca_caso_show` continua respondendo** como deep-link (redireciona para o objeto). Links antigos, importação e histórico não quebram.
- **Todas as rotas de mutação `/cobrancas/casos/{id}/…` permanecem** (backend financeiro inalterado). Só o destino do redirect pós-ação muda.
- **Isolamento multi-tenant:** toda leitura do objeto por rota usa `findOneByIdDoTenant` (padrão B-route: lookup por repositório, nunca `getCurrentTenant()` para autorizar acesso ao recurso). 404 em objeto de outro tenant.

## Modelo (inalterado) — recап, para referência

```
Carteira (do Cliente)
  └─ ObjetoCobranca      identificacao, descricao, referenciaExterna
       ├─ VinculoPessoaObjeto (N)   Pessoa + tipoVinculo + período   ← "Pessoas"
       └─ CasoCobranca (1, âncora invisível)   pessoaCobradaAtual, status, honorários, pastaJudicial
            └─ Obrigacao (N), Pagamento (N), Acordo (N), Liquidacao (N), ProximaAcao, Secao, Documento, EventoHistorico
```

`Pessoa` é entidade do tenant (reutilizável entre objetos). O vínculo é por objeto.

## Escopo — o que muda

### A. Nova página canônica do objeto
- **Novo `ObjetoController`** (`app/src/Cobranca/Controller/ObjetoController.php`) com rota **`cobranca_objeto_show`** = `/cobrancas/objetos/{id}` (GET, `id => \d+`).
  - Move para cá as rotas de objeto que hoje moram no `CarteiraController`: `cobranca_objeto_criar` e `cobranca_vinculo_criar`/`cobranca_vinculo_encerrar` (coesão de recurso; `CarteiraController` fica só de carteira). *(Se a movimentação inflar o diff, é aceitável manter `objeto_criar` no `CarteiraController` e criar só o `show` — decidir na implementação; preferência: mover.)*
  - `show(int $id)`: `findOneByIdDoTenant` no objeto → resolve o caso âncora → monta `ObjetoDetalheOutput` → renderiza `cobranca/objeto/show.html.twig`.
- **Novo DTO `ObjetoDetalheOutput`** (`app/src/Cobranca/DTO/`): embrulha o `CasoDetalheOutput` atual (reaproveitado integralmente) + dados do objeto (identificação, descrição, referência, carteira) + lista de vínculos (`VinculoPessoaOutput`: pessoa, papel, período, é-cobrada-atual). **Reaproveita as primitivas de saldo/alertas já otimizadas** (N+1 resolvido nas fases P0–P4 — não regredir).
- **Novo `MontarDetalheObjetoUseCase`** (ou método no repositório) que resolve o caso do objeto e carrega os vínculos com **fetch-join** (evitar N+1). Reusa `MontarDetalheCasoUseCase` internamente.

### B. Template — extrair o corpo do caso num partial reutilizável
- Extrair de `cobranca/caso/show.html.twig` o **corpo operacional** (cabeçalho de saldo/próxima ação/alertas + abas Obrigações / Pagamentos & Liquidações / Acordos / Documentos / Histórico + modais de mutação) para um partial `cobranca/_partials/_corpo_cobranca.html.twig`, parametrizado pelo objeto/caso.
- `cobranca/objeto/show.html.twig` (novo): cabeçalho do **objeto** (identificação + descrição; some o rótulo/ícone "caso"/"briefcase") + bloco "Pessoa cobrada atual" em destaque (reusa markup existente, com botão trocar; estado vazio quando não há cobrada) + **nova aba "Pessoas"** + `include` do `_corpo_cobranca`.
- `cobranca/caso/show.html.twig`: deixa de ser renderizado (vira redirect no controller) — pode ser removido depois que o objeto_show cobrir tudo; manter enquanto a extração não estiver validada.

### C. Aba "Pessoas" (envolvidos)
- Lista os `VinculoPessoaObjeto` do objeto: nome, papel (`tipoVinculo`), período, **destaque de quem é a cobrada atual**.
- **Vincular pessoa:** modal com autocomplete (endpoint GET novo, tenant-scoped, busca `Pessoa` por nome/CPF/CNPJ) → vincula existente via `cobranca_vinculo_criar`; botão "criar nova" reusa `CriarPessoaUseCase` + vínculo, na mesma ação.
- **Trocar cobrada:** reusa `cobranca_caso_alterar_pessoa`. **Encerrar vínculo:** `cobranca_vinculo_encerrar`.
- Guarda: a lista de opções de "trocar cobrada" vem dos vínculos ativos do objeto (via `opcoesDoTenant`/vínculos), não de query global.

### D. Criação do objeto cria o caso âncora (com o nome do cobrado)
- A criação do objeto pede **identificação/descrição do objeto + nome do cobrado** (obrigatório). Na mesma transação: cria `Pessoa` (só `nome`), o `ObjetoCobranca`, o `CasoCobranca` âncora (status `Ativo`, `pessoaCobradaAtual` = essa pessoa, honorários herdados da carteira) e o `VinculoPessoaObjeto`. Reusa `CriarPessoaUseCase` + `AbrirCasoUseCase` + o vínculo. **Sem migração** (`pessoaCobradaAtual` continua `nullable: false`; o caso nasce válido). Redireciona para `cobranca_objeto_show`.
- **Invariante 1-caso-por-objeto** garantida na criação. Objetos legados (importação/uso atual) podem ter 0, 1 ou vários casos: o resolvedor escolhe o caso **ativo mais recente** (fallback: mais recente) e **loga** se houver >1 — nunca explode. Objeto legado sem caso: cria o âncora on-demand no primeiro acesso *(ou via backfill — decidir na implementação; preferência: on-demand no `show`, dentro de transação)*.
- A ação **"Abrir caso"** (`cobranca_caso_abrir`) some da UI. A rota pode ficar como no-op/deprecada ou ser removida — decidir na implementação (preferência: remover da UI, manter rota inofensiva até limpeza posterior).

### E. Carteira — grid de cards de objeto
- `cobranca/carteira/show.html.twig`: substituir os **dois** blocos atuais ("Casos da carteira" + "Objetos da carteira") por **um grid de cards de objeto**: identificação/descrição, **pessoa cobrada**, **estado** (badge de status), **saldo exigível**, mini-lista de envolvidos. Card clicável → `cobranca_objeto_show`.
- Métrica "Casos" do topo da carteira: remover ou renomear para "Objetos". "Novo objeto" permanece.
- O `CarteiraController::show` deixa de montar a lista de casos separada; monta a lista de **objetos com seu caso resolvido** (saldo/cobrada/estado) — reusando primitivas já otimizadas.

### F. Redirects e a palavra "caso"
- **Redirect pós-mutação:** todas as ações em `CasoController` (`encerrar`, `alterarPessoaCobrada`, `judicializar`, `registrarTentativa`) e nos controllers `Obrigacao`/`Pagamento`/`Acordo`/`Documento`/`Liquidacao`/`AcaoCobranca`/`Secao` passam a redirecionar para `cobranca_objeto_show` (resolvendo o objeto do caso). Hoje redirecionam para `cobranca_caso_show`.
- **`cobranca_caso_show`** (`CasoController::show`): vira redirect 302 para `cobranca_objeto_show` do objeto correspondente.
- **Palavra "caso" some dos textos visíveis** (botões, títulos, labels). Onde "encerrar caso" for confuso, ajustar copy (ex.: "encerrar cobrança") — sem renomear rotas/símbolos de backend.

### G. Lista global "Casos" (`cobranca_caso_index`) — **DECIDIDO: remover do menu**
- **Decisão do humano (2026-07-13):** **remover o item "Casos" do menu**; a navegação passa a ser sempre **Carteira → Objeto**. A rota `cobranca_caso_index` deixa de ser link de menu — pode permanecer como deep-link inofensiva ou ser removida na limpeza posterior (preferência: tirar do menu agora, remover a rota/template na fatia de limpeza). **Não** haverá lista global de objetos tenant-wide nesta frente.

## Camadas afetadas (resumo)

| Camada | Mudança |
|---|---|
| Controller | **Novo** `ObjetoController` (`show` + move objeto/vínculo). `CasoController::show`→redirect; redirects de mutação apontam p/ objeto. `CarteiraController::show` monta objetos. |
| UseCase | `CriarObjetoUseCase` cria caso âncora (adapta `AbrirCasoUseCase` p/ pessoa nula). Novo `MontarDetalheObjetoUseCase`. **Nenhum UseCase financeiro muda.** |
| DTO | Novo `ObjetoDetalheOutput` (embrulha `CasoDetalheOutput`) + `VinculoPessoaOutput`. Ajuste no output da carteira p/ cards de objeto. |
| Repository | Resolver "caso do objeto" + fetch-join de vínculos (anti-N+1). Busca de `Pessoa` por nome/CPF/CNPJ tenant-scoped p/ autocomplete. Guard >1 caso. |
| Template | Novo `objeto/show.html.twig` + partial `_partials/_corpo_cobranca.html.twig` (extraído do caso). Nova aba Pessoas. Carteira vira grid de cards. |

## Testes (functional + unit)

- **Functional:** `objeto_show` renderiza as abas e o cabeçalho do objeto; criar objeto cria o caso âncora (sem pessoa); vincular pessoa (busca-existente e cria-nova); trocar cobrada; `caso_show` redireciona para o objeto; **redirect pós-mutação** aponta para o objeto; **isolamento tenant** no `objeto_show` e no autocomplete de pessoa (404/vazio cross-tenant).
- **Unit:** `CriarObjetoUseCase` cria caso âncora com honorários herdados e sem pessoa; resolvedor de caso escolhe o ativo mais recente e loga com >1.
- **Regressão:** suíte `tests/Cobranca` e global permanecem verdes; **não regredir N+1** (as telas continuam usando as primitivas de saldo/alertas otimizadas).

## Ordem de execução (fatias, cada uma testável)

1. **Leitura**: `ObjetoDetalheOutput` + `MontarDetalheObjetoUseCase` + resolvedor "caso do objeto" (com guard >1) + testes unit.
2. **Página**: extrair `_corpo_cobranca` partial + `objeto/show.html.twig` + `ObjetoController::show` + aba Pessoas (só leitura) + `caso_show`→redirect + testes functional.
3. **Criação**: `CriarObjetoUseCase` cria caso âncora (pessoa nula) + "Abrir caso" some da UI + testes.
4. **Pessoas (escrita)**: autocomplete busca-ou-cria + vincular/trocar/encerrar dentro da página + testes.
5. **Redirects**: mutações redirecionam para o objeto + testes.
6. **Carteira**: grid de cards de objeto (remove os dois blocos) + testes.
7. **Copy/menu**: sumir "caso" dos textos; resolver a sub-decisão G.

## Critério de conclusão

- Abrir um objeto mostra pessoas + obrigações + pagamentos + acordos + documentos + histórico numa página só, com a cobrada atual em destaque e a aba Pessoas funcional (busca-ou-cria).
- Criar objeto → página do objeto, pronto para receber pessoas/obrigações.
- Carteira mostra cards de objeto; clicar abre o objeto. Palavra "caso" ausente da UI. Deep-links de caso redirecionam.
- Nenhum dado financeiro alterado; suíte `tests/Cobranca` + global verdes; N+1 não regredido; isolamento tenant provado por teste cross-tenant.

## Fora do escopo (follow-ups)

- Remover a opção **Modo Múltiplo** do form da carteira (item 1 já tocou esse form) — cosmético, depois.
- Aposentar de vez `cobranca_caso_index`/`caso/*` templates após validação em prod.
- Fusão real no modelo (aposentar `CasoCobranca` com migração) — **não** faremos agora; possível follow-up futuro de alto risco.
