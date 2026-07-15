# Ajustes de Cobranças — Backlog desta rodada

> Rodada de ajustes no módulo **já em produção** (bluejus.com.br). Fluxo combinado:
> **registrar → discutir um a um (analisar código real + sugerir melhoria) → planejar → implementar → testar → revisar → corrigir**.
> Nada de código antes de fechar a ideia de cada item. Fonte de verdade desta rodada.
>
> Base: branch `gestao-cobrancas` == `master` (HEAD `61a1450`, tudo em prod).
> Sessão iniciada em: **2026-07-13**.

## Legenda de status
`📝 registrado` → `💬 em discussão` → `✅ ideia fechada` → `📐 planejado (spec)` → `🔨 implementado` → `🔍 revisado` → `🚢 pronto p/ deploy`

## ⚠️ Cadência (acordada com o humano em 2026-07-13)
Por item: **implementar → MOSTRAR o resultado (smoke visual quando dá) → o humano APROVA → SÓ ENTÃO suíte + `/review` + corrigir + commit atômico → próximo item.** Não rodar a suíte completa nem o `/review` antes do humano aprovar o resultado visual do item.

**Andamento (2026-07-14):** Item 1 ✅ commitado (`854cade`,`35d8d12`). Item 4 ✅ commitado (`ea4f86a`). **Item 2 (objeto=caso) 🔨 EM ANDAMENTO — o humano ANTECIPOU:** Fatias 1,2,3,4,6 commitadas (`fe536eb`/`be936a6`/`8118137`/`3522495`/`74904f0`); faltam Fatias 5 (redirects das mutações + `caso_show`→redirect + testes) e 7 (menu "Casos" + limpeza). Itens 3/5/6/7/8 = ideias fechadas, não iniciados. Estado detalhado em `SESSION_HANDOFF.md` + `PLANO_AJUSTE2_OBJETO_UNIFICADO.md`.

---

## 1. Tooltips + formatação do % no formulário de criação da Carteira — `🔍 revisado` (commitado)
> Implementado: `PercentualType` + `PercentualParaTextoTransformer` (vírgula pt-BR ⇄ ponto); `descricao()` nos enums `ModoCarteira`/`FormaHonorarios`; partial `carteira/_campos_config.html.twig` (popover `?` + input-group `%`) usado nos modais criar/editar; `cobranca-carteira-form.js` (init popover + toggle/disable percentual em "sem percentual"); `CarteiraController::ajudaDosCampos()`. Testes: `CadastroCarteiraControllerTest` +2 (criar/editar com vírgula). `tests/Cobranca` 411/411. Revisão `feature-review-agent`: aprovado, 3 achados menores CORRIGIDOS (disable do %; teste editar; `form_errors` global).

**Pedido:** acrescentar tooltips de informação sobre as opções do formulário de criação da carteira (modos de operação, formas de honorários). Melhorar a formatação do campo de percentual de honorários.
**Risco:** BAIXO (UI/form, sem regra de negócio nem permissão).

**Onde vive:** modal em `carteira/index.html.twig` (criar) via `CriarCarteiraType`; MESMOS campos no modal "Editar configuração" (`carteira/_acoes_modais.html.twig`) via `EditarConfiguracaoCarteiraType`. Aplicar em AMBOS.

**Ideia final (decidida):**
1. **Tooltip = ícone `?` com POPOVER no hover** (decisão do humano) ao lado dos labels de `modo` e `formaHonorarios`, listando o significado de cada opção. Bootstrap 5 não auto-inicializa popover → init JS ESCOPADO no modal (não mexer no `base.html.twig` global).
2. **Campo de percentual:** input-group com sufixo `%`; **aceitar vírgula (pt-BR)** via normalização/transformer, gravando no formato `decimal(5,2)` da entidade (regex do DTO passa a aceitar vírgula OU ponto); placeholder `10,00`.
3. **Toggle:** ocultar/desabilitar o percentual quando `formaHonorarios = Sem percentual` (usa `FormaHonorarios::exigePercentual()`, que já existe).
4. Replicar 1–3 no modal de Editar configuração.
- *Flag menor (fora do escopo, só anotar):* hoje o DTO permite percentual vazio mesmo quando a forma exige (`exigePercentual()==true`); poderia validar presença — decidir se entra.

