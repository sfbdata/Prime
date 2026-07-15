# Spec — Ajuste 4: Remover "Revisão de pessoa cobrada" por completo

> Rodada de ajustes de Cobranças (ver `docs/gestao-cobrancas/AJUSTES_BACKLOG.md` §4). Risco **MÉDIO**
> (migration destrutiva em prod + toca AlertasCobranca/Dashboard/DetalheCaso e testes). Módulo em produção.

## Objetivo
A feature "Revisão de pessoa cobrada" (Etapa 5) não tem uso prático. Remover **por completo**: some da
interface, dos alertas, do dashboard, do detalhe do caso, do backend e do banco.

## Confirmação de dados
- **Dev:** `cobranca_revisao_pessoa_cobrada` = 1 linha (resolvida, de smoke). OK para DROP no dev.
- **Prod:** o humano confirma `SELECT count(*)` antes do deploy (esperado 0 ou só resolvidas — nada operacional).

## Invariante de compatibilidade (NÃO violar)
- **Manter `TipoEventoHistorico::RevisaoVinculo`** (valor `revisao_vinculo`): há **2 eventos** desse tipo
  no histórico do dev (e possivelmente em prod). Remover o caso do enum quebraria a hidratação desses
  eventos. Só deixamos de **criar** novos (o UseCase que criava é apagado). Documentar no enum.

## Escopo — arquivos a APAGAR
- `src/Cobranca/Controller/RevisaoCobrancaController.php`
- `src/Cobranca/UseCase/GerarRevisaoUseCase.php`, `ResolverRevisaoUseCase.php`
- `src/Cobranca/Entity/RevisaoPessoaCobrada.php`
- `src/Cobranca/Repository/RevisaoPessoaCobradaRepository.php`
- `src/Cobranca/Enum/StatusRevisao.php`
- `src/Cobranca/Form/GerarRevisaoType.php`, `ResolverRevisaoType.php`
- `src/Cobranca/DTO/GerarRevisaoInput.php`, `ResolverRevisaoInput.php`, `RevisaoOutput.php`
- `src/Cobranca/Exception/RevisaoJaResolvidaException.php`, `RevisaoNaoEncontradaException.php`
- Testes só-revisão: `tests/Cobranca/Unit/GerarRevisaoUseCaseTest.php`, `ResolverRevisaoUseCaseTest.php`

## Escopo — arquivos a EDITAR
- `src/Cobranca/Service/AlertasCobranca.php`: remover dep `RevisaoPessoaCobradaRepository`, o param
  `$temRevisaoPendente` de `montarAlertas`, os 3 call-sites (`alertasDoCaso`/`alertasComContexto`/
  `alertasDosCasos`) e o bloco do alerta `RevisaoPendente`.
- `src/Cobranca/Enum/TipoAlerta.php`: remover o caso `RevisaoPendente` e seus arms (label/badgeClass/icone).
  Seguro — TipoAlerta é derivado, não persistido.
- `src/Cobranca/UseCase/MontarDashboardCobrancaUseCase.php`: remover dep, `casoIdsComPendente`,
  `$revisoesPendentes` (contagem + arm) e o argumento `revisoesPendentes:` do DTO.
- `src/Cobranca/DTO/DashboardCobrancaOutput.php`: remover `revisoesPendentes`.
- `src/Cobranca/UseCase/MontarDetalheCasoUseCase.php`: remover dep + `revisoesPendentes` (map dos pendentes).
- `src/Cobranca/DTO/CasoDetalheOutput.php`: remover `revisoesPendentes`.
- `templates/cobranca/dashboard/index.html.twig`: remover o card "Revisões de pessoa cobrada".
- `templates/cobranca/caso/show.html.twig`: remover o botão "Revisão", o banner "Revisões pendentes" e o
  JS do modal resolver.
- `templates/cobranca/caso/_acoes_modais.html.twig`: remover `modalGerarRevisao` e `modalResolverRevisao`.
- `src/Tenant/UseCase/PurgarEscritorioUseCase.php`: remover `cobranca_revisao_pessoa_cobrada` da
  `ORDEM_DELECAO`.
- Testes a ajustar (remover asserts de revisão, manter o resto): `AlertasCobrancaTest`,
  `MontarDashboardCobrancaUseCaseTest`, `MontarCentralAlertasUseCaseTest`, `CobrancaBatchConsistenciaTest`,
  `CasoEncerradoBloqueiaMutacaoControllerTest`, `JudicializacaoCobrancaIsolamentoTenantTest`,
  `AcaoRevisaoMutacaoControllerTest` (este cobre AÇÃO + revisão — remover só os testes de revisão; se
  sobrar só ação, renomear/manter a parte de ação).

## Migration
- Nova migration: `DROP TABLE cobranca_revisao_pessoa_cobrada` (idempotente com `IF EXISTS`); `down()`
  recria a tabela (DDL espelhando `Version20260709191327`). Aplicar em prod no deploy do lote.

## Ordem de execução (sequencial, um commit atômico do item 4)
1. Editar integrações (AlertasCobranca → TipoAlerta → Dashboard/DTO → Detalhe/DTO → templates → purga).
2. Apagar backend dedicado + testes só-revisão; ajustar testes compartilhados.
3. Migration DROP.
4. Rodar `tests/Cobranca` + suíte global; corrigir; `/review`.

## Critério de conclusão
- Nenhuma referência a Revisão na UI (grep limpo em templates de cobrança, exceto o evento histórico legado).
- `tests/Cobranca` verde; suíte global verde; sem deprecations.
- `TipoEventoHistorico::RevisaoVinculo` preservado e documentado.
- Migration up/down validada no dev.
