# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-10 — **Etapa 8 Onda 8B PARCIAL: fundação + 3 fatias de mutações caso-level (obrigações/encerrar, ação/tentativa/revisão, acordo) CONCLUÍDAS, testadas e revisadas.** Faltam 2 fatias: **8B-D financeiro** e **8B-E cadastro+seleção**.

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `30e4cf4` (correções da revisão). Cadeia da 8B sobre a Onda 8A (`b0d2786`).
- **Etapa:** 8 → **Onda 8B EM ANDAMENTO**. Fatias 8B-0/A/B/C ✅. Próxima = **8B-D (financeiro)**, depois **8B-E (cadastro+seleção)**; depois **8C** (importação visual + file-manager) e **Etapa 9**.
- **Suíte:** `tests/Cobranca` **288/288**; GLOBAL **1569/1569** (medido no HEAD `30e4cf4`).
- **Working tree:** limpo (untracked só `.claude/worktrees/` e os `.xlsx` TOPLIFE gitignorados).
- **Escritor:** ÚNICO (orquestrador). **Fan-out foi AVALIADO e RECUSADO** para o cluster do Caso: todas as mutações caso-aninhadas editam o template compartilhado `caso/show.html.twig` + `caso/_acoes_modais.html.twig` → não são file-independentes. Decisão registrada na spec §Decomposição.
- **Migrations:** nenhuma na Etapa 8 (só camada HTTP).

## Commits desta sessão (Onda 8B, sobre `b0d2786`)
- `29e7a8e` — **8B-0 Fundação**: trait `AutorizacaoCobranca` (gate módulo + capacidade via `hasPermission`), `CentavosType`+`CentavosParaReaisTransformer` (int centavos ↔ reais pt-BR, aritmética inteira, unit-testado), refactor dos 2 controllers 8A p/ o trait, **fix de flake latente do teste 8A** (asserção contra HTML escapado), spec `docs/specs/cobranca-etapa8b-mutacoes.md`.
- `4973dac` — **8B-A**: registrar obrigação, reconhecer valor (modal reutilizável), encerrar caso. `ObrigacaoController`+`CasoController::encerrar`. Base de teste `CobrancaWebTestCase`.
- `c190d04` — **8B-B**: definir/concluir próxima ação (`AcaoCobrancaController`), registrar tentativa (`CasoController`), gerar/resolver revisão (`RevisaoCobrancaController`).
- `e718a4d` — **8B-C**: criar acordo (multiselect obrigações escopado + coleção de parcelas), romper/cancelar (modais reutilizáveis), cumprir (CSRF manual). `AcordoController`. Flag `AcordoOutput.ativo` (aditivo).
- `30e4cf4` — **correções da revisão** (sem bloqueantes): `formulariosDeMutacao` só monta forms se `hasPermission('resources.cobranca.gerenciar')`; matriz de teste de cancelar acordo fechada (sem-cap/IDOR/CSRF).

## Padrão ESTABELECIDO da 8B (replicar em 8B-D/E — NÃO reinventar)
Toda mutação, na ordem: **1) gate** `tenantComCapacidade('resources.cobranca.gerenciar' | 'resources.carteira.gerenciar' | 'resources.cobranca.movimentacao_financeira')` → null = `semAcesso()`; **2) resolver entidade** `findOneByIdDoTenant($id,$tenant)` → null = `createNotFoundException` (404 anti-IDOR), **antes** do CSRF; **3) CSRF** (Symfony Form automático; ou `isCsrfTokenValid('nome_'.$id)` manual em ação sem campos); **4) mutação** `try { $useCase->executar($input,$tenant,$user) } catch (<domínio>) { addFlash danger }`, sucesso → `addFlash success`; **5) PRG sempre** `redirectToRoute('cobranca_caso_show', ...)`. Controller fino; UseCases fazem flush internamente (NÃO chamar flush).
- **Forms**: `data_class = Input DTO`; dinheiro = `CentavosType`; datas = `DateType single_text input:datetime_immutable`; enums = `EnumType`; coleção = `CollectionType` (ver acordo/parcelas). `casoId`/`obrigacaoId`/etc. vêm da ROTA (setados no controller, não são campos).
- **Modais**: em `caso/_acoes_modais.html.twig` (só sob `has_permission`). Ação caso-level = 1 modal com Form. Ação **por-item** = 1 modal reutilizável + JS que injeta `form.action` via `data-acao-url` (ver reconhecer/resolver/romper/cancelar). Views criadas em `CasoController::formulariosDeMutacao($caso)` (gated por capacidade).
- **Choices escopadas ao caso** (ex.: obrigações do acordo): helper estático no Form (`AcordoCriarType::opcoesObrigacoes(...)`), chamado IDÊNTICO no render (show) e no POST, senão o ChoiceType reprova. **Reusar esse padrão na alocação de pagamento (8B-D).**
- **Testes**: `CobrancaWebTestCase` (base) → `criarAdminLogado` (isSystem = bypass de capacidade), `criarOperadorSemCapacidade` (não-system, só `modules.cobrancas.view` → prova negação de capacidade), `semearGrafo($tenant, $overridesCaso)`, `tenantAvulso()`, `tokenDoFormulario($crawler, 'nome_do_form')`. Por mutação: happy + capacidade-negada + IDOR 404 + CSRF inválido + erro de domínio relevante.