## 2. Objeto e Caso viram UMA COISA (na experiência) — `🔨 EM ANDAMENTO (fatias 1,2,3,4,6 commitadas; faltam 5 e 7)` — spec `docs/specs/cobranca-ajuste2-objeto-caso-unificado.md` · plano `PLANO_AJUSTE2_OBJETO_UNIFICADO.md`
**Pedido (esclarecido):** "quero que o objeto e o caso se tornem uma coisa só; abrir o objeto e ver a página igual à página de caso." O objeto tem pessoa e obrigações — a camada "caso" não deve aparecer. Na página da carteira: **cards de objeto** (nome do objeto, pessoa, estado, total da dívida); clicar → página do objeto.
**Risco:** MÉDIO (navegação + camada de leitura + redirects das mutações). NÃO toca o modelo de dados.

**Decisão de arquitetura (fechada):** **esconder o "caso" da interface, mantendo a entidade `CasoCobranca` como âncora técnica invisível, 1 caso por objeto (Modo Único).** Abrir o objeto = ver o conteúdo que hoje é a página do caso. ZERO migração de dados; preserva saldo/honorários/acordos/pagamentos/judicialização/alertas (tudo segue preso ao caso por baixo).

**Premissa confirmada a validar com o humano:** 1 caso ativo por objeto → **Modo Múltiplo aposentado** (se o humano usa múltiplo em carteira real, muda o desenho; se não, dá pra remover a opção no form do item 1).

**Direção de implementação (detalhar na SPEC):**
- Nova rota/página `cobranca_objeto_show` (`/cobrancas/objetos/{id}`) = página canônica; extrair o corpo da `caso/show.html.twig` num partial reutilizável.
- Cabeçalho: objeto (identificação/descrição/rótulo) + pessoas vinculadas (cadastro) + estado + saldo; corpo: abas obrigações/pagamentos/acordos/documentos/histórico.
- Carteira: grid de cards de objeto → `cobranca_objeto_show`. Remover a tabela "Casos da carteira" solta.
- Mutações 8B: passam a redirecionar pro objeto. Manter `cobranca_caso_show` funcionando (deep-link).
- Sub-questões p/ a spec: objeto sem cobrança ainda (iniciar cobrança / de onde vem a "pessoa cobrada"), destino dos redirects, palavra "caso" some dos textos/labels.
- **Foundational:** os itens 5/6/7/8 vivem "dentro" dessa página — considerar a ordem de implementação.

## 3. "Tentativa" = registro de contato (default frustrado) — `🔍 revisado (commitado 6c95985)`
> Implementado: enums `CanalContato`(Telefone/WhatsApp/E-mail/SMS) + `ResultadoContato`(NaoAtendido/CaixaPostal/NumeroErrado/PrometeuPagar/Outro); DTO/Form com `dataContato`(DateTimeType, default agora via `MontadorModaisCaso`)+`canal`(EnumType)+`resultado`(EnumType, default NaoAtendido), sem `valorSolicitado`/`novoPrazo`; UseCase grava evento `ContatoRealizado` (tipo ocioso reusado → sem migração) com `ocorridoEm`=dataContato (novo param opcional aditivo em `RegistrarEventoHistorico::registrar`) e descrição "Contato por {canal} em {data} — {resultado}. {obs}"; copy "Tentativa"→"Registrar contato" (botão/modal) + flash "Contato registrado.". Testes: unit reescrito + functional reforçado (tipo/descrição/ocorridoEm + caso "sem canal"→não grava). `tests/Cobranca` 403/403, global 1716→1717. feature-review LIMPO (2 nits de cobertura CORRIGIDOS antes do commit). Smoke real OK. **SEM migration.**

**Pedido:** a tentativa deve ser um registro de contato — informar dia/hora (pré-preenchido com agora), canal (telefone/WhatsApp/e-mail), tipicamente "não atendido". Remover "valor solicitado" e "novo prazo".
**Risco:** BAIXO (form + DTO + UseCase + enum de evento + template; sem migração; supera SPEC §10 — decisão do humano).

