# Ponto: escritório explícito nas consultas da folha (auditoria 1.13)

Risco ALTO (ponto eletrônico). Origem: achado 1.13 da auditoria de segurança de 04/11/2026
(`bluejus-coordenacao/auditoria/J-diversos.md`).

## Problema

As batidas e justificativas que alimentam a folha, o espelho exportado (PDF e XLSX) e a ficha do
funcionário são buscadas só por usuário e período. O escritório fica a cargo do `TenantFilter`,
que é inerte fora de requisição (console, Messenger, Master sem escritório) e segue o escritório
da sessão ou da URL. Os métodos irmãos dos mesmos repositórios já filtram o escritório à mão
("Filtro de tenant explícito além do TenantFilter: é dado de ponto").

Não há exploração hoje: todos os chamadores rodam em requisição HTTP, com o filtro ligado no mesmo
escritório que usam para jornada e feriados. É defesa em profundidade.

## Contrato

1. `RegistroPontoRepository::findByUserAndCompetencia` e `RegistroPontoRepository::findBatidasDoDia`
   recebem `Tenant $tenant` e filtram `r.tenant = :tenant`.
2. `JustificativaPontoRepository::findByUserAndCompetencia`, `findByUserAndCompetenciaIndexed`
   (herda do anterior) e `findByTenantUser` recebem `Tenant $tenant` e filtram `j.tenant = :tenant`.
3. Todo chamador passa o MESMO `Tenant` que já usa para jornada, feriados e início da contagem.
   Chamadores conhecidos na auditoria (conferir com grep, a lista pode ter mudado):
   `PontoController`, `FolhaPontoBuilder`, `VerificadorAlertaPonto`, `EsquecimentosRegistrados`,
   `ObterEstadoDoPontoDoDiaUseCase`, `TenantController::editUserRole`.
4. Nenhuma mudança de comportamento visível: com o filtro ligado no escritório certo, o resultado
   é o mesmo de hoje.
5. Sem migration, sem mudança de entidade, sem mudança de rota.

## Critérios de aceite

- Teste de repositório com o filtro `tenant` DESLIGADO: usuário com batidas em A e B;
  `findByUserAndCompetencia(user, A, ano, mes)` devolve só as de A. Idem `findBatidasDoDia`.
- Mesmo teste para `JustificativaPontoRepository::findByUserAndCompetencia`,
  `findByUserAndCompetenciaIndexed` e `findByTenantUser`.
- Teste funcional do export PDF e XLSX de colaborador com batidas em dois escritórios: só as do
  escritório da sessão entram.
- Teste funcional da ficha `/tenant/{A}/user/{id}/edit-role` com o mesmo colaborador: só as
  justificativas de A.
- PHPStan nível 5 e a suíte do domínio Ponto verdes.

## Fora do escopo

Outros repositórios do Ponto que já filtram o escritório; telas; regras de jornada.
