# Spec — Cobranças Etapa 5: Estados, Judicialização, Encerramento, Próxima Ação, Revisões e Alertas

> Risco: **ALTO** (toca multi-tenant + integração cross-domínio com `Pasta`). Alvo da revisão.
> Fonte de verdade das regras: `docs/gestao-cobrancas/FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` (SPEC) §8, §13, §14, §16, §17, §22, §23. Este documento **não reinterpreta** as invariáveis (§23) nem expande o MVP (§24).

---

## 1. Objetivo da etapa

Fechar o ciclo de vida do Caso de Cobrança: transições de estado, judicialização (vínculo a uma `Pasta` existente), encerramento manual, próxima ação operacional e a camada de revisão de vínculo + alertas derivados.

**Invariáveis cobertas:** 16 (judicialização não encerra), 17 (acompanhamento até encerramento manual), 28 (sistema alerta, humano decide) + 1 (tenant) no vínculo cross-domínio.

## 2. Modelo de estados (SPEC §16/§17)

`StatusCaso` (já existe, 3 valores de ciclo de vida): `ativo` → `judicializado` → `encerrado`.

- **"Pronto para encerrar" NÃO é um 4º estado persistido** — é indicador **derivado**: `status !== encerrado E saldoExigivel(caso) === 0`. Um caso pode estar simultaneamente *judicializado* e *pronto para encerrar*.
- **Judicializar** muda a fase para `judicializado` — **não encerra** (invariável 16). O acompanhamento financeiro continua.
- **Encerrar** é **sempre manual** (invariável 17), exige saldo exigível resolvido (`=== 0`), e é o único caminho para `encerrado`.
- Encerrado: histórico permanece; não recebe novas obrigações; nova inadimplência gera **novo** caso (não reabre).

## 3. Integração com Pasta (judicialização) — cross-domínio

- Ligação **UNIDIRECIONAL** `CasoCobranca → Pasta`: nova FK `cobranca_caso.pasta_judicial_id` (`ManyToOne Pasta`, nullable, `ON DELETE SET NULL`).
- **NÃO tocar `PastaController`** (~1800 linhas) nem duplicar Pasta/Processo/Documento (SPEC §16, invariável — §24 proíbe duplicação).
- **Guard multi-tenant obrigatório:** a `Pasta` vinculada tem de pertencer ao MESMO tenant do caso. Resolvida por `id + tenant` (`PastaRepository::findOneBy(['id','tenant'])`). Pasta de outro escritório ⇒ `PastaNaoEncontradaException` (não vaza existência). **Teste cross-tenant real (DB) obrigatório.**
- **Permissão do módulo `pastas`:** o gate `can_access_module('pastas')` é responsabilidade do **controller da Etapa 8** (não há controllers nesta etapa — toda a feature difere a camada HTTP/permissão para a Etapa 8). O UseCase garante o isolamento de tenant (a invariável crítica). Documentado como responsabilidade da Etapa 8.
- `migration ALTERA cobranca_caso` (2ª migration de Cobranças a alterar tabela do próprio módulo). Ordem de purga: `cobranca_caso` já é apagado (linha ~76) antes de `pasta` (Fase 2) → FK-safe.

## 4. Entidades novas

### `ProximaAcao` (TenantAware + Auditavel) — SPEC §14
Tarefa operacional definida pelo gestor. **Máx. 1 ativa (pendente) por caso.**
- `caso` (nn), `tenant` (nn), `descricao` (string), `prazo` (date nullable), `status` (`StatusProximaAcao` pendente/concluida), `resultado` (nullable), `responsavel` (User nn), `criadoEm`, `criadoPor`, `concluidaEm` (nullable).

### `RevisaoPessoaCobrada` (TenantAware + Auditavel) — SPEC §8
Pendência de revisão de vínculo. **Depois de resolvida, para de gerar alerta.**
- `caso` (nn), `tenant` (nn), `motivo` (string), `status` (`StatusRevisao` pendente/resolvida), `resolucao` (nullable), `criadoEm`, `criadoPor` (nullable), `resolvidaEm` (nullable), `resolvidaPor` (User nullable).

## 5. Enums novos
- `StatusProximaAcao`: `pendente` / `concluida` (+ `label()`).
- `StatusRevisao`: `pendente` / `resolvida` (+ `label()`).

## 6. UseCases (fan-out em 3 sub-features disjuntas)