**Ideia final (decidida):**
- **Remover** `valorSolicitado` + `novoPrazo` do DTO/Form.
- **Adicionar:** `dataContato` (DateTimeImmutable, default = agora, editável); `canal` (enum novo `CanalContato`: **Telefone, WhatsApp, E-mail, SMS**); `resultado` (enum novo, opcional, default "Não atendido": **Não atendido / Caixa postal / Número errado / Prometeu pagar / Outro**); manter `observacao`.
- **Evento de histórico:** trocar `BoletoEnviado` por um tipo adequado (novo `RegistroContato`/`TentativaContato`, OU reusar o já-existente-e-ocioso `ContatoRealizado` — decidir na spec; label da timeline coerente). A `dataContato` vira a data do evento na timeline (`ocorridoEm`). Descrição gerada: "Contato por {canal} em {data} — {resultado}. {obs}".
- **Template:** renomear modal/botão ("Tentativa" → ex.: "Registrar contato"), trocar os campos.

## 3. "Tentativa" = registro de contato frustrado (simplificar) — `📝 registrado`
**Pedido:** a tentativa deve ser um registro de contato frustrado — o usuário informa que entrou em contato tal dia/hora (pré-preencher com a data/hora atual da abertura do formulário) por telefone/WhatsApp/e-mail e **não foi atendido**. Remover os campos "valor solicitado" e "novo prazo".
**Risco:** a definir (form + UseCase de tentativa; provável BAIXO/MÉDIO).

## 4. Remover a "Revisão de pessoa cobrada" — POR COMPLETO — `🔨 implementado (não commitado; falta suíte+review+commit)`
> Implementado no working tree (~36 arquivos). Migration `Version20260713120000` (DROP IF EXISTS) **aplicada só no dev**. `TipoEventoHistorico::RevisaoVinculo` PRESERVADO (legado). Smoke no navegador OK. **Falta:** `php -d memory_limit=512M bin/phpunit tests/Cobranca` + global → `/review` → corrigir → commit. Spec: `docs/specs/cobranca-ajuste4-remover-revisao.md`.

**Pedido:** remover o botão de Revisão — não serve para nada na prática. **Decisão: remoção completa** (código + tabela).
**Risco:** MÉDIO (migration destrutiva em prod + toca AlertasCobranca/Dashboard/DetalheCaso e seus testes). Confirmar antes que não há dados reais na tabela `cobranca_revisao_pessoa_cobrada` em prod.

**Escopo da remoção (fechado):**
- **UI:** botão "Revisão" + `modalGerarRevisao` + banner "Revisões pendentes" + `modalResolverRevisao` (caso/show + _acoes_modais); card "Revisões de pessoa cobrada" no dashboard.
- **Alertas/Dashboard/Detalhe:** tirar o alerta "revisão pendente" de `AlertasCobranca`; tirar `revisoesPendentes` de `MontarDashboardCobrancaUseCase` e `MontarDetalheCasoUseCase`; ajustar `TipoAlerta` (remover o caso) e o evento `RevisaoVinculo` de `TipoEventoHistorico` se ficar órfão.
- **Backend a apagar:** `RevisaoCobrancaController`, `GerarRevisaoUseCase`, `ResolverRevisaoUseCase`, `RevisaoPessoaCobrada` (entity), `RevisaoPessoaCobradaRepository`, `StatusRevisao`, `GerarRevisaoType`/`ResolverRevisaoType`, `GerarRevisaoInput`/`ResolverRevisaoInput`/`RevisaoOutput`, `RevisaoJaResolvidaException`/`RevisaoNaoEncontradaException`.
- **Testes a apagar/ajustar:** `GerarRevisaoUseCaseTest`, `ResolverRevisaoUseCaseTest`, `AcaoRevisaoMutacaoControllerTest`; e ajustar `AlertasCobrancaTest`, `MontarDashboardCobrancaUseCaseTest`, `MontarCentralAlertasUseCaseTest`, `CobrancaBatchConsistenciaTest`, `CasoEncerradoBloqueiaMutacaoControllerTest`, `JudicializacaoCobrancaIsolamentoTenantTest` (removem asserts de revisão).
- **Purga:** tirar da `PurgarEscritorioUseCase::ORDEM_DELECAO`.
- **Migration:** `DROP TABLE cobranca_revisao_pessoa_cobrada` (idempotente; down recria). Aplicar em prod no deploy.

