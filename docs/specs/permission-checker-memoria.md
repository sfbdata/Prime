# PermissionChecker: menos consultas por página (risco MÉDIO)

Origem: medição de queries por página (05/11/2026). O layout, que inclui a sidebar e o `base.html.twig` via
`PermissionExtension`, faz cerca de 55 consultas por página. Dois pontos do `PermissionChecker` respondem por
quase tudo:

1. `resolveUserTenant()` faz `findOneBy(user, tenant)` a cada chamada. São de 22 a 34 consultas iguais por
   página. Repetir o `findOneBy` não economiza nada: o Doctrine só pula o banco quando a busca é pela chave
   primária.
2. `hasPermissionFromUserTenant()` percorre `TenantRole::getTenantRolePermissions()`. Cada
   `getPermission()` carrega uma `Permission` com uma consulta própria. Para o colaborador comum são cerca de
   20 consultas por request, também nas chamadas AJAX (sino, alerta do ponto, lembretes).

## Regra inegociável

O resultado de toda checagem fica **idêntico** ao atual: os mesmos bypasses (super admin global, perfil de
sistema), o vínculo ativo, o cadeado, o `ResourceAccess` e a permissão do papel. Nenhum filtro de tenant,
permissão ou cadeado muda. Só cai o número de idas ao banco.

## Desenho

### D1. Vínculo guardado para o request

- Chave: `id do usuário : id do escritório`. Sem id (entidade não persistida), nada é guardado.
- Fica guardada só a **entidade encontrada**. "Não encontrado" não é guardado: a próxima chamada consulta de
  novo. Assim um vínculo criado no meio do request é visto.
- A cada uso da entrada guardada:
  - o `isActive()` é lido **ao vivo** na entidade. Um vínculo desativado no mesmo request recusa na hora;
  - `UserTenantRepository::continuaGerenciado()` confirma que a entidade ainda é gerenciada pelo
    EntityManager. Se ela foi removida (`remove`), ficou solta (`clear`/`detach`) ou o EntityManager foi
    trocado, a entrada sai e o banco é consultado de novo.
- O papel (`getTenantRole()`) e as permissões dele continuam lidos ao vivo na entidade. Trocar o papel no
  mesmo request vale na hora.
- `PermissionChecker implements ResetInterface`: a memória zera entre requests e entre mensagens do worker do
  Messenger (tag `kernel.reset` por autoconfiguração; o worker reseta os serviços a cada mensagem).

### D2. Permissões do papel numa consulta só

- `TenantRolePermission::$permission` passa a `fetch: 'EAGER'`. A coleção do papel carrega as `Permission`
  junto, numa consulta, em vez de uma por item.
- A checagem continua percorrendo a coleção ao vivo. Permissão incluída ou retirada no mesmo request vale como
  hoje.
- É só o modo de carregar: a tabela não muda e não há migration.

### Limite conhecido

Um `DELETE` em SQL cru de `user_tenant` (só a purga do escritório, `PurgarEscritorioUseCase`) não tira a entidade
do EntityManager. Se o mesmo processo conferir a permissão daquela pessoa naquele escritório depois da purga e
antes do `reset()`, a memória ainda devolve o vínculo apagado. A purga não confere permissão depois do `DELETE`,
e o escritório inteiro deixa de existir.

## Testes

- **Unitário:** a segunda chamada não consulta o banco; a entrada que não é mais gerenciada é consultada de
  novo; vínculo desativado recusa; "não encontrado" não é guardado; `reset()` limpa a memória.
- **Funcional, equivalência:** para cada pessoa (sistema, colaborador com permissões, colaborador sem vínculo,
  vínculo inativo, super admin) e cada código de `Permission`, o `hasPermission` com a memória é igual ao
  resultado com `reset()` antes de cada chamada.
- **Funcional, invalidação no mesmo request:**
  - desativar o vínculo;
  - remover o vínculo;
  - trocar o papel;
  - incluir ou retirar uma permissão do papel.
- **Funcional, trava de consultas:** o número de queries do layout numa página simples (Meu Perfil), para o
  primário e para o colaborador, não passa do teto medido depois da mudança.

## Fora deste PR

Cadeado e `RegraDosPrimarios`, Central, preferências, `BloqueioPorPedidoDeExclusao`, sino e N+1 de cada
tela. Ficam nos PRs 2, 3 e 4 da medição.
