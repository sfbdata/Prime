# Plano de Implementação — Ajuste 2: página unificada do objeto

> **Para workers agênticos:** implementar tarefa a tarefa. Steps usam checkbox (`- [ ]`).
> **Spec (contrato):** `docs/specs/cobranca-ajuste2-objeto-caso-unificado.md`. Ler antes de cada fatia.
> **Cadência do projeto (obrigatória):** por fatia → implementar → **MOSTRAR resultado (smoke visual no navegador)** → humano APROVA → só então suíte completa + `/review` + corrigir + **commit atômico** → próxima fatia. Não rodar suíte/`review` antes do aval visual da fatia.

**Goal:** Abrir um objeto passa a mostrar a cobrança inteira (pessoas, obrigações, pagamentos, acordos, documentos, histórico) numa página só; o "caso" some da UI mas continua como âncora invisível (1 por objeto).

## Estado (2026-07-14)
- ✅ **Fatia 1** leitura — `fe536eb`
- ✅ **Fatia 2** página do objeto + navegação — `be936a6` (`caso_show` ainda renderiza; redirect é a Fatia 5)
- ✅ **Fatia 3** criar objeto pede o nome do cobrado (`CriarObjetoComCobrancaUseCase`; "Abrir caso" removido; "Novo objeto" no header de Casos) — `8118137`
- ✅ **Fatia 4** "Nova pessoa" no objeto (cadastra+vincula) — `3522495`
- ✅ **Fatia 6** card "Objetos" removido da carteira; Vincular/Encerrar relocados pro objeto; `PessoaController` redireciona pro objeto — `74904f0`
- ⏳ **Fatia 5** redirects das mutações → objeto + `caso_show`→redirect + atualizar testes de mutação (que hoje batem em `/casos/{id}`)
- ⏳ **Fatia 7** tirar "Casos" do menu + remover `caso/show.html.twig` morto + copy "caso"

**Architecture:** Abordagem A — `CasoCobranca` intacto por baixo. Nova página `cobranca_objeto_show` resolve o caso único do objeto e reusa o corpo do caso via partial. Carteira vira grid de cards de objeto. Zero migração de dados.

**Tech Stack:** PHP 8.2 / Symfony 7.4 / Doctrine ORM 3 / Twig / PHPUnit 11 (DAMA + Foundry v2), tudo dentro do container `jusprime_php_dev`.

## Global Constraints (copiar da spec — valem em TODA tarefa)

- **Zero migração de dados.** Nenhuma coluna/FK muda. Não há migration nesta frente.
- **Isolamento multi-tenant:** acesso a objeto/caso por rota SEMPRE via `findOneByIdDoTenant($id, $tenant)` (padrão B-route). 404 cross-tenant. Autocomplete de pessoa filtra por tenant.
- **Não regredir N+1:** reusar as primitivas já otimizadas (`CalculadoraSaldo::saldosDosCasos`/`derivarSaldosDosCasos`, `AlertasCobranca::alertasDosCasos`, fetch-joins). Nada de saldo por SQL novo.
- **Padrões PHP/Symfony do projeto:** `declare(strict_types=1)`, type hints 100%, `private readonly` promovido, classes `final` (menos entidades), só atributos PHP, `===`/`!==`, sem `else` após `return`. 1 controller por recurso.
- **`CasoCobranca` permanece.** `cobranca_caso_show` continua respondendo (vira redirect). Rotas de mutação `/cobrancas/casos/{id}/…` permanecem; só muda o destino do redirect.
- **Comandos:** `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit <alvo>'`.

**Interfaces existentes reutilizadas (assinaturas reais):**
- `MontarDetalheCasoUseCase::executar(CasoCobranca $caso): CasoDetalheOutput` — já monta cabeçalho (saldo/estado/pessoa/próxima ação/alertas) + coleções das abas. O `CasoDetalheOutput` já expõe `objetoIdentificacao`, `objetoDescricao`, `carteiraId`, `carteiraNome`, `pessoaCobrada*`, saldos, `alertas`, `obrigacoes/pagamentos/liquidacoes/acordos/historico`.
- `CasoCobrancaRepository::findOneByIdDoTenant(int $id, Tenant $tenant): ?CasoCobranca`.
- `VincularPessoaAObjetoUseCase`, `CriarPessoaUseCase`, `CriarObjetoUseCase`, `AbrirCasoUseCase` (hoje exige pessoa cobrada — será adaptado).
- Rotas existentes: `cobranca_objeto_criar` (`/cobrancas/carteiras/{id}/objetos`), `cobranca_vinculo_criar` (`/cobrancas/objetos/{id}/vinculos`), `cobranca_vinculo_encerrar`, `cobranca_caso_alterar_pessoa` (`/cobrancas/casos/{id}/pessoa-cobrada`).