## 5. Editar + Excluir Obrigações (correção auditada, com guardas) — `✅ COMMITADO (A `d101244` + B `7b6b471`)` — spec `docs/specs/cobranca-ajuste5-editar-excluir-obrigacao.md`
> **Fatia A — Editar (commitada `d101244`):** ação Editar (corrige descrição/valor/vencimento/referência/encargos, motivo obrigatório) + evento `ObrigacaoEditada` (antes/depois); guards caso encerrado / travada por acordo VIGENTE (`participaDeAcordoVigente`) / valor abaixo do alocado. **"Reconhecer valor" APOSENTADO** (unificado no Editar; enum legado `ValorAtualizadoReconhecido` preservado). **Trava vigente-aware** (corrige bug: obrigação de acordo cancelado voltava bloqueada; parcela de acordo desfeito vira histórico editável — badge "Acordo desfeito" + cadeado explicativo). feature-review LIMPO (2 achados corrigidos).
> **Fatia B — Excluir (commitada `7b6b471`):** ação Excluir (hard delete) com motivo obrigatório + evento `ObrigacaoExcluida` (snapshot); guards caso encerrado / travada por acordo vigente / pagamento alocado (`ObrigacaoComPagamentoException`). Controller CSRF manual (`excluir_obrigacao_{id}`); botão lixeira + modal confirmação. feature-review LIMPO (bug do data-attr da descrição no modal CORRIGIDO + testes do guard-pagamento/substituída/motivo-vazio adicionados). **SEM migration.** `tests/Cobranca` 425/425, global 1737→1740. Smoke real OK (editar objeto 117; acordo cancelado objeto 296; excluir throwaway objeto 297). **ITEM 5 COMPLETO.**

**Pedido:** editar as obrigações (TODOS os campos) + poder EXCLUIR obrigação cadastrada errada.
**Risco:** MÉDIO (nova mutação de escrita mexendo em valor que alimenta saldo derivado; tensão com invariável 20).

**Ideia final (decidida):**
- **Editar (novo `EditarObrigacaoUseCase` + modal):** descrição, valor original, vencimento, referência externa (e encargos — posso unificar no mesmo modal ou manter o "Reconhecer valor" à parte; decidir na spec).
- **Excluir (novo `ExcluirObrigacaoUseCase`):** só com guardas.
- **Guardas (edição E exclusão):** bloquear se for **parcela de acordo** (`acordoOrigem`), **substituída** (`acordoSubstituto`), **caso encerrado**; na edição, não deixar `valorExigivel` cair abaixo do **já pago/alocado** (conecta item 6); na exclusão, só se **sem pagamento alocado**.
- **Auditoria:** registra evento no histórico em cada edição/exclusão.
- **Enquadramento (registrar na spec):** edição = **correção de cadastro** explícita e auditada; a invariável 20 continua valendo para movimentos operacionais (tentativa/pagamento não reescrevem a dívida). Documentar essa distinção.
- **UI:** botão "Editar" + "Excluir" na linha da obrigação (aba Obrigações), ao lado do "Reconhecer valor".

## 6. Registrar pagamento simples (auto-alocação FIFO) + "valor alocado" claro — `🔍 revisado (COMPLETO — fatias 1 `d8d61be` + 2 `4b726e9` + 3 `66a3ece`)` — spec `docs/specs/cobranca-ajuste6-pagamento-fifo.md`
> **Fatia 1 (`d8d61be`):** serviço read-only `AutoAlocadorFifo` (`derivar` não-lança p/ prévia + `alocar` lança p/ escrita) + `PagamentoExcedeSaldoException` + DTOs `PreviaAlocacaoFifo`/`LinhaAlocacaoFifo`. Teto = `saldoExigivel` derivado (idêntico a `CalculadoraSaldo`, sem `max(0)` por obrigação — senão super-alocação inflaria o teto e D1 liberaria saldo negativo; furo pego na revisão); distribuição com clamp defensivo + guard `salaTotal` (Σ linhas == valorDivida quando cabe). feature-review 2 IMPORTANTES + 1 MENOR fechados.
> **Fatia 2 (`4b726e9`):** FIFO vira PADRÃO nos UseCases Registrar/Corrigir (ramo auto/manual); DTOs ganham `alocarManualmente` + `Assert\Callback` condicional; Forms ganham checkbox + rótulo "Valor alocado"→"Valor nesta obrigação"; controller captura `PagamentoExcedeSaldoException`. **Na correção, alocar ANTES de limparAlocacoes/flush** (a query de saldo vê o próprio pagamento, que o FIFO exclui da sala). feature-review: 2 lacunas de cobertura fechadas (correção-auto em BANCO REAL + manual-sem-linhas barra).
> **Fatia 3 (`66a3ece`):** UI — 2 endpoints GET de prévia (JSON, fonte única de centavos, gate+IDOR+read-only) + JS ao vivo (dívida/honorários + quebra FIFO, desabilita submit em excede) + checkbox manual (bloco escondido por padrão) + help text. **Fix de bug pré-existente** (padrão de modal reutilizável com `action` nula → POST na própria página só-GET = 405): guard de submit nos 6 modais reutilizáveis. **XSS (BLOQUEANTE da revisão) corrigido**: render por DOM/`textContent` (descrição da obrigação nunca em innerHTML), provado inerte no navegador. Smoke real OK. **SEM migration.** `tests/Cobranca` 449/449, global 1764/1764.

