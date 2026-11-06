# Memória do vínculo ativo no UserTenantRepository

Risco: MÉDIO (lê o vínculo User/Tenant; não grava nem muda regra). Etapa 2 da revisão de queries (05/11/2026).

## Problema

`findAtivoPorUserETenant(user, tenant)` roda de 4 a 7 vezes por página só no layout: `NivelNoCadeado`,
`CentralExtension`, `ChaveDoPontoEletronico`, `PublicoDosAvisosDePonto`, `LeituraDasPastasDosAvisos`,
`PoderDeEdicaoSemPrazo`, `GuardaDaExclusaoPeloPrimario`. São ~90 chamadores no total; mexer em cada um
não compensa. A consulta é por usuário + escritório, não por id, então o identity map do Doctrine não ajuda.

## Regra

1. Memória no próprio repositório, por requisição, chave `userId:tenantId`. Só guarda vínculo **achado**.
   "Não achou" sempre consulta de novo (vínculo criado no meio da requisição aparece).
2. Na batida, só devolve o vínculo guardado se a consulta devolveria o mesmo:
   - continua no EntityManager (`contains`: falso depois de `remove`+flush, `clear` ou `detach`);
   - o `isActive` **do banco** é true: dado original do UnitOfWork, que o flush atualiza.
   A consulta de hoje filtra pelo banco e devolve a entidade do identity map; as duas conferências
   reproduzem isso. Por isso, desativar sem flush continua devolvendo o vínculo (como hoje), e com flush some.
   Se não bate, descarta a entrada e consulta.
3. `existeVinculoAtivo` usa a mesma memória na batida; sem batida, o COUNT de sempre.
4. `ResetInterface` (tag `kernel.reset` por autoconfiguração): zera no fim da requisição e entre mensagens
   do worker do messenger.
5. User e tenant são `readonly` no `UserTenant`: a chave não muda.

Fora (riscos teóricos, sem caminho no código hoje):
- DELETE por SQL cru no `user_tenant`: só `PurgarEscritorioUseCase` (comando de purga), que não relê o vínculo
  depois. Código novo que apague ou altere `user_tenant` por DQL/SQL e releia no mesmo processo chama `reset()`.
- Flush que grava o `is_active` e depois sofre rollback: o Doctrine já atualizou o dado original. Hoje nenhum
  fluxo relê o vínculo depois de um rollback (o EntityManager fecha na exceção do flush).
- Outra conexão mudando o banco no meio da requisição: igual ao que o `PermissionChecker` já aceita.

## Testes

- `UserTenantRepositoryMemoriaTest`: equivalência com e sem memória (ativo, suspenso, outro escritório, sem
  vínculo, em duas voltas); N leituras = 1 consulta; criado depois aparece; desativar sem flush igual à
  consulta; desativar com flush some; reativar volta; remover e `clear`; reset do kernel.
- `QueriesDoLayoutTest`: teto remedido (40 e 37).