---

## Fatia 1 — Camada de leitura: resolver o caso do objeto + DTOs

**Objetivo:** dado um objeto, resolver seu caso âncora (com guarda p/ legado >1 caso) e montar um `ObjetoDetalheOutput` que embrulha o `CasoDetalheOutput` + identidade do objeto + vínculos. Sem UI ainda.

**Files:**
- Create: `app/src/Cobranca/DTO/VinculoPessoaOutput.php`
- Create: `app/src/Cobranca/DTO/ObjetoDetalheOutput.php`
- Create: `app/src/Cobranca/UseCase/MontarDetalheObjetoUseCase.php`
- Modify: `app/src/Cobranca/Repository/CasoCobrancaRepository.php` (novo `casoAncoraDoObjeto`)
- Modify: `app/src/Cobranca/Repository/VinculoPessoaObjetoRepository.php` (fetch-join dos vínculos de um objeto, se ainda não houver)
- Test: `app/tests/Cobranca/Functional/MontarDetalheObjetoUseCaseTest.php` (integração/KernelTestCase — `MontarDetalheCasoUseCase` é `final`, não-mockável, então usa banco + Foundry, o que `tests/CLAUDE.md` classifica como Functional)

**Interfaces (Produces):**
- `CasoCobrancaRepository::casoAncoraDoObjeto(ObjetoCobranca $objeto): ?CasoCobranca` — retorna o caso Ativo mais recente do objeto; se >1, escolhe o mais recente e `logger->warning(...)`; null se objeto sem caso.
- `VinculoPessoaOutput` (readonly): `pessoaId:int`, `nome:string`, `cpf:?string`, `cnpj:?string`, `email:?string`, `telefone:?string`, `papelLabel:string`, `dataInicio:\DateTimeImmutable`, `dataFim:?\DateTimeImmutable`, `ativo:bool`, `ehCobradaAtual:bool`; `::fromEntity(VinculoPessoaObjeto $v, ?int $pessoaCobradaAtualId): self`.
- `ObjetoDetalheOutput` (readonly): `objetoId:int`, `identificacao:string`, `descricao:?string`, `referenciaExterna:?string`, `carteiraId:int`, `carteiraNome:string`, `caso:CasoDetalheOutput`, `temCobradaAtual:bool`, `vinculos: list<VinculoPessoaOutput>`.
- `MontarDetalheObjetoUseCase::executar(ObjetoCobranca $objeto, CasoCobranca $caso): ObjetoDetalheOutput` — recebe o caso já resolvido/validado por tenant no controller; delega ao `MontarDetalheCasoUseCase` e agrega os vínculos.

- [ ] **Step 1: teste unit falhando** — `MontarDetalheObjetoUseCaseTest`: cria carteira→objeto→caso (Foundry), 2 vínculos (1 = pessoa cobrada atual), executa e assevera: `identificacao` do objeto, `caso` é `CasoDetalheOutput`, `vinculos` tem 2 itens, exatamente 1 com `ehCobradaAtual === true`, `temCobradaAtual === true`. Seguir `app/tests/CLAUDE.md` (attributes, Foundry v2, sem mock de EM — usar os repos reais via container de teste).
- [ ] **Step 2: rodar e ver falhar** — `... bin/phpunit tests/Cobranca/Unit/MontarDetalheObjetoUseCaseTest.php` → FAIL (classe inexistente).
- [ ] **Step 3: criar `VinculoPessoaOutput`** com `fromEntity`.
- [ ] **Step 4: criar `ObjetoDetalheOutput`.**
- [ ] **Step 5: criar `casoAncoraDoObjeto`** no `CasoCobrancaRepository` (QueryBuilder filtrando por objeto, `orderBy criadoEm DESC`, prioriza `status = Ativo`; `logger->warning` se count>1). Injetar `LoggerInterface` se ainda não injetado.
- [ ] **Step 6: criar `MontarDetalheObjetoUseCase`** (injeta `MontarDetalheCasoUseCase` + `VinculoPessoaObjetoRepository`).
- [ ] **Step 7: rodar e ver passar.**
- [ ] **Step 8 (após aval visual da fatia — aqui não há visual):** rodar `tests/Cobranca` + global, `/review`, corrigir, **commit** `Cobrancas: leitura do objeto (DTO+usecase p/ pagina unificada)`.

