# Plano de Implementação — Gestão de Cobranças

> Documento de **planejamento**. Nada aqui foi implementado. Fonte de verdade das regras: `FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` (referida como "SPEC"). Este plano não reinterpreta nem simplifica as regras invariáveis da SPEC (§23) e não expande o escopo além do MVP (§24).

---

## 0. Sumário executivo

- **Novo domínio** `app/src/Cobranca/` (namespace `App\Cobranca`), seguindo o layout de domínio do projeto (`Controller/ UseCase/ Entity/ Repository/ DTO/ Form/ Enum/ Service/`). Não reaproveita o domínio Expediente (quase vazio).
- **Reuso pesado** do que já existe: `Cliente` (credor), `Pasta` (judicialização), `TenantAware`+`TenantFilter` (multi-tenant), `Auditavel`+`AuditLogSubscriber` (auditoria técnica), `PermissionChecker`/`can_access_module` (autorização), `ArquivoStorageService` + JS/CSS do file manager (documentos), `_filtro_barra`/`filtro-tabela.js` (filtros), cards `.db-*` (dashboard), tema `--bs-*`/`--jp-accent`.
- **Sequência obrigatória** (SPEC §21/§25): núcleo do domínio + UseCases → testes → importação → telas → alertas → dashboard.
- **Risco: ALTO** pelo critério do CLAUDE.md (toca dinheiro + multi-tenant). Exige spec formal (esta + a SPEC), revisão reforçada com `feature-review-agent` e re-revisão em pontos financeiros.
- **Fronteira com o futuro Financeiro (SPEC §19) é dura**: Cobranças registra efeitos sobre a dívida (obrigações, saldo, pagamentos/liquidações, acordos, honorários **projetados e realizados**). Caixa, faturamento, conciliação, repasses e recebimento efetivo de honorários **ficam de fora** — não modelar agora.

---

## 1. Padrões obrigatórios de arquitetura (o que o plano segue)

Confirmado por análise do código atual:

- **Fluxo:** `Controller → Form/DTO → UseCase → Entity → Repository → flush()`. Toda regra de negócio mora em UseCase; controller é fino.
- **PHP:** `declare(strict_types=1)`, type hints 100%, `private readonly` para dependências, só atributos, classes `final` (exceto entidades Doctrine).
- **Multi-tenant:** entidade `implements TenantAware` + `#[ORM\ManyToOne(targetEntity: Tenant::class)] #[ORM\JoinColumn(nullable: false)] private ?Tenant $tenant`. O `TenantFilter` (SQLFilter `tenant`, ativado no `kernel.request`) injeta `tenant_id` automaticamente — **rede de segurança**, não substituto: UseCases atribuem tenant do usuário e repositories filtram explicitamente. Não há trait; o campo é declarado manualmente (padrão de `Cliente`/`Pasta`/`Processo`).
- **Auditoria:** entidade `implements Auditavel` → auditada automaticamente pelo `AuditLogSubscriber` (`onFlush`), gravando em `audit_log` (ator, tenant, IP, rota, changeset).
- **Permissões:** são **linhas no banco** (`permission.code`), não enum PHP. Gate em controller via `PermissionChecker::canAccessModule($user, $tenant, 'cobrancas')` / `canAccessResource(...)`; no Twig via `can_access_module('cobrancas')`. Proibido checar role direto.
- **Enums:** backed enum `string` com método `label()`, em `app/src/Cobranca/Enum/`, mapeados com `enumType:`.
- **Testes:** unit do UseCase + functional do controller; DAMA (rollback transacional por teste), Foundry v2 (factories); `APP_ENV=test` com `failOnDeprecation/Notice/Warning` (um deprecation derruba a suíte). Rodar sempre dentro do container.
- **Docker:** todos os comandos (`bin/console`, `composer`, `phpunit`) rodam dentro de `jusprime_php_dev`.
- **Git:** escrita é manual do humano; o orquestrador monta e explica os comandos.

---

## 2. Domínios existentes reutilizados / integrados

| Domínio | Papel na feature | Ponto de integração concreto |
|---|---|---|
| **Cliente** (`app/src/Cliente/`) | É o **credor**. Toda Carteira pertence a um Cliente (invariável 4). | `CasoCobranca`/`Carteira` referenciam `App\Cliente\Entity\Cliente` (id **int**, PF/PJ via herança JOINED). Nunca criar cadastro de credor separado (SPEC §4). |
| **Pasta** (`app/src/Pasta/`) | Destino da **judicialização**. | `CasoCobranca.pastaJudicial` = `ManyToOne(Pasta)` **nullable**. Link na UI = `path('pasta_show', {id})` (`/pasta/{id}`). Sem duplicar pasta/processo/documentos (SPEC §16). |
| **Processo** (`app/src/Processo/`) | Indireto — vem junto da Pasta (via `PastaProcesso`). | Nenhuma FK direta; a cobrança liga à **Pasta**, não ao Processo. |
| **Documentos / file manager** | Anexos do Caso mesmo **sem Pasta** (SPEC §15). | Reutilizar `App\Shared\Service\ArquivoStorageService` (+ `CompressorArquivo`) e o front do file manager (`pasta-arquivos.js/.css`, contrato por `data-*`). **Criar** entidades próprias `CobrancaDocumento`/`CobrancaSecao` (as de Pasta têm FK `pasta` obrigatória — não reutilizáveis) e o parâmetro `cobrancas_uploads_dir`. |
| **Autorização** (`PermissionChecker`, `PermissionExtension`, `PermissionFixture`) | Módulo `cobrancas` + capacidades. | Novas permissões no `PermissionFixture`; gate em controllers e menu. |
| **Auditoria** (`AuditLogSubscriber`, `AuditLog`) | Auditoria técnica (SPEC §22). | Entidades sensíveis `implements Auditavel`. **Não** substitui o Histórico do Caso (SPEC §13/§26 invariável). |
| **Tenant** (`Tenant`, `TenantContext`, `TenantFilter`) | Isolamento (invariável 1). | Toda entidade `TenantAware`. |
| **UI compartilhada** | Filtros, cards, badges, tema, tooltips, menu, base layout. | Ver §9 (Telas/UX). |