**Pedido:** "valor alocado" não ficou claro; registrar pagamento está muito complicado.
**Risco:** MÉDIO (mexe no fluxo financeiro; alocação alimenta saldo/honorários derivados).

**Diagnóstico:** o [AlocadorPagamento](app/src/Cobranca/Service/AlocadorPagamento.php) exige que a Σ das alocações feche com a **parte-dívida** (derivada), NÃO com o valor pago. Quando honorários são "acrescidos à dívida", esse alvo é invisível → `PagamentoInconsistenteException` impossível de acertar às cegas. Além do tédio de alocar manualmente linha a linha.

**Ideia final (decidida):**
- **Auto-alocação por padrão = FIFO** (mais antiga/vencida primeiro) — implementa o follow-up #8. Novo serviço/estratégia de alocação automática que preenche as alocações somando exatamente a parte-dívida.
- **Alocação manual mantida como opção** (toggle "alocar manualmente" ‹avançado›). Auto é o default.
- **Mostrar a divisão derivada ao vivo:** ao digitar o valor pago, exibir "dívida: R$X · honorários: R$Y" (endpoint/JS) — mata a armadilha do alvo invisível.
- **Aplicar o mesmo** ao "Corrigir pagamento" (mesma coleção de alocações).
- **Terminologia:** rever o rótulo "valor alocado" + help text no fluxo manual.
- *Guarda:* auto-alocação FIFO precisa lidar com resto de arredondamento em centavos (última obrigação absorve a sobra), como o rateio de honorários já faz.