> *Nota de cadência:* fatia 1 não tem tela; o "mostrar" é o teste verde. As fatias 2+ têm smoke visual antes do commit.

---

## Fatia 2 — Página do objeto (leitura)

**Objetivo:** nova rota/tela `cobranca_objeto_show` renderiza cabeçalho do objeto + cobrada em destaque + aba Pessoas (só leitura) + o corpo operacional (abas).

> **Desvio de plano (decidido na implementação, registrado 2026-07-13):**
> 1. **Sem partial `_corpo_cobranca`.** Como `caso_show` vira redirect (Fatia 5), o corpo do caso teria um único consumidor → YAGNI. Em vez do partial, o corpo foi **movido** para `objeto/show.html.twig` (o template do caso segue vivo até a Fatia 5). Os 3 helpers de construção de modais/documentos do `CasoController` viraram o serviço **`MontadorModaisCaso`** (DRY real: usado pelas duas páginas).
> 2. **`caso_show` NÃO vira redirect na Fatia 2 — só na Fatia 5.** Fazer o redirect aqui quebraria os testes de mutação existentes (eles fazem `GET /cobrancas/casos/{id}` para pegar o token CSRF e conferem o conteúdo após o POST, que ainda redireciona para `caso_show`). O flip de TODOS os redirects (`caso_show` + mutações) + atualização dos testes + remoção do `caso/show.html.twig` morto fica coeso na **Fatia 5**.

**Files:**
- Create: `app/src/Cobranca/Controller/ObjetoController.php` (rota `cobranca_objeto_show`)
- Create: `app/templates/cobranca/objeto/show.html.twig`
- Create: `app/templates/cobranca/_partials/_corpo_cobranca.html.twig` (extraído de `caso/show.html.twig`)
- Modify: `app/templates/cobranca/caso/show.html.twig` → passa a `include` o partial (fica idêntico até removermos)
- Modify: `app/src/Cobranca/Controller/CasoController.php` → `show()` vira redirect para `cobranca_objeto_show`
- Test: `app/tests/Cobranca/Functional/ObjetoShowControllerTest.php`

**Interfaces (Consumes):** Fatia 1 (`MontarDetalheObjetoUseCase`, `casoAncoraDoObjeto`).
**Produces:** rota `cobranca_objeto_show` = `/cobrancas/objetos/{id}` (GET, `id => \d+`).

- [ ] **Step 1: teste functional falhando** — `ObjetoShowControllerTest`: (a) GET `objeto_show` de objeto do tenant → 200, contém identificação + as abas (Obrigações/Pagamentos/Acordos/Documentos/Histórico) + aba "Pessoas" + nomes dos vínculos; (b) **cross-tenant** → 404; (c) GET `caso_show` do caso correspondente → redirect 302 para `objeto_show`. Login super-admin de teste, tenant-scoped (ver `JudicializacaoCobrancaIsolamentoTenantTest` como referência de padrão IDOR).
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: extrair `_corpo_cobranca.html.twig`** — mover de `caso/show.html.twig` o cabeçalho operacional (saldo/próxima ação/alertas, linhas ~40–110) + as abas (~113 em diante) + os modais de mutação; parametrizar pelo que já recebe (`caso`, `forms`, `casoId`, `secoes`, `arquivosFm`, flags). `caso/show.html.twig` passa a `{% include %}` o partial (comportamento idêntico — smoke garante).
- [ ] **Step 4: criar `objeto/show.html.twig`** — cabeçalho do objeto (identificação + descrição; sem ícone/rótulo "caso") + bloco "Pessoa cobrada atual" em destaque (reusa markup; estado vazio "Nenhuma pessoa cobrada · [definir]" quando `temCobradaAtual === false`) + aba "Pessoas" (lista `vinculos`, destaca cobrada) + `include _corpo_cobranca`.
- [ ] **Step 5: criar `ObjetoController::show`** — `findOneByIdDoTenant` no objeto → `casoAncoraDoObjeto` → monta `ObjetoDetalheOutput` → gates de permissão/forms **iguais** aos do `CasoController::show` (gerenciar/movimentar/pastas) → render. Reusar os helpers de forms (extrair para um serviço/trait se necessário p/ não duplicar).
- [ ] **Step 6: `CasoController::show` vira redirect** — resolve o objeto do caso e `redirectToRoute('cobranca_objeto_show', {id: objetoId})`.
- [ ] **Step 7: rodar functional e ver passar.**
- [ ] **Step 8: SMOKE VISUAL** — subir dev, abrir um objeto real (tenant 1, TOPLIFE), conferir abas + cobrada + Pessoas; abrir uma URL antiga de caso e ver o redirect. **MOSTRAR ao humano.**
- [ ] **Step 9 (após aval):** suíte + `/review` + corrigir + **commit** `Cobrancas: pagina unificada do objeto (leitura) + partial do corpo`.

