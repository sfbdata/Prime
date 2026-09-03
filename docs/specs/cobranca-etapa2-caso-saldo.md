# Spec — Cobranças Etapa 2: Caso de Cobrança, Obrigações e Saldo derivado

> Risco ALTO (núcleo financeiro + multi-tenant). Alvo de revisão. Fonte de regras: `FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` (SPEC) e `PLAN.md` §8/Etapa 2. Nomenclatura oficial (SPEC §4) preservada.

## Storytelling (derivado da SPEC — nenhuma decisão de negócio pendente, PLAN §3.1)

**AbrirCaso** — Quem: gestor autorizado. O quê: iniciar um Caso de Cobrança para um Objeto, escolhendo a Pessoa cobrada atual. Pré: objeto e pessoa existem no MESMO tenant. Modo A (carteira `unico`): só pode existir 1 caso **cobrável** (não encerrado — `ativo` ou `judicializado`) por objeto → rejeita 2º. Modo B (`multiplo`): vários permitidos. Pós: caso `ativo`, snapshot da regra de honorários da carteira (SPEC §18.2/§18.3 — não recalcula depois), evento `caso_aberto`. Erros: objeto/pessoa de outro tenant (não encontrado), caso ativo já existe (modo A).

**RegistrarObrigacao** — Quem: gestor. O quê: lançar uma obrigação (competência/parcela/taxa) num caso. Pré: caso existe no tenant e NÃO está encerrado (SPEC §17). Pós: `Obrigacao` com `valorOriginal`+`vencimentoOriginal` preservados (SPEC §10), evento `obrigacao_criada`. Erros: caso não encontrado; caso encerrado.

**ReconhecerValorAtualizado** — Quem: gestor. O quê: reconhecer manualmente encargos (juros/multa/correção) sobre uma obrigação, SEM criar nova obrigação e SEM apagar o original (SPEC §10, invariável 20). Pós: `encargosReconhecidos` setado; evento `valor_atualizado_reconhecido`. O sistema NÃO calcula encargos automaticamente.

**RegistrarTentativaCobranca** — Quem: gestor. O quê: registrar tentativa (boleto/valor atualizado enviado + novo prazo esperado). SPEC §10: **não cria obrigação, não altera valor original** — só `EventoHistorico` (`boleto_enviado`/`novo_prazo`) com `dados` {valorSolicitado, novoPrazo}. Preserva as duas verdades: vencimento original × prazo da tentativa atual.

**AlterarPessoaCobrada** — Quem: gestor. O quê: trocar manualmente a pessoa cobrada do caso (SPEC §8, invariáveis 7/9/10). Pós: `pessoaCobradaAtual` nova; evento `pessoa_cobrada_alterada` (motivo, de→para, usuário). NÃO altera dívida/pagamentos/acordos/documentos. Continua exatamente 1 pessoa cobrada por caso.

## Entidades (namespace `App\Cobranca\Entity`, PK int, TenantAware)

- **CasoCobranca** (`cobranca_caso`, Auditavel): `tenant` nn, `objeto`(ObjetoCobranca) nn, `pessoaCobradaAtual`(Pessoa) nn, `status`(StatusCaso=ativo), snapshot `formaHonorarios`+`percentualHonorarios`, timestamps, `criadoPor`. `pastaJudicial` fica para a Etapa 5. Métodos ricos: `estaAtivo()`, `estaEncerrado()`, `definirPessoaCobrada(Pessoa)`.
- **Obrigacao** (`cobranca_obrigacao`, Auditavel): `tenant` nn, `caso`(CasoCobranca) nn, `descricao`, `valorOriginal`(int centavos), `vencimentoOriginal`(date), `encargosReconhecidos`(int centavos, default 0), `referenciaExterna?`, timestamps, `criadoPor`. `valorExigivel()` = valorOriginal+encargosReconhecidos. `reconhecerEncargos(int)`. (FKs de acordo → Etapa 4.)
- **EventoHistorico** (`cobranca_evento_historico`, **NÃO** Auditavel — é o log de domínio, SPEC §13/invariável 26): `tenant` nn, `caso` nn, `tipo`(TipoEventoHistorico), `ocorridoEm`, `usuario`(User, nullable SET NULL), `descricao`, `dados`(json nullable).

## Enums
- **StatusCaso**: ativo / judicializado / encerrado (SPEC §17; "pronto para encerrar" é indicador derivado, não estado).
- **TipoEventoHistorico**: lista completa da PLAN §4.2 (caso_aberto, obrigacao_criada, valor_atualizado_reconhecido, contato_realizado, boleto_enviado, novo_prazo, negociacao, acordo_*, pagamento_*, liquidacao_registrada, pessoa_cobrada_alterada, revisao_vinculo, judicializacao, vinculo_pasta, encerramento).

## Serviços (read-only / append)
- **CalculadoraSaldo** (Service, sem persistir; centavos int): `saldoExigivel(caso)` = Σ `valorExigivel()` das obrigações do caso; `saldoVencido(caso, ?hoje)` = idem restrito a `vencimentoOriginal ≤ hoje`; `saldoConsolidadoObjeto(objeto)` = Σ `saldoExigivel` dos casos **cobráveis (não encerrados)** do objeto (modo B, SPEC §6 — retificado em 03/09/2026). Em Etapa 2 não há pagamentos/liquidações/acordos → a subtração desses entra na Etapa 3/4 (extensão orquestrador-owned). Fonte = eventos/valores (SPEC §10, invariável 20); nunca coluna de saldo manual.
- **RegistrarEventoHistorico** (Service): `registrar(caso, tipo, usuario, descricao, dados=[])` → cria+persiste o `EventoHistorico` (SEM flush; o UseCase flusha uma vez).

## Migration
`cobranca_caso`, `cobranca_obrigacao`, `cobranca_evento_historico` (colunas de dinheiro = `INT` centavos). Aplicar dev+test via `migrations:execute --up`.

## Purga (anti-drift `PurgaCoberturaSchemaTest`)
Inserir na `ORDEM_DELECAO` ANTES do bloco Etapa 1 (caso → objeto/pessoa): `cobranca_evento_historico` → `cobranca_obrigacao` → `cobranca_caso`. Semear no teste da purga.

## Testes
Unit de cada UseCase (mock repos); `CalculadoraSaldo` (parcial/encargos/consolidado modo B, centavos); cross-tenant (caso/obrigação de outro escritório rejeitados); modo A rejeita 2º caso cobrável (inclusive quando o existente está judicializado); caso encerrado rejeita obrigação; boleto atualizado NÃO cria obrigação nem altera original; `EventoHistorico` gravado nos eventos certos.

## Invariáveis cobertas: 5,6,7,8,9,10,20,26 (+ 1/23/24 multi-tenant transversal).
## Fora de escopo (etapas seguintes): pagamentos/liquidações/honorários realizados (E3), acordos e substituição de obrigações (E4), judicialização/encerramento/próxima ação/revisão/alertas (E5), pastaJudicial (E5).