## 7. Formulário de acordo inteligente + abrir/editar acordo — `🔍 revisado (COMPLETO — 4 fatias)` — spec `docs/specs/cobranca-ajuste7-acordo-inteligente.md`
> **✅ Fatia 3 (`a2be47f`)** abrir acordo: `GET /cobrancas/acordos/{id}` (`cobranca_acordo_show`, gate só de MÓDULO, 404 anti-IDOR) + `MontarDetalheAcordoUseCase` + `AcordoDetalheOutput`/`ParcelaAcordoResumoOutput`/`ObrigacaoSubstituidaResumoOutput` + template + link "Abrir acordo" na aba. Resumo (total/entrada do snapshot, desconto **ou juros** derivado), parcelas com o pago (Quitada/Parcial/Em aberto, alocado em UMA query em lote) e substituídas. **`#[ORM\OrderBy]` nas coleções do `Acordo`** (achado da revisão: sem ele o Postgres devolve na ordem da heap e um UPDATE embaralha as parcelas — a Fatia 4 seria o gatilho); sem migration. feature-review: 1 IMPORTANTE (o "Pago" não era provado — teste com Pagamento+Alocação REAIS, provado por mutação) + N+1 latente no re-acordo + `temJuros` sem substituídas, todos corrigidos.
> **✅ Fatia 4 (`bb10f90`)** editar acordo: `EditarAcordoUseCase` (**valida tudo antes de escrever**) + `EditarAcordoInput`/`ParcelaEdicaoInput` + Forms + `POST cobranca_acordo_editar` + barra de ações no detalhe (Editar/Cumprir/Romper/Cancelar, `action` fixa) + editor com redistribuição ao vivo. Guardas: INV-C (parcela paga congelada) · **invariável 14 (parcela já substituída por OUTRO acordo vigente congelada)** · só Ativo (INV-D) · caso encerrado (INV-H) · substituídas intocadas (INV-E) · id estranho/repetido recusado · INV-B (`Σ parcelas == total`, D8). Evento `AcordoEditado` com antes/depois + retrato das removidas. **As travas valem só para a linha que MUDOU** (a UI reenvia todas); linhas travadas ficam `readOnly` (**não** `disabled` — disabled não é submetido e recriaria o beco). `tests/Cobranca` **516/516**.
> **🔴 BLOQUEANTE pego pela revisão (e corrigido):** o editor apagava fisicamente uma parcela de A que um acordo B vigente guardava como dívida original → se B fosse rompido, a dívida sumia em silêncio. Caminho alcançável **só pela UI**. Ao corrigir, foram introduzidas (e pegas na re-revisão) 2 regressões: `ObrigacaoDeAcordoException` fora do `catch` (→ 500) e guard antes do `linhaMudouParcela` (→ acordo ineditável para sempre). Tudo fechado e **provado por mutação**. **Lição:** guard novo exige teste FUNCTIONAL do caminho HTTP, não só unit.
> **⏳ Follow-up aberto (decisão do humano, FORA do item 7):** "acordo sobre acordo" na **criação** — `doCasoExigiveis` oferece parcelas de acordo vigente e `CriarAcordoUseCase` nunca olha `acordoOrigem`. Se o fluxo não existir no produto, o ajuste é na criação (código já em prod). Detalhe na spec §13.
> **Decisões fechadas com o humano:** D1 migração aditiva (snapshot `valor_total_negociado`+`valor_entrada`, NÃO-autoritativo p/ saldo) · D2 editar = editor completo das parcelas não-pagas · D3 sobra de centavos na 1ª parcela · D4 entrada = 1ª obrigação-parcela · D5 desconto/juros derivado · D6 detalhe = página dedicada `GET /cobrancas/acordos/{id}` · D7 honorários §18 sem tratamento especial (rateio segue no pagamento).
> **✅ Fatia 1 (`361cb53`)** modelo + gerador backend: serviço puro `GeradorParcelamento` (centavos, sobra na 1ª) + enum `Periodicidade` (mensal/quinzenal/semanal, clamp de fim de mês) + `ParcelamentoInvalidoException` + DTO `LinhaParcelaGerada`; migration aditiva `Version20260714130000` (aplicada dev+test); `Acordo` +2 campos; `CriarAcordoInput` +callback INV-B; `CriarAcordoUseCase` grava snapshot + cria entrada como 1ª obrigação + revalida INV-B. Retrocompatível (modal antigo deriva o total). Unit 27/27. feature-review LIMPO (1 NIT do evento de histórico corrigido).
> **✅ Fatia 2 (`2c3ed78`)** gerador na UI de criar: `#modalCriarAcordo` com painel (total auto da seleção via `data-valor-centavos` + total negociável + entrada + qtd/1º venc/periodicidade → **Gerar**), endpoint `GET cobranca_acordo_previa_parcelamento` (fonte única de centavos, gate `gerenciar`, sem CSRF, não toca banco), JS ao vivo (soma seleção, redistribui não-fixadas ao editar, indicador de fechamento desabilita submit se entrada+parcelas≠total). Servidor revalida INV-B. **Smoke real OK** (objeto 107, sem persistir); **bug pego no smoke e corrigido**: `parseCentavos` é pt-BR (ponto=milhar) → valores gerados passaram a ser escritos com vírgula. Functional +6 (19/19). feature-review LIMPO (3 NITs corrigidos: attr do CentavosType, overflow de data no endpoint, catch da ParcelamentoInvalidoException). `tests/Cobranca` 475/475.
> **⏳ FALTAM:** **Fatia 3** abrir acordo (detalhe read-only `cobranca_acordo_show` + `MontarDetalheAcordoUseCase` + `AcordoDetalheOutput` + template + link "abrir acordo" na aba) · **Fatia 4** editar acordo (`EditarAcordoUseCase` diff+guardas INV-C/D/E/H + evento `AcordoEditado` + form + rota + UI no detalhe). Item **8** (parcelas na aba) depende da Fatia 3.

**Pedido:** parcelamento automático (escolher qtd de parcelas; sistema soma e divide); editar qualquer parcela com recálculo ao vivo das demais (centavos quebrados / preferência); abrir o acordo p/ ver detalhe e editar.
**Risco:** MÉDIO/ALTO (regra financeira, aritmética de centavos, edição de acordo existente com possíveis pagamentos). **Merece SPEC dedicada.**