---

## Fatia 3 — Criar objeto cria o caso âncora (com o nome do cobrado)

**Objetivo:** a criação do objeto pede **identificação + nome do cobrado** e, na mesma transação, cria `Pessoa` (só nome) + `ObjetoCobranca` + `CasoCobranca` âncora + `VinculoPessoaObjeto`. "Abrir caso" some da UI; criar objeto leva para a página do objeto. **Sem migração** (decisão 2026-07-13: caso sempre nasce com pessoa; `pessoaCobradaAtual` segue `nullable: false`).

**Files:**
- Modify: `app/src/Cobranca/UseCase/CriarObjetoUseCase.php` (orquestra Pessoa+Objeto+Caso+Vínculo numa transação; reusa `CriarPessoaUseCase` + `AbrirCasoUseCase`)
- Modify: DTO/Form de criação do objeto (`CriarObjetoInput` + `CriarObjetoType`) — novo campo `nomeCobrado` obrigatório
- Modify: controller de `cobranca_objeto_criar` → redireciona p/ `cobranca_objeto_show`; remover botão/modais "Abrir caso" dos templates da carteira
- Test: `app/tests/Cobranca/Functional/` (criação cria pessoa+caso+vínculo; nome obrigatório)

**Sub-decisão a resolver aqui:** nome já existente → criar nova (recomendado) ou autocomplete busca-ou-cria.

**Interfaces (Consumes):** Fatia 1/2.
- [ ] **Step 1: teste falhando** — criar objeto com `nomeCobrado='Fulano'` → existe 1 `CasoCobranca` ativo com `pessoaCobradaAtual.nome === 'Fulano'`, 1 `VinculoPessoaObjeto`, honorários herdados; nome vazio → erro de validação.
- [ ] **Step 2: ver falhar.**
- [ ] **Step 3:** `CriarObjetoInput`/`CriarObjetoType` ganham `nomeCobrado` (obrigatório).
- [ ] **Step 4:** `CriarObjetoUseCase` cria Pessoa(nome)+Objeto+Caso+Vínculo na mesma transação/flush.
- [ ] **Step 5:** controller redireciona p/ `objeto_show`; remover "Abrir caso" da UI da carteira (`carteira/show.html.twig` + `_acoes_modais` + JS de contexto).
- [ ] **Step 6:** rodar e ver passar.
- [ ] **Step 7: SMOKE VISUAL** — criar objeto novo com um nome, cair na página do objeto já com a pessoa cobrada. **MOSTRAR.**
- [ ] **Step 8 (após aval):** suíte + `/review` + commit `Cobrancas: criar objeto ja cria a cobranca (nome do cobrado)`.

---

## Fatia 4 — Aba Pessoas (escrita): vincular busca-ou-cria + trocar/encerrar

**Objetivo:** dentro da página, vincular pessoa (autocomplete busca existente por nome/CPF/CNPJ, ou cria nova), trocar cobrada, encerrar vínculo.

**Files:**
- Modify: `ObjetoController` (ou novo endpoint) — autocomplete GET tenant-scoped de `Pessoa`
- Modify: `app/src/Cobranca/Repository/PessoaRepository.php` — busca por nome/CPF/CNPJ tenant-scoped
- Modify: `objeto/show.html.twig` — modal "Vincular pessoa" (autocomplete + "criar nova"); botão trocar (reusa `cobranca_caso_alterar_pessoa`); encerrar vínculo (`cobranca_vinculo_encerrar`)
- Reuso: `VincularPessoaAObjetoUseCase`, `CriarPessoaUseCase`
- Test: functional (busca-existente, cria-nova, trocar cobrada, isolamento tenant no autocomplete)

- [ ] **Step 1:** functional falhando (vincular existente; criar nova; autocomplete cross-tenant retorna vazio; trocar cobrada reflete no cabeçalho).
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** endpoint autocomplete + método de repo (tenant-scoped, limita resultados).
- [ ] **Step 4:** modal + JS de autocomplete; ação vincular (existente OU cria-nova numa transação).
- [ ] **Step 5:** ligar trocar/encerrar às rotas existentes; redirects p/ `objeto_show`.
- [ ] **Step 6:** ver passar.
- [ ] **Step 7: SMOKE VISUAL** (buscar, vincular, criar nova, trocar cobrada). **MOSTRAR.**
- [ ] **Step 8 (após aval):** suíte + `/review` + commit `Cobrancas: aba Pessoas do objeto (vincular busca-ou-cria, trocar cobrada)`.

