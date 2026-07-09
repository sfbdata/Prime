# Spec — Cobranças Etapa 4: Acordos

> Risco **ALTO** (mexe no saldo derivado + ALTERA a tabela `cobranca_obrigacao`; multi-tenant). Fonte de regras: `FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` (SPEC) §12 e `PLAN.md` §8/Etapa 4. Nomenclatura oficial (SPEC §4). Continua o núcleo (Etapa 2) e os movimentos (Etapa 3).

## Storytelling (derivado da SPEC §12)

**CriarAcordo** — Quem: gestor autorizado. O quê: negociar um Acordo que **substitui** obrigações selecionadas do MESMO Caso por novas obrigações (parcelas). Pré: caso existe no tenant e NÃO está encerrado; cada obrigação a substituir é do MESMO caso (invariável 13 — acordo não atravessa casos) e ainda não está substituída por um acordo vigente. Pós: `Acordo` (status `ativo`); as obrigações selecionadas são marcadas com `acordoSubstituto` (NUNCA apagadas — invariável 14); novas `Obrigacao` parcelas com `acordoOrigem`; o saldo derivado passa a **excluir** as substituídas e **incluir** as parcelas (invariável 15); evento `acordo_criado`. Substituição PARCIAL permitida (§12.5 — pode substituir só parte). Erros: caso não encontrado/encerrado; obrigação de outro caso ou já substituída.

**RomperAcordo** — Quem: gestor. O quê: romper MANUALMENTE um acordo `ativo` (devedor deixou de cumprir), com motivo. NÃO é automático por parcela vencida (§12.7 — o sistema só alerta). Pós: acordo `rompido` + `motivoRompimento`; o saldo derivado **restaura as obrigações originais** (voltam ao exigível) e **descarta as parcelas** do acordo — tudo por derivação (invariável 20), sem reversão imperativa; evento `acordo_rompido`. Erros: acordo não encontrado/outro tenant; acordo não está `ativo`.

**CancelarAcordo** — Quem: gestor. O quê: cancelar um acordo `ativo` (ex.: criado por engano), com motivo. Pós: acordo `cancelado`; saldo restaura originais e descarta parcelas (igual ao rompimento, para efeito de saldo); evento `acordo_cancelado`. Erros: acordo não encontrado; não `ativo`.

**MarcarAcordoCumprido** — Quem: gestor. O quê: marcar um acordo `ativo` como `cumprido` (parcelas quitadas — o sistema alerta, o gestor decide, §28). Pós: acordo `cumprido`; a substituição PERMANECE (originais fora, parcelas dentro e quitadas → saldo tende a 0); evento `acordo_cumprido`. Erros: acordo não encontrado; não `ativo`.

## Decisão de design central (documentada — efeito do estado do acordo no saldo)

A SPEC §12 é explícita só sobre o acordo **ativo** ("obrigações substituídas deixam de compor o saldo exigível"). Para os estados `rompido`/`cancelado` a SPEC é silenciosa. Adoto o modelo **derivado por status** (coerente com invariável 20 — saldo sempre derivado, nunca reversão manual):

> Uma obrigação é **exigível** ⟺
> `(acordoSubstituto is null OU acordoSubstituto.status ∈ {rompido, cancelado})`
> **E** `(acordoOrigem is null OU acordoOrigem.status ∈ {ativo, cumprido})`.

Ou seja: acordo **ativo/cumprido** → substituídas FORA, parcelas DENTRO. Acordo **rompido/cancelado** → substituídas VOLTAM, parcelas FORA. Nenhum UseCase faz reversão imperativa; só muda `Acordo.status`, e a `CalculadoraSaldo` deriva o resto. *(Se o negócio quiser que o rompimento NÃO restaure os originais, é trocar a regra do `doCasoExigiveis` — decisão isolada num ponto.)*