---

## 3. Verificação de decisões bloqueantes + decisões técnicas assumidas

### 3.1 Decisões de negócio bloqueantes: **nenhuma**

A SPEC final resolveu explicitamente tudo o que antes travava a modelagem: saldo derivado (§10, invariável 20), encargos registrados à parte sem "excedente" automático (§11), honorários acrescidos vs separados com rateio proporcional (§18), Pessoa cobrada sem vínculo/fiador (§8.7, invariável 21), Pessoa reutilizável com dedup por CPF/CNPJ no tenant (§7, invariável 24), documentos antes da Pasta (§15, invariável 25), estados do Caso (§17), Histórico ≠ auditoria (§13, invariável 26), nomenclatura oficial (§4, invariável 27) e fronteira com o Financeiro (§19, invariável 22). **É possível modelar sem novas respostas de negócio.**

### 3.2 Decisões **técnicas** assumidas (explícitas, não silenciosas — confirmar antes/na Etapa 0)

Estas são de implementação, não de negócio. Assumo um default e sinalizo para você validar:

1. **Representação monetária — ✅ CONFIRMADO por você.** Todos os valores (dívida, pagamento, honorário) em **`INTEGER`/`BIGINT` de centavos**, com um helper fino `Dinheiro` (parse/format/arredondamento) e aritmética centralizada nos serviços de saldo/honorários. Motivo: rateio proporcional de honorários (§18) e derivação de saldo exigem aritmética exata; `decimal` do Doctrine volta como *string* PHP e é fonte clássica de bug. Não é "abstração financeira genérica" (§19) — é só o tipo do valor.
2. **PK das entidades novas.** Seguir o padrão de entidades novas do projeto (a skill `criar-entity` decide UUID vs int no momento da implementação). FKs para `Cliente`/`Pasta` continuam **int** (são entidades legadas int). Não bloqueia a modelagem.
3. **Estado "pronto para encerrar" (§17) — ✅ CONFIRMADO por você.** Modelado como **indicador derivado** (`status ∈ {ativo, judicializado}` e `saldoExigível == 0`), **não** como estado persistido — porque um caso pode estar simultaneamente *judicializado* e *pronto para encerrar*. O `status` persistido tem 3 valores de ciclo de vida: `ativo`, `judicializado`, `encerrado`. "Pronto para encerrar" vira badge/alerta. Fica **exatamente** o comportamento da SPEC (indica, não encerra), sem inventar um 4º estado ambíguo.
4. **Alocação de pagamento parcial entre obrigações.** **Assumo alocação explícita** (o gestor indica quais obrigações o pagamento cobre e quanto em cada), com **sugestão FIFO por vencimento** pré-preenchida. Motivo: "saldo vencido" e "obrigação quitada" precisam saber a que obrigação o dinheiro foi. Coerente com o espírito manual da SPEC (§9/§11). Entidade `AlocacaoPagamento`.
5. **Alertas: derivados vs persistidos.** Alertas puramente factuais (vencimento, parcela de acordo vencida, próxima ação atrasada, saldo zero) são **derivados por query** (sem tabela). A **revisão de pessoa cobrada/vínculo** (§8) é **persistida** (`RevisaoPessoaCobrada`, com estado pendente/resolvida), porque a SPEC exige que "depois da revisão o mesmo evento não continue gerando alerta" — isso requer estado.
6. **Nome do módulo/menu — ✅ CONFIRMADO por você.** Módulo e item de menu **próprios**, rotulados **"Cobranças"** (permissão `modules.cobrancas.view`). **Não** entra dentro do futuro módulo Financeiro nem sob "Clientes" neste momento — é item de menu independente, com gate `can_access_module('cobrancas')`. (Os `modules.financeiro.view`/menu "Financeiro" existentes permanecem intocados.)
7. **Honorários "realizados" (§18.7).** Modelados como **valor derivado** das recuperações efetivas (pagamentos/liquidações), calculado pela regra snapshot do caso — **não** cria entidade de "honorário recebido" (isso é Financeiro, §18.8/§19). Projetados = derivados do saldo/base pela regra.