**Ideia final (decidida):**
- **Gerador de parcelamento:** seleciona obrigações → total automático; **total negociável** (desconto/juros ajusta o total) + **entrada opcional**; escolhe **qtd de parcelas** + **data da 1ª** + **periodicidade configurável (mensal/quinzenal/semanal)** → gera parcelas: valor dividido igualmente (sobra de centavos na 1ª ou última), descrição "Parcela k/n", vencimentos em sequência (editáveis um a um).
- **Recálculo ao vivo (JS):** sobrescrever o valor de qualquer parcela **fixa** ela; as parcelas **não-fixadas** redistribuem o restante pra fechar o total (centavo sempre bate). Servidor **revalida** Σ parcelas == total no submit.
- **Abrir acordo:** nova tela/painel de **detalhe do acordo** (parcelas, status, obrigações substituídas, entrada, total, desconto/juros).
- **Editar acordo (novo `EditarAcordoUseCase`):** edita parcelas **ainda não pagas**; guardas: parcela com pagamento alocado não muda; acordo rompido/cancelado é congelado. Auditado.
- **Modelo de dados:** hoje o Acordo não tem colunas de total/entrada/desconto (total é derivado das parcelas). Avaliar na spec se precisa persistir entrada/desconto/total negociado (provável: sim, p/ o detalhe e a edição) → **migration** (aditiva). Parcelas continuam sendo obrigações com `acordoOrigem`.
- **Conecta item 8** (visualizar parcelas na aba Obrigações).

## 8. Visualizar parcelas do acordo na aba Obrigações (dropdown + link) — `✅ ideia fechada`
**Pedido:** quando um acordo é criado, as parcelas aparecem soltas na aba Obrigações; quero visualizar melhor.
**Risco:** BAIXO (agrupamento/leitura na UI).

**Ideia final (decidida):** na aba Obrigações, o acordo vira **uma linha-resumo** ("Acordo {data} · N parcelas · R$ {total}") com **dropdown/accordion inline** que expande as parcelas ali mesmo + **link "abrir acordo"** para a tela de detalhe do item 7 (onde edita). As obrigações substituídas somem da lista principal (ficam no detalhe do acordo). Reusa o detalhe do item 7.

---

## Ordem de implementação proposta (dependências)
> Regra: cada item MÉDIO/ALTO ganha **spec** em `docs/specs/` antes de implementar; BAIXO a descrição acima basta. Ciclo por item: investigar → spec (se MÉDIO+) → implementar → testar → `/review` → corrigir → conferir.

1. **Item 1** (form da carteira) — BAIXO, independente, aquecimento.
2. **Item 4** (remover Revisão por completo) — MÉDIO; feito cedo porque **limpa** alertas/dashboard/detalhe/caso que os itens 2 e 5–8 vão remexer (reduz superfície e conflito).
3. **Item 3** (tentativa = contato) — BAIXO, ação isolada do caso.
4. **Item 5** (editar/excluir obrigação) — MÉDIO, aba Obrigações.
5. **Item 6** (pagamento FIFO) — MÉDIO, financeiro do caso.
6. **Item 7** (acordo inteligente + editar) — MÉDIO/ALTO, SPEC dedicada; maior item.
7. **Item 8** (parcelas na aba) — BAIXO, depende do detalhe do acordo do item 7.
8. **Item 2** (objeto = caso; cards de objeto) — MÉDIO, reestrutura a navegação por último, "embrulhando" a página de caso já melhorada pelos itens 3–8. **Premissa a validar: Modo Único (1 caso/objeto).**

_Racional:_ 2 é foundational mas em grande parte **relocaliza** o corpo da página do caso; os itens 3–8 melhoram esse corpo. Fazer 3–8 na página estável e depois 2 "embrulhar" reduz retrabalho. Alternativa: 2 primeiro (estabelece a página do objeto) — decisão do humano.

## 8. Visualizar parcelas do acordo na aba Obrigações (modal/dropdown) — `📝 registrado`
**Pedido:** quando um acordo é criado, as parcelas aparecem na aba Obrigações; quero abrir um modal (ou dropdown) para visualizar melhor as parcelas do acordo.
**Risco:** a definir (provável BAIXO — leitura/agrupamento na UI).

---

## Notas de dependência (primeira leitura)
- **7 e 8** são a mesma frente (Acordo): parcelamento + visualização. Discutir juntos, implementar em sequência.
- **3 e 4** mexem em ações do Caso (tentativa/revisão) — mesma área de UI (modais de ação do caso).
- **2** é reorganização da tela `show` da carteira; pode conviver com 5/6/8 que mexem nas abas do Caso.