## Entidade nova (namespace `App\Cobranca\Entity`, PK int, `TenantAware`, `Auditavel`)
- **Acordo** (`cobranca_acordo`): `tenant` nn, `caso`(CasoCobranca) nn, `status`(StatusAcordo=ativo), `dataAcordo`(date_immutable), `motivoRompimento`(string 255 nullable), `motivoCancelamento`(string 255 nullable), timestamps, `criadoPor`(User SET NULL). Inversos opcionais: `OneToMany obrigacoesSubstituidas` (mappedBy `acordoSubstituto`), `OneToMany parcelas` (mappedBy `acordoOrigem`). Métodos ricos: `estaAtivo()`, `romper(string $motivo)`, `cancelar(?string $motivo)`, `marcarCumprido()`.

## Alterações em entidade existente (migration ALTERA `cobranca_obrigacao` — cuidado, não é só tabela nova)
- **Obrigacao** ganha 2 FKs nullable: `acordoOrigem`(ManyToOne Acordo, nullable — a obrigação é parcela deste acordo) e `acordoSubstituto`(ManyToOne Acordo, nullable — a obrigação foi substituída por este acordo). Getters/setters + `foiSubstituida()`/`ehParcela()`.

## Enum novo
- **StatusAcordo** (`string`, `label()`): `ativo` / `cumprido` / `rompido` / `cancelado` (SPEC §12).
- **TipoEventoHistorico**: adicionar `AcordoCumprido = 'acordo_cumprido'` (os demais `acordo_*` já existem).

## Serviço estendido — `CalculadoraSaldo` (orquestrador-owned)
- Passa a usar `ObrigacaoRepository::doCasoExigiveis(caso)` (status-aware, regra acima) no lugar de `doCaso` em `saldoExigivel` e `saldoVencido`.
- Alocações abatidas apenas nas obrigações exigíveis: usar `totalAlocadoEmObrigacoes(idsExigiveis, tenant)` (método já existente da Etapa 3) — `saldoExigivel` deixa de usar `totalAlocadoNoCaso`.

## Repositórios
- **AcordoRepository** (stub): `salvar`/`remover`/`findOneByIdDoTenant`.
- **ObrigacaoRepository**: `doCasoExigiveis(caso)` (LEFT JOIN acordoSubstituto/acordoOrigem, filtro por status; escopo por tenant do caso). `doCaso` (todas) permanece — pode servir ao histórico/UI.

## Exceptions novas
- `AcordoNaoEncontradoException`, `AcordoNaoAtivoException` (rompimento/cancelamento/cumprimento exigem `ativo`), `ObrigacaoJaSubstituidaException` (obrigação já pertence a um acordo vigente). (`ObrigacaoDeOutroCasoException`/`CasoNaoEncontradoException`/`CasoEncerradoException` reusadas.)

## Migration
CREATE `cobranca_acordo`; ALTER `cobranca_obrigacao` ADD `acordo_origem_id`, `acordo_substituto_id` (INT nullable, FK NO ACTION). Aplicar dev+test via `migrations:execute --up`.

## Purga (anti-drift)
`cobranca_acordo` é `tenant_id` → `ORDEM_DELECAO`: inserir **entre `cobranca_obrigacao` e `cobranca_caso`** (obrigação referencia acordo → obrigação primeiro; acordo referencia caso → acordo antes do caso). Semear no `PurgarEscritorioUseCaseTest` (acordo + uma obrigação com `acordo_substituto_id`).

## Testes
- **CalculadoraSaldo** (unit): substituída sai do exigível; parcela entra; acordo rompido restaura originais e descarta parcelas; cumprido mantém substituição.
- **CriarAcordo** (unit): substituição parcial; marca `acordoSubstituto` sem apagar (invariável 14); gera parcelas com `acordoOrigem`; rejeita obrigação de outro caso (invariável 13) / já substituída; evento `acordo_criado`.
- **RomperAcordo/CancelarAcordo/MarcarAcordoCumprido** (unit): transição de estado; exige `ativo`; motivo no rompimento; evento certo.
- **Cross-tenant DB** (functional): acordo não alcança caso de outro tenant; substituição não cruza tenant; saldo pós-acordo/rompimento correto no banco; obrigação substituída persiste (invariável 14).

## Invariáveis cobertas: 13, 14, 15 (+ 1/16/20 transversais).
## Fora de escopo (Etapa 5+): judicialização/encerramento/próxima ação/revisão/alertas; parcela vencida gera ALERTA (derivado) — a Etapa 5 faz o alerta, aqui só garantimos que não rompe automático.