> Se você discordar de qualquer default acima (principalmente #1, #6), me diga na revisão deste plano — nenhum deles foi cravado no código.

---

## 4. Modelo de domínio proposto

Namespace `App\Cobranca`. Nomenclatura oficial da SPEC §4 preservada nos nomes de classe (nunca "Cobranca" sozinho para o Caso).

### 4.1 Entidades

| Entidade | Representa | Relações principais | `Auditavel`? |
|---|---|---|---|
| `Carteira` | Carteira de Cobrança (SPEC §4) | `ManyToOne Cliente` (credor, nn), `ManyToOne Tenant` (nn); embute `RegraHonorarios` padrão; config: `modo` (enum), `toleranciaAtrasoDias`, `tipoVinculoPreferido`, `rotuloObjeto` (nomenclatura da UI) | Sim |
| `ObjetoCobranca` | Objeto de Cobrança | `ManyToOne Carteira`, `Tenant`; `referenciaExterna` (import, nullable) | Sim |
| `Pessoa` | Cadastro reutilizável (devedor/proprietário/fiador…) | `Tenant`; `nome`, `cpf?`, `cnpj?`, contatos (email/telefone) | Sim |
| `VinculoPessoaObjeto` | Pessoa Vinculada (relação temporal) | `ManyToOne Pessoa`, `ObjetoCobranca`, `Tenant`; `tipoVinculo`, `dataInicio`, `dataFim?`, `motivoEncerramento?`, `observacao?` | Sim |
| `CasoCobranca` | Caso de Cobrança (agregado central) | `ManyToOne ObjetoCobranca`, `Tenant`; `pessoaCobradaAtual` (`ManyToOne Pessoa`), `pastaJudicial` (`ManyToOne Pasta`, nullable); `status` (enum), embute `RegraHonorarios` **aplicada (snapshot)** | Sim |
| `Obrigacao` | Obrigação/competência/parcela | `ManyToOne CasoCobranca`, `Tenant`; `descricao`, `valorOriginal`, `vencimentoOriginal`, `encargosReconhecidos?`, `referenciaExterna?`, `acordoOrigem?` (se é parcela de acordo), `acordoSubstituto?` (se foi substituída) | Sim |
| `Pagamento` | Pagamento monetário (confirmação manual) | `ManyToOne CasoCobranca`, `Tenant`; `data`, composição (`valorDivida`, `valorEncargos`, `valorHonorarios`), `motivoCorrecao?`; `OneToMany AlocacaoPagamento` | Sim |
| `AlocacaoPagamento` | Quanto de um pagamento foi para cada obrigação | `ManyToOne Pagamento`, `Obrigacao`, `Tenant` | Sim (via pagamento) |
| `Liquidacao` | Redução não monetária (bem/direito) | `ManyToOne CasoCobranca`, `Tenant`; `tipo` (enum), `descricaoBem`, `valorAtribuidoBem?`, `valorReconhecido` (reduz saldo), `data` | Sim |
| `Acordo` | Acordo/parcelamento | `ManyToOne CasoCobranca`, `Tenant`; `status` (enum), `dataAcordo`, `motivoRompimento?`; relação com obrigações substituídas e com parcelas geradas | Sim |
| `ProximaAcao` | Próxima ação manual (máx. 1 ativa/caso, §14) | `ManyToOne CasoCobranca`, `Tenant`; `descricao`/`tipo`, `prazo`, `status`, `resultado?`, `concluidaEm?`, `responsavel` (User) | Sim |
| `EventoHistorico` | Linha do tempo operacional (§13) | `ManyToOne CasoCobranca`, `Tenant`; `tipo` (enum), `ocorridoEm`, `usuario` (User), `descricao`, `dados` (json) | **Não** (é o próprio log de domínio) |
| `RevisaoPessoaCobrada` | Pendência de revisão de vínculo (§8) | `ManyToOne CasoCobranca`, `Tenant`; `motivo`, `status` (pendente/resolvida), `resolucao?`, `resolvidaEm?` | Sim |
| `CobrancaDocumento` | Documento do Caso (§15) | `ManyToOne CasoCobranca`, `secao?`, `Tenant`; espelha `PastaDocumento` | Sim |
| `CobrancaSecao` | Seção/pasta do file manager do Caso | `ManyToOne CasoCobranca`, `Tenant` | Sim |

`RegraHonorarios` = **embeddable** (`#[ORM\Embeddable]`) com `forma` (enum) + `percentual?`, embutido em `Carteira` (padrão) e em `CasoCobranca` (snapshot aplicado). Isola a regra e evita recálculo silencioso de casos antigos (invariável, §18.3). *(Se o projeto não usar embeddables em nenhum ponto, cai para colunas inline — decisão menor da Etapa 1.)*

### 4.2 Enums (`app/src/Cobranca/Enum/`)

`ModoCarteira` (`unico`/`multiplo` — modos A/B §6) · `TipoVinculo` (proprietário/coproprietário/inquilino/ocupante/possuidor/representante/outro) · `StatusCaso` (ativo/judicializado/encerrado) · `StatusAcordo` (ativo/cumprido/rompido/cancelado) · `FormaHonorarios` (acrescido_divida/retido_recuperado/cobrado_separado/sem_percentual) · `TipoLiquidacao` (dinheiro/bem_movel/bem_imovel/outro) · `TipoEventoHistorico` (obrigacao_criada, contato_realizado, boleto_enviado, novo_prazo, negociacao, acordo_criado, acordo_rompido, acordo_cancelado, pagamento_registrado, pagamento_corrigido, liquidacao_registrada, pessoa_cobrada_alterada, revisao_vinculo, judicializacao, vinculo_pasta, encerramento) · `CanalContato` (telefone/whatsapp/email/presencial/carta/outro) · `StatusProximaAcao` (pendente/concluida). Todos `string` com `label()`.

> **Fases (§16) × estados (§17):** as fases da SPEC mapeiam 1:1 para `StatusCaso` — `ativo` = fase **extrajudicial**, `judicializado` = **judicializada**, `encerrado` = **encerrada**. "Pronto para encerrar" **não** é valor do enum: é indicador derivado (§3.2 #3, saldo exigível zero e caso não encerrado).

### 4.3 Onde cada regra invariável (§23) é garantida

| Invariável | Garantido em |
|---|---|
| 1 Todo dado pertence a tenant | Toda entidade `TenantAware` + FK nn + `TenantFilter` + teste cross-tenant por etapa |
| 2/4 Credor é Cliente; carteira pertence a cliente | `Carteira.cliente` nn (FK `Cliente`) |
| 3 Devedor não vira Cliente | `Pessoa` é entidade separada de `Cliente` (não há promoção) |
| 5/6 Caso pertence a 1 objeto; 1..N casos ativos conforme modo | `CasoCobranca.objeto` nn; `Carteira.modo` valida no `AbrirCaso`/`RegistrarObrigacao` |
| 7/8/21 Exatamente 1 pessoa cobrada; pode não ter vínculo | `CasoCobranca.pessoaCobradaAtual` nn (Pessoa), sem exigir vínculo |
| 9/10 Pessoa cobrada só muda manual; vínculo não muda automático | UseCase `AlterarPessoaCobrada` (único caminho); nenhum efeito colateral de vínculo |
| 11 Pessoas/vínculos anteriores no histórico | `VinculoPessoaObjeto` nunca deletado; `dataFim`+motivo; `EventoHistorico` |
| 12 Pagamento não atravessa casos | `AlocacaoPagamento.obrigacao` sempre do mesmo `CasoCobranca`; validado no UseCase |
| 13 Acordo não atravessa casos | `Acordo.caso` único; obrigações substituídas devem ser do mesmo caso |
| 14/15 Obrigação substituída não some, mas sai do saldo | `Obrigacao.acordoSubstituto` marca; nunca deletada; `CalculadoraSaldo` a exclui do exigível |
| 16/17 Judicialização não encerra; encerramento manual | `status`; `EncerrarCaso` exige confirmação; saldo zero só indica |
| 18/19 Honorários separados; carteira define padrão | `RegraHonorarios` embeddable; snapshot no caso; serviço separa credor×escritório |
| 20 Saldo derivado | `CalculadoraSaldo` (nunca coluna manual) |
| 22 Fronteira Financeiro | Sem entidades de caixa/faturamento/repasse; honorários só projetado/realizado |
| 23/24 Pessoa/vínculo/cobrada distintos; CPF/CNPJ opcionais | 3 entidades distintas; documentos nullable; dedup só intra-tenant |
| 25 Documento sem Pasta | `CobrancaDocumento.caso` nn, `pasta` inexistente aqui |
| 26 Histórico ≠ auditoria | `EventoHistorico` (domínio) + `Auditavel` (técnico), separados |
| 27 Nomenclatura | Nomes de classe e UI seguem §4 |
| 28 Sistema alerta, humano decide | Nenhum UseCase automático muda estado sensível; alertas são leitura |

---

## 5. Estratégia de saldo e honorários (o coração financeiro)

**`CalculadoraSaldo` (serviço, read-only, sem persistir):**
- `saldoExigivel(caso)` = Σ(`valorOriginal` + `encargosReconhecidos`) das obrigações **não substituídas por acordo** − Σ alocações de pagamento − Σ `valorReconhecido` de liquidações.
- `saldoVencido(caso)` = idem, restrito a obrigações com `vencimentoOriginal`/prazo de parcela ≤ hoje e ainda não quitadas.
- `saldoConsolidadoObjeto(objeto)` = Σ dos saldos dos casos ativos do objeto (modo B, §6). Nenhum caso isolado representa o total.
- Fonte de verdade = eventos/valores (SPEC §10). Cache/otimização de leitura é permitido depois, mas não como fonte.
- **Honorários "acrescidos à dívida" (§18, invariável 18):** `saldoExigivel` acima é o **saldo do credor** (só a dívida). O honorário do escritório é componente **separado**, calculado por `CalculadoraHonorarios`, exibido e cobrado à parte. Quando a forma é `acrescido_divida`, o **total a cobrar do devedor** = saldo do credor + honorário projetado — mas os dois nunca se misturam no modelo (a composição de cada pagamento em `valorDivida`/`valorHonorarios` preserva a separação). Nenhum dos dois é valor manual.

**`CalculadoraHonorarios` (serviço, read-only):**
- **Projetados** = aplicação da `RegraHonorarios` snapshot do caso sobre a base (valor reconhecido da dívida), respeitando "honorários não entram na própria base" (§18.5).
- **Realizados** = proporcional às recuperações efetivas (pagamentos/liquidações), pela forma:
  - `acrescido_divida`: cada pagamento é **rateado proporcionalmente** entre dívida-do-credor e honorários (§18); a composição fica em `Pagamento.valorDivida`/`valorHonorarios`.
  - `retido_recuperado`: honorário realizado proporcional a cada recuperação.
  - `cobrado_separado`: registra o honorário **gerado**; recebimento efetivo = Financeiro (não modelar).
  - `sem_percentual`: zero.
- **Não** existe "honorário recebido/faturado/repassado" aqui (§18.8/§19).

Toda aritmética em centavos inteiros, centralizada nesses dois serviços, com testes numéricos dedicados (arredondamento de rateio, soma fecha com o total).

---

## 6. Multi-tenancy, auditoria e fronteira (cross-cutting, valem em todas as etapas)

- **Multi-tenant (risco central):** cada entidade `TenantAware` + FK tenant nn; **inclusive as entidades de associação** (`VinculoPessoaObjeto`, `AlocacaoPagamento`, `CobrancaSecao`) — defesa em profundidade, diferente do `PastaProcesso` legado (que não é `TenantAware`). Repositories nunca consultam sem escopo de tenant. Controllers com guard IDOR (`canAccessResource`/verificação de posse por tenant). **Todo UseCase que cruza entidades valida que pertencem ao mesmo tenant.** Cada etapa entrega teste **cross-tenant** (escritório B não vê/edita dado do A).
- **Auditoria técnica:** entidades sensíveis `implements Auditavel` → `audit_log` automático cobre criação/alteração de obrigações, pagamentos/correções, liquidações, acordos, mudança de pessoa cobrada, honorários, judicialização, vínculo com pasta, encerramento (SPEC §22).
- **Histórico operacional:** `EventoHistorico` escrito **explicitamente** pelos UseCases (é domínio, visível ao usuário). Nunca usar `audit_log` como substituto (SPEC §13, invariável 26).
- **Sem exclusão silenciosa:** correções relevantes por correção/encerramento com motivo+data+usuário, rastreáveis pela **auditoria existente** (SPEC §22). **Não há estorno de pagamento no MVP** — apenas correção quando necessária.
- **Fronteira Financeiro:** ver §5 e SPEC §19 — nenhuma entidade de caixa/banco/fatura/repasse.

---

## 7. Migrations e impacto em dados existentes

- Todas as migrations **criam tabelas novas** do domínio `cobranca_*`. **Nenhuma altera tabela existente** no MVP (Cliente/Pasta/Processo intactos) — a ligação com Pasta é uma FK **na tabela do Caso**, não uma coluna nova em `pasta`.
- Impacto em dados de produção: **nenhum** nas tabelas atuais. Só inserção de linhas de permissão (via `PermissionFixture`/seed) para o módulo `cobrancas`.
- `services.yaml`: novo parâmetro `cobrancas_uploads_dir` (`%kernel.project_dir%/public/uploads/cobrancas`; em test → `var/uploads-test/cobrancas`) + bind. Diretório precisa ser gravável pelo uid 1000 em dev (mesma nota de uploads do CLAUDE.md).
- Migrations agrupadas por etapa (uma por bloco coeso), aplicadas em dev/test durante o desenvolvimento; deploy só ao final (rebuild via `deploy-prod-tls.sh`).

---

## 8. Etapas de implementação

> Regra transversal em **toda** etapa de UseCase: primeiro storytelling do UseCase (skill `criar-usecase`), depois teste, depois implementação (fluxo do CLAUDE.md). Toda etapa termina com suíte verde no container e um teste cross-tenant quando toca entidade nova.

### Etapa 0 — Fundação do domínio e do módulo
**Escopo:** estrutura de pastas `app/src/Cobranca/*`; registrar mapeamento Doctrine do domínio (padrão por-domínio do `doctrine.yaml`); permissões no `PermissionFixture` (`modules.cobrancas.view` + capacidades §22: `resources.cobranca.gerenciar`, `resources.carteira.gerenciar`, `resources.cobranca.movimentacao_financeira` — nomes a confirmar com `docs/AUTORIZACAO.md`); travar decisões técnicas §3.2 (#1 dinheiro, #6 módulo).
**Migrations:** nenhuma (sem entidades ainda). Permissões via fixture/seed.
**Integrações:** `PermissionFixture`, `PermissionExtension`, `doctrine.yaml`.
**Critério de conclusão:** `php bin/console` sem erro; `doctrine:mapping:info` reconhece o namespace; permissões aparecem no seed; decisões §3.2 confirmadas por você.

### Etapa 1 — Núcleo de cadastro: Carteira, Objeto, Pessoa, Vínculo
**Escopo:** entidades `Carteira`, `ObjetoCobranca`, `Pessoa`, `VinculoPessoaObjeto` + enums `ModoCarteira`, `TipoVinculo`, `FormaHonorarios` + embeddable `RegraHonorarios`; repositories com filtro de tenant; UseCases: `CriarCarteira`, `EditarConfiguracaoCarteira` (modo, honorários padrão, tolerância, tipo de vínculo preferido, rótulo do objeto), `CriarObjeto`, `CriarPessoa` (+ `SugerirPessoasDuplicadas` por CPF/CNPJ intra-tenant, §7/invariável 24), `VincularPessoaAObjeto`, `EncerrarVinculo` (data+motivo, sem apagar).
**Migrations:** cria `cobranca_carteira`, `cobranca_objeto`, `cobranca_pessoa`, `cobranca_vinculo_pessoa_objeto`.
**Testes:** unit de cada UseCase; repo (filtro de tenant); **cross-tenant** (dedup de Pessoa nunca atravessa tenant, §7).
**Critério:** suíte verde; migration aplica em dev/test; dedup sugere só no mesmo tenant; vínculo encerrado preserva histórico.

### Etapa 2 — Caso de Cobrança, Obrigações e Saldo derivado
**Escopo:** entidades `CasoCobranca` (status, pessoa cobrada, snapshot de honorários, pasta nullable) e `Obrigacao`; enums `StatusCaso`; entidade `EventoHistorico` + serviço `RegistrarEventoHistorico`; serviço `CalculadoraSaldo`. UseCases: `AbrirCaso` (define pessoa cobrada; aplica modo A/B da carteira), `RegistrarObrigacao` (modo A → caso ativo; modo B → gestor escolhe caso existente ou novo), `ReconhecerValorAtualizado` (encargos, sem nova obrigação), `RegistrarTentativaCobranca` (boleto/valor atualizado + novo prazo → só `EventoHistorico`, §10), `AlterarPessoaCobrada` (motivo+histórico, sem efeito em dívida/pagamentos/acordos).
**Migrations:** cria `cobranca_caso`, `cobranca_obrigacao`, `cobranca_evento_historico`.
**Testes:** saldo em cenários (parcial, encargos, substituição); modo A vs B; invariáveis 5–10, 15, 20; `EventoHistorico` gravado nos eventos certos; teste de que boleto atualizado **não** cria obrigação nova nem apaga valor original (§10).
**Critério:** `CalculadoraSaldo` correta; nenhuma escrita de saldo manual; suíte verde; cross-tenant.

### Etapa 3 — Pagamentos, Liquidações e Honorários
**Escopo:** entidades `Pagamento` (+`AlocacaoPagamento`, composição dívida/encargos/honorários) e `Liquidacao`; enums `TipoLiquidacao`; serviço `CalculadoraHonorarios` (projetados/realizados por forma). UseCases: `RegistrarPagamento` (alocação explícita c/ sugestão FIFO; rateio proporcional de honorários quando `acrescido_divida`), `CorrigirPagamento` (correção quando necessária, com motivo; rastreável pela **auditoria existente**; reflete no saldo derivado — **sem conceito de estorno no MVP**), `RegistrarLiquidacao` (valor reconhecido reduz saldo, distinto do valor do bem, §11).
**Migrations:** cria `cobranca_pagamento`, `cobranca_alocacao_pagamento`, `cobranca_liquidacao`.
**Testes:** rateio proporcional fecha com o total (centavos); honorários por forma (4 formas §18); pagamento não atravessa casos (invariável 12); correção de pagamento é rastreável pela auditoria e reflete no saldo derivado; liquidação reconhecida ≠ valor do bem; **fronteira Financeiro** (nada de recebido/caixa).
**Critério:** aritmética exata; invariáveis 12, 18; suíte verde; cross-tenant.

### Etapa 4 — Acordos
**Escopo:** entidade `Acordo` (obrigações substituídas + parcelas geradas); enum `StatusAcordo`; tolerância de atraso da carteira. UseCases: `CriarAcordo` (seleciona obrigações a substituir — mesmo caso; gera parcelas como novas `Obrigacao` com `acordoOrigem`; pode substituir só parte, §12.5), `RomperAcordo` (manual+motivo), `CancelarAcordo`, `MarcarAcordoCumprido` (ou derivado quando parcelas quitadas).
**Migrations:** cria `cobranca_acordo` (+ join de obrigações substituídas, se ManyToMany).
**Testes:** invariáveis 13, 14, 15; obrigações substituídas somem do exigível mas persistem; parcela vencida **não** rompe automático (§12.7); substituição parcial; acordo continua após judicialização (§12.10).
**Critério:** saldo pós-acordo correto; suíte verde; cross-tenant.

### Etapa 5 — Estados, Judicialização, Encerramento, Próxima ação, Revisões e Alertas derivados
**Escopo:** transições de `StatusCaso`; UseCase `Judicializar` (vincula `Pasta` existente — respeita tenant + permissão do módulo `pastas`; grava `EventoHistorico` + `vinculo_pasta`; não duplica pasta/processo/doc, §16); indicador derivado "pronto para encerrar" (saldo zero); `EncerrarCaso` (manual, saldo resolvido; bloqueia novas obrigações; permite novo caso futuro, §17). Entidade `ProximaAcao` (máx. 1 ativa) + `DefinirProximaAcao`/`ConcluirAcao` (resultado + próxima, §14). Entidade `RevisaoPessoaCobrada` + `GerarRevisao`/`ResolverRevisao` (§8: para de alertar após resolvida). Serviço `AlertasCobranca` (derivados: vencimento a verificar, parcela de acordo vencida, ação atrasada, saldo zero, revisão pendente, §14).
**Migrations:** cria `cobranca_proxima_acao`, `cobranca_revisao_pessoa_cobrada`.
**Testes:** invariáveis 16, 17; judicialização não encerra e continua acompanhando; encerramento só manual; "pronto para encerrar" é indicador; máx. 1 próxima ação ativa; alerta de revisão cessa após resolução; cross-tenant no vínculo com Pasta (não vincular Pasta de outro tenant).
**Critério:** máquina de estados coerente; alertas corretos; suíte verde.

### Etapa 6 — Documentos do Caso de Cobrança
**Escopo:** entidades `CobrancaDocumento`/`CobrancaSecao` (FK `caso` nn), parâmetro `cobrancas_uploads_dir` + bind; UseCases `EnviarDocumento`/`MoverDocumento`/`ExcluirDocumento`/`CriarSecao`/`RenomearSecao`/`ExcluirSecao` reutilizando `ArquivoStorageService` (+ `CompressorArquivo`). Front reutiliza `pasta-arquivos.js`/`.css` por contrato de `data-*` (adaptando endpoints).
**Migrations:** cria `cobranca_documento`, `cobranca_secao`.
**Testes:** upload/serve/exclusão; guard IDOR + tenant + CSRF; documento existe **sem** Pasta; ao judicializar, documentos **permanecem no Caso** (não migram nem duplicam, invariável 25).
**Critério:** anexos operáveis sem Pasta; isolamento por tenant no disco (subpasta por tenant, como M5); suíte verde.

### Etapa 7 — Importação em massa (após núcleo + testes, §21)
**Escopo:** importador da **fonte específica** (relatórios da contabilidade), sempre dentro de uma **carteira escolhida**; fluxo upload → parse → **preview/validação** → confirmação → **relatório de resultado** (importado/ignorado/rejeitado); dedup por `referenciaExterna`/CPF-CNPJ **intra-tenant**; reimportação idempotente (sem duplicidade silenciosa). Reusa os UseCases do núcleo (mesmas regras do cadastro manual). Sem importador universal (§24).
**Migrations:** nenhuma nova (usa entidades do núcleo); talvez tabela de log de importação, se necessário para idempotência.
**Testes:** com relatório real **anonimizado** (fixture); reimportação não duplica; nunca cruza tenant; linhas inválidas viram "rejeitado" com motivo.
**Critério:** importa e reimporta sem duplicar; resultado claro; regras finas ajustadas com dado real (§21).

### Etapa 8 — Telas operacionais (UX como requisito — ver §9)
**Escopo:** item de menu gated (`can_access_module('cobrancas')`); telas na sequência de trabalho (lista de carteiras → visão da carteira → lista de casos com filtro reutilizável → **detalhe do caso** como tela central → formulários de ação). Controllers finos com guard de permissão/tenant/IDOR/CSRF.
**Migrations:** nenhuma.
**Testes:** functional por rota (permissão, tenant, IDOR, CSRF, render); um E2E do fluxo principal (opcional, Playwright).
**Critério:** fluxo operável ponta a ponta; princípios de UX (§9) atendidos; suíte verde.

### Etapa 9 — Alertas na UI + Dashboard
**Escopo:** central de alertas (derivados) por carteira e visão global; Dashboard (§20): financeira (saldo aberto/vencido, recuperado no período, honorários projetados/realizados), operacional (pagamentos a verificar, ações atrasadas, parcelas vencidas, revisões, judicializados), resultado (recuperado/aberto/taxa de recuperação), e no modo B distinguir objetos inadimplentes × casos ativos. Reusa cards `.db-*`.
**Migrations:** nenhuma.
**Testes:** KPIs corretos contra fixtures; dashboard respeita tenant e permissão.
**Critério:** números batem; sem vazamento entre tenants; suíte verde.

---

## 9. Interface e UX (requisito funcional, SPEC §26 — considerado desde o desenho)

Princípio-guia (SPEC §26): *ao abrir a área de cobranças, bater o olho e entender o que está acontecendo, o que exige atenção e a próxima ação.* Traduzido em decisões de tela:

- **Tela central = Detalhe do Caso.** Cabeçalho com **saldo exigível/vencido** em destaque, **estado** (badges: vencido, pendente, em revisão, pronto para encerrar), **pessoa cobrada atual** e **próxima ação** proeminente (a "próxima coisa a fazer"). Corpo com **timeline do histórico** (§13) e abas Obrigações / Pagamentos & Liquidações / Acordos / Documentos.
- **Priorização visual de estados** (SPEC §26): reutilizar o padrão de badges Bootstrap (`text-bg-success/warning/danger/secondary`) e o realce de linha do "urgente" das Demandas (`.pasta-row-urgente`, `.badge-urgente-pulso`) — mapeando para vencido/atrasado/em revisão. Enum de estado no back com `badgeClass()`/`label()` no Output DTO (padrão `PrioridadePasta`).
- **Listas com o filtro reutilizável** (`_partials/_filtro_barra.html.twig` + `filtro-tabela.js` + `.css`): busca livre + facetas (status, fase, vencido, tem acordo, judicializado, carteira) + chips + auto-apply AJAX, no contrato de `DemandasController` (render parcial em XHR).
- **Visão da carteira**: saldo consolidado, nº de objetos inadimplentes × nº de casos ativos (modo B), e um bloco "o que exige atenção" (alertas derivados) no topo.
- **Documentos**: reutiliza o file manager drill-down (JS/CSS) para o Caso.
- **Tooltips** (SPEC §26): Bootstrap `data-bs-toggle="tooltip"` em indicadores e conceitos (saldo vencido, honorários projetados/realizados, estados). Auto-tooltip de tabela truncada já é global no `base.html.twig`.
- **Tema claro/escuro**: só usar vars `--bs-*` e, para accent, `--jp-accent` com override `html[data-bs-theme="dark"]`. Toggle já é global — nenhuma tela precisa de JS de tema.
- **Menos cliques**: formulários pré-preenchem a próxima ação provável (ex.: ao registrar pagamento, sugerir alocação FIFO; ao concluir ação, propor a próxima).
- **Consistência** entre lista, detalhe, formulários, alertas, histórico e dashboard, seguindo `base.html.twig` + AdminLTE.

A UX entra **desde a Etapa 8** (não como acabamento), e o modelo de dados das Etapas 1–5 já expõe os campos que as telas precisam (estados, badges, saldos, próxima ação, alertas).

---

## 10. Riscos e pontos de atenção

1. **Multi-tenancy (ALTO).** Domínio grande, muitas queries agregadas (saldo consolidado, dashboard). Risco de query sem escopo de tenant e de UseCase cruzando entidades de tenants diferentes. → Mitigação: `TenantAware` em tudo (inclusive associações), teste cross-tenant por etapa, validação de mesmo-tenant nos UseCases que cruzam entidades, `tenant-safety-review` antes do merge.
2. **Aritmética financeira (ALTO).** Rateio proporcional de honorários e saldo derivado. → Centavos inteiros, aritmética centralizada em `CalculadoraSaldo`/`CalculadoraHonorarios`, testes numéricos de fechamento.
3. **Scope creep para mini-ERP (MÉDIO).** Tentação de modelar caixa/faturamento. → Fronteira §5/§19 explícita; honorários só projetado/realizado; revisão adversarial checa isso.
4. **Integração com Pasta legada (MÉDIO).** `PastaController` tem ~1800 linhas. → No MVP, ligação é **unidirecional** `Caso → Pasta` (FK + link `pasta_show`); **não** tocar `PastaController`. Se um dia a Pasta precisar mostrar o Caso, é evolução futura (painel read-only).
5. **Nome do módulo/menu (MÉDIO, decisão de produto).** Overlap com `modules.financeiro`/`clientes` existentes. → Confirmar com você na Etapa 0.
6. **Importação com dados reais (MÉDIO).** Regras de dedup só se firmam com relatório real (§21). → Por isso vem depois do núcleo + testes; retrabalho contido.
7. **Volume da feature (MÉDIO).** É grande. → Entregas pequenas e verificáveis (10 etapas), cada uma com suíte verde e revisão.

---

## 11. MVP × Evoluções futuras

**No MVP (esta feature):** núcleo Carteira/Objeto/Pessoa/Vínculo/Caso/Obrigação; pagamentos/liquidações; acordos; estados/judicialização/encerramento; próxima ação; alertas derivados + revisão de vínculo; documentos do caso; honorários projetados/realizados; importação da fonte específica; telas operacionais; dashboard simples.

**Fora do MVP (SPEC §24, não implementar):** motor de juros/multa/correção automático; boletos/Pix/WhatsApp/e-mail automáticos; protesto/Serasa; construtor livre de entidades e workflow configurável; importador universal; múltiplas pessoas cobradas simultâneas; pagamentos/acordos atravessando casos; honorários sucumbenciais; **e todo o domínio Financeiro** (caixa, conciliação, faturamento, NF, contas a pagar/receber, repasses, recebimento efetivo de honorários — SPEC §19).

---

## 12. Ordem consolidada das etapas

`0 Fundação → 1 Cadastro (Carteira/Objeto/Pessoa/Vínculo) → 2 Caso + Obrigações + Saldo → 3 Pagamentos/Liquidações/Honorários → 4 Acordos → 5 Estados/Judicialização/Encerramento/Ações/Alertas → 6 Documentos → 7 Importação → 8 Telas/UX → 9 Alertas UI + Dashboard.`

Núcleo e UseCases (0–6) antes da importação (7); importação antes das telas (8); dashboard por último (9) — exatamente a ordem da SPEC §21. Cada etapa é pequena, testável e revisável, com critério objetivo de conclusão.

---

**Aguardando sua revisão e aprovação. Nenhuma implementação será iniciada antes disso.**