## PRÓXIMA AÇÃO EXATA — 8B-D (Financeiro)
Ligar: **Registrar pagamento** (`RegistrarPagamentoUseCase`, cap `resources.cobranca.movimentacao_financeira`) · **Corrigir pagamento** (`CorrigirPagamentoUseCase`) · **Registrar liquidação** (`RegistrarLiquidacaoUseCase`).
- **Pagamento** = o mais complexo: `RegistrarPagamentoInput` tem `alocacoes[]` (`AlocacaoPagamentoInput{obrigacaoId,valor}`) → `CollectionType` de um `AlocacaoPagamentoType`. O `obrigacaoId` de cada alocação deve ser escopado às obrigações exigíveis do caso — **reusar o padrão `opcoesObrigacoes` do acordo** (ChoiceType com choices idênticas no render e no POST). Alocação MANUAL explícita (FIFO é follow-up #8, NÃO fazer). Botões na aba "Pagamentos & Liquidações".
- **Corrigir pagamento** = por-item (modal reutilizável), `CorrigirPagamentoInput{pagamentoId,data?,valorPago,alocacoes[],motivoCorrecao(NotBlank)}`. Resolver `Pagamento` por `findOneByIdDoTenant`; redirect via `$pagamento->getCaso()->getId()` (confirmar getter). Correção SEM estorno.
- **Liquidação** = simples (SEM coleção): `RegistrarLiquidacaoType` com `tipo` (EnumType `TipoLiquidacao`, NotNull), `descricaoBem`, `valorAtribuidoBem?`, `valorReconhecido` (CentavosType), `data`. Só formas NÃO monetárias (dinheiro é Pagamento — follow-up #7). Botão na mesma aba.
- Contratos exatos (campos/exceptions) no relatório de investigação já feito — reconferir os Input DTOs em `src/Cobranca/DTO/`. `PagamentoOutput`/`LiquidacaoOutput` já têm o que a UI precisa (id etc.) — conferir se `PagamentoOutput` tem `id` p/ o botão de corrigir; se faltar, adicionar (aditivo).

## Depois — 8B-E (Cadastro + seleção)
CRUD Carteira (`CriarCarteira` cap `carteira.gerenciar` / `EditarConfiguracaoCarteira`) · Objeto (`CriarObjeto`) · Pessoa (`CriarPessoa`) · Vínculo (`Vincular`/`EncerrarVinculo`) · Abrir caso (`AbrirCaso`) · Alterar pessoa cobrada (`AlterarPessoaCobrada`) · Judicializar (`JudicializarCaso`, gate ADICIONAL `pastas` p/ escolher a Pasta). Templates: `carteira/index` (nova carteira) e `carteira/show` (editar config, novo objeto, abrir caso); alterar-pessoa/judicializar em `caso/show`. **Desafio de UX**: seleção de Pessoa (criar + escolher) e de Pasta — decidir selector/`ChoiceType` escopado ao tenant (`PessoaRepository::doTenant` pode não existir ainda; criar se preciso). Pré-preencher `EditarConfiguracaoCarteiraInput` a partir da entidade (DTO sem `fromEntity` — popular à mão no controller).

## Follow-ups da revisão (registrar; decisão de negócio para o humano)
1. **Assimetria "caso encerrado" (IMPORTANTE, DECISÃO DO HUMANO):** `ReconhecerValorAtualizado`, `RegistrarTentativaCobranca` e `GerarRevisao` **não** bloqueiam num caso encerrado no servidor (a UI esconde o botão, mas a rota POST muta). Os "irmãos" (RegistrarObrigacao/DefinirProximaAcao/ConcluirAcao/CriarAcordo) lançam `CasoEncerradoException`. O guard vive na camada UseCase (Etapas 1–7) — **não alterei regra de domínio de etapa anterior numa onda HTTP**. **Reconhecer-valor é o de risco de integridade** (encerrado exige saldo 0; reconhecer encargos criaria encerrado-com-saldo). Decidir: adicionar o guard nos 3 UseCases (consistência) ou aceitar/documentar a assimetria. Tentativa/revisão são cosméticos (alertas de encerrado já dão short-circuit).
2. **Matriz de teste (IMPORTANTE, parcial):** a spec pede happy·erro·capacidade·IDOR·CSRF por mutação. Cancelar-acordo foi fechado. Ainda faltam células de **capacidade-negada e CSRF-inválido** em: reconhecer-valor, tentativa, concluir-ação, gerar-revisão, resolver-revisão, romper-acordo (todas têm IDOR + happy; o mecanismo `tenantComCapacidade`/CSRF é centralizado e já provado em várias rotas → risco de regressão, não vulnerabilidade). Fechar por completude quando conveniente.
3. **NIT:** `RegistrarTentativaCobrancaInput::observacao` é o único texto livre sem `Assert\Length` (DTO da Etapa 5). Adicionar `Length(max: ...)` se for mexer nesse DTO.

## Decisões mantidas (NÃO alterar)
- Etapa 7: linha só-encargos rejeitada, sem obrigação principal-zero. Intacta.
- Dinheiro = int centavos; formatação de saída só via `|centavos`; entrada só via `CentavosType`. Nunca float/aritmética de dinheiro no Twig.
- Autorização: módulo em TODA rota; capacidade via `hasPermission` nas mutações (não per-item ACL). `isSystem`/`ROLE_SUPER_ADMIN` = bypass (por design do checker).

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `30e4cf4` ou posterior, working tree limpo.
2. `php -d memory_limit=512M bin/phpunit tests/Cobranca` = 288/288.
3. Ler este handoff + `docs/specs/cobranca-etapa8b-mutacoes.md` + EXECUTION_STATUS §"Próxima ação".
4. Carregar skill `workflow`. Retomar por **8B-D (financeiro)** seguindo o "Padrão ESTABELECIDO" acima. Storytelling dos Forms antes; single-writer (o cluster do Caso não paraleliza).
