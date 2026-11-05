---
tipo: correcao
---
## Changelog
- Ponto: escritório explícito nas consultas da folha (auditoria de segurança, lote J, item 1.13; spec `docs/specs/ponto-escritorio-explicito-na-folha.md`):
  - `RegistroPontoRepository::findByUserAndCompetencia` e `findBatidasDoDia` recebem o `Tenant` e filtram `r.tenant = :tenant`.
  - `JustificativaPontoRepository::findByUserAndCompetencia`, `findByUserAndCompetenciaIndexed` e `findByTenantUser` recebem o `Tenant` e filtram `j.tenant = :tenant`.
  - Os chamadores passam o mesmo escritório que já usam para jornada e feriados: `PontoController` (folha, espelho em PDF e XLSX, aviso de horário), `FolhaPontoBuilder` (saldo do mês e anual), `VerificadorAlertaPonto`, `EsquecimentosRegistrados`, `ObterEstadoDoPontoDoDiaUseCase` e a ficha `TenantController::editUserRole`.
  - Defesa em profundidade: antes o escritório dependia só do `TenantFilter`, que não age fora de requisição. Sem migration, sem mudança de entidade nem de rota.

## Para o cliente
- A folha de ponto, o espelho em PDF e em planilha e a ficha do funcionário passam a buscar batidas e justificativas sempre só do escritório aberto, também nas rotinas automáticas. Quem trabalha em dois escritórios vê, em cada um, só as batidas e justificativas daquele escritório.
- Os avisos automáticos de ponto esquecido e de horário passam a olhar só o escritório de onde o aviso sai.