### Sub-feature A — Judicialização & Encerramento
- `JudicializarCasoUseCase(input{casoId,pastaId}, tenant, user)`: resolve caso+pasta por id+tenant; rejeita se encerrado (`CasoEncerradoException`) ou já judicializado (`CasoJaJudicializadoException`); seta `pastaJudicial` + `status=Judicializado`; grava `EventoHistorico` `Judicializacao` e `VinculoPasta`. **Não encerra.**
- `EncerrarCasoUseCase(input{casoId,observacao?}, tenant, user)`: resolve caso; rejeita se já encerrado (`CasoEncerradoException`); exige `saldoExigivel(caso) === 0` senão `SaldoNaoResolvidoException`; seta `status=Encerrado`; grava `EventoHistorico` `Encerramento`. Funciona tanto para caso `ativo` quanto `judicializado`.
- Dependências: `CasoCobrancaRepository`, `PastaRepository` (read), `CalculadoraSaldo` (read), `RegistrarEventoHistorico`.

### Sub-feature B — Próxima ação
- `DefinirProximaAcaoUseCase(input{casoId,descricao,prazo?}, tenant, user)`: resolve caso; rejeita se encerrado; rejeita se já há ativa (`ProximaAcaoAtivaJaExisteException`, via `findAtivaDoCaso`); cria `ProximaAcao` pendente (responsavel=user). Sem evento de histórico (não consta na lista §13).
- `ConcluirAcaoUseCase(input{acaoId,resultado,proximaDescricao?,proximoPrazo?}, tenant, user)`: resolve ação por id+tenant; rejeita se não pendente (`ProximaAcaoNaoEncontradaException` para inexistente); registra `resultado`+`concluidaEm`+`status=concluida`; se `proximaDescricao` informado, cria a próxima ação (permitido: após concluir, não há ativa — respeita máx. 1).
- Dependências: `ProximaAcaoRepository`, `CasoCobrancaRepository`.

### Sub-feature C — Revisão de vínculo & Alertas derivados
- `GerarRevisaoUseCase(input{casoId,motivo}, tenant, user)`: resolve caso; cria `RevisaoPessoaCobrada` pendente; grava `EventoHistorico` `RevisaoVinculo`.
- `ResolverRevisaoUseCase(input{revisaoId,resolucao}, tenant, user)`: resolve revisão por id+tenant; rejeita se já resolvida (`RevisaoJaResolvidaException`); seta `resolucao`+`resolvidaEm`+`status=resolvida`+`resolvidaPor`; grava `EventoHistorico` `RevisaoVinculo` (resolvida). **Após resolver, o alerta cessa.**
- `AlertasCobranca` (serviço read-only): `alertasDoCaso(caso, ?hoje): AlertaCobranca[]` — alertas derivados (SPEC §14):
  - obrigação exigível vencida a verificar;
  - parcela de acordo (obrigação com `acordoOrigem`) vencida;
  - próxima ação ativa atrasada (`findAtivaDoCaso` + `prazo < hoje`);
  - saldo zero (pronto para encerrar) — `status !== encerrado E saldoExigivel === 0`;
  - revisão pendente (`RevisaoPessoaCobradaRepository::existePendenteDoCaso`).
  Nenhum alerta muda estado (invariável 28). Enum `TipoAlerta` + DTO `AlertaCobranca` (readonly).
- Dependências: `RevisaoPessoaCobradaRepository`, `CasoCobrancaRepository`, `ObrigacaoRepository` (read), `ProximaAcaoRepository` (read via `findAtivaDoCaso`), `CalculadoraSaldo` (read), `RegistrarEventoHistorico`.

## 7. Contratos compartilhados (andaime, committado antes do fan-out)
Entidades (`CasoCobranca.pastaJudicial`, `ProximaAcao`, `RevisaoPessoaCobrada`), enums (`StatusProximaAcao`, `StatusRevisao`), repositories **com todas as queries cross-cluster** (`ProximaAcaoRepository::findAtivaDoCaso` e `RevisaoPessoaCobradaRepository::existePendenteDoCaso`/`pendentesDoCaso` são consumidas pela sub-feature C além das donas), todas as exceptions, migration, factories, purga+seed. Nenhum implementador edita repositório/entidade/migration.

## 8. Testes obrigatórios
- Judicialização **não** encerra (status vira `Judicializado`, nunca `Encerrado`); vínculo grava eventos.
- Encerramento só manual; bloqueia com saldo != 0; funciona de `ativo` e de `judicializado`.
- "Pronto para encerrar" é indicador derivado (não estado); saldo zero acende o alerta.
- Máx. 1 próxima ação ativa; concluir seta status; concluir+definir próxima cria a nova.
- Alerta de revisão cessa após resolução.
- **Cross-tenant real (DB):** não é possível vincular `Pasta` de outro tenant (`JudicializacaoCobrancaIsolamentoTenantTest`).
- Suíte global verde + `tenant-safety-review`.

## 9. Fora do escopo (não fazer)
Controllers/telas (Etapa 8), documentos (Etapa 6), painel read-only do Caso dentro da Pasta (evolução futura), qualquer coisa do domínio Financeiro (§19), motor de juros/alertas automáticos que decidam sozinhos (§24/§28).
