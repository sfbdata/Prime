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

## 3. "Tentativa" = registro de contato (default frustrado) — `✅ ideia fechada`
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

## 5. Editar + Excluir Obrigações (correção auditada, com guardas) — `✅ ideia fechada`
**Pedido:** editar as obrigações (TODOS os campos) + poder EXCLUIR obrigação cadastrada errada.
**Risco:** MÉDIO (nova mutação de escrita mexendo em valor que alimenta saldo derivado; tensão com invariável 20).

**Ideia final (decidida):**
- **Editar (novo `EditarObrigacaoUseCase` + modal):** descrição, valor original, vencimento, referência externa (e encargos — posso unificar no mesmo modal ou manter o "Reconhecer valor" à parte; decidir na spec).
- **Excluir (novo `ExcluirObrigacaoUseCase`):** só com guardas.
- **Guardas (edição E exclusão):** bloquear se for **parcela de acordo** (`acordoOrigem`), **substituída** (`acordoSubstituto`), **caso encerrado**; na edição, não deixar `valorExigivel` cair abaixo do **já pago/alocado** (conecta item 6); na exclusão, só se **sem pagamento alocado**.
- **Auditoria:** registra evento no histórico em cada edição/exclusão.
- **Enquadramento (registrar na spec):** edição = **correção de cadastro** explícita e auditada; a invariável 20 continua valendo para movimentos operacionais (tentativa/pagamento não reescrevem a dívida). Documentar essa distinção.
- **UI:** botão "Editar" + "Excluir" na linha da obrigação (aba Obrigações), ao lado do "Reconhecer valor".

## 6. Registrar pagamento simples (auto-alocação FIFO) + "valor alocado" claro — `✅ ideia fechada`
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

## 7. Formulário de acordo inteligente + abrir/editar acordo — `✅ ideia fechada` (SPEC própria)
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