---

## Fatia 5 — Redirects das mutações apontam para o objeto

**Objetivo:** toda ação de mutação passa a voltar para `cobranca_objeto_show`.

**Files:** `CasoController` (encerrar/alterarPessoa/judicializar/registrarTentativa), `ObrigacaoController`, `PagamentoController`, `AcordoController`, `DocumentoCobrancaController`, `LiquidacaoController`, `AcaoCobrancaController`, `CobrancaSecao`* — trocar `redirectToRoute('cobranca_caso_show', …)` por resolver o objeto do caso e `redirectToRoute('cobranca_objeto_show', …)`. Helper compartilhado p/ resolver objetoId do caso.
- [ ] **Step 1:** functional falhando (uma mutação de cada controller → assert redirect p/ objeto).
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** helper `objetoIdDoCaso` + trocar os redirects.
- [ ] **Step 4:** ver passar.
- [ ] **Step 5: SMOKE VISUAL** (registrar pagamento/tentativa e cair no objeto). **MOSTRAR.**
- [ ] **Step 6 (após aval):** suíte + `/review` + commit `Cobrancas: mutacoes redirecionam para a pagina do objeto`.

---

## Fatia 6 — Carteira vira grid de cards de objeto

**Objetivo:** substituir os dois blocos ("Casos da carteira" + "Objetos da carteira") por um grid de cards de objeto (identificação, cobrada, estado, saldo, envolvidos), clicável → `objeto_show`.

**Files:** `CarteiraController::show` (montar objetos com caso resolvido: saldo/cobrada/estado via primitivas em lote — anti-N+1), `carteira/show.html.twig`, DTO de card se necessário. Métrica "Casos" do topo → remover/renomear.
- [ ] **Step 1:** functional falhando (carteira mostra card do objeto com cobrada+saldo+estado; link p/ objeto; não mostra mais tabela "Casos").
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** montar lista de objetos com dados derivados em lote (reusar `saldosDosCasos`/`alertasDosCasos`; **medir N+1 no profiler**).
- [ ] **Step 4:** template grid de cards; remover os dois blocos antigos.
- [ ] **Step 5:** ver passar.
- [ ] **Step 6: SMOKE VISUAL + checagem de N+1** no profiler (não regredir). **MOSTRAR.**
- [ ] **Step 7 (após aval):** suíte + `/review` + commit `Cobrancas: carteira vira grid de cards de objeto`.

---

## Fatia 7 — Copy + menu (remover "Casos") + limpeza

**Objetivo:** sumir a palavra "caso" da UI; **remover o item "Casos" do menu** (decisão G); remover `caso/show.html.twig` órfão e (se seguro) a rota `caso_index`/template.

**Files:** template de menu/nav de Cobranças, `caso/show.html.twig` (remover após validado), `CasoController::index`/`caso/index.html.twig` (tirar do menu; remover na limpeza), textos ("encerrar caso"→"encerrar cobrança", etc.).
- [ ] **Step 1:** functional falhando (menu não tem link "Casos"; textos de "caso" trocados nas telas tocadas).
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** remover link do menu; ajustar copy; remover `caso/show.html.twig` (já coberto pelo partial+objeto).
- [ ] **Step 4:** ver passar.
- [ ] **Step 5: SMOKE VISUAL** (menu sem "Casos"; navegação Carteira→Objeto). **MOSTRAR.**
- [ ] **Step 6 (após aval):** suíte + `/review` + commit `Cobrancas: remover Casos do menu e limpar copy do caso`.

---

## Self-review (cobertura da spec)

- §A página canônica → Fatia 2 ✔ · §B partial → Fatia 2 ✔ · §C aba Pessoas → Fatias 2(leitura)+4(escrita) ✔ · §D criação cria caso → Fatia 3 ✔ · §E cards de objeto → Fatia 6 ✔ · §F redirects + copy → Fatias 5+7 ✔ · §G remover menu Casos → Fatia 7 ✔.
- Invariantes (zero migração, tenant, N+1, caso preservado, caso_show redirect) → nas Global Constraints e verificadas nas fatias 2/6.
- Fora do escopo (Modo Múltiplo no form, fusão real do modelo, remover caso_index rota) → follow-ups anotados na spec.
