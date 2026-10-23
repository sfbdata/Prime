# Escritório suspenso ou excluído nas rotas que recebem o escritório pela URL

Risco ALTO (identidade Tenant e ponto eletrônico). Pedido do coordenador em 20/10/2026, a partir da revisão
cruzada do #125 feita pela b5 (item 3). Base: master. Sem migration.

## Defeito

O `TenantContextValidatorListener` barra a conta suspensa (`isActive=false`) ou excluída (em quarentena) só
quando ela é o escritório **da sessão**. Parte das rotas que recebem o escritório no caminho autoriza pelo
vínculo com o escritório da URL, sem olhar a sessão: no `TenantController` (equipe, editar com cargos e lotações,
papel e nome do colaborador, remover, ponto manual, justificativas, anexo, acessos, sedes), no
`HorasPagasController`, no `TenantUserProfileController` (dados pessoais) e no `escritorio_excluir`. Por isso, o
admin do escritório A suspenso que tem acesso ao escritório B entra com B na sessão e continua mexendo na equipe e
no **ponto** de A.

As demais (perfis, feriados, jornada e home office) já exigiam que o escritório da URL fosse o da sessão, e aí a
barreira da sessão recusava: nelas, esta barreira só troca a recusa genérica pelo aviso de conta suspensa.

O defeito vale para a suspensão pelo Master, que já está em produção (spec `perfis-1.2-contas.md` C1, C2).

## Regra

**R1.** Se uma rota age sobre um escritório que não está ativo (`Tenant::isActive() !== true`: suspenso, ou
excluído e em quarentena), ela recusa quem tem vínculo ativo com ele e não é Master. Quem não tem vínculo segue
para o controller, que já o recusa como sempre (403/404 genérico): o nome e a situação da conta não vazam para
fora do escritório.

**R2.** O escritório da rota é:
- o parâmetro `tenantId` do caminho, em qualquer rota que o tenha;
- o parâmetro `id`, nas rotas cujo caminho começa no próprio escritório: `app_jornada_tenant_get`,
  `app_jornada_tenant_save` (`/tenant/{id}/jornada`) e `escritorio_excluir` (`/escritorio/{id}/excluir`).

**R3.** O Master (`RegraDosMasters::ehMaster`, o único jeito de detectá-lo aqui) passa, inclusive vinculado ao
escritório suspenso e agindo pela URL com outro escritório na sessão (regra do dono: "o Master é dono do sistema e
pode fazer tudo"). A barreira da sessão (C12) continua barrando o Master sem exceção: a diferença entre as duas
vai ao pacote do dono.

**R4.** `escritorio_sair` fica fora. Sair de um escritório tira o próprio acesso, e tirar acesso nunca trava.
Toda exceção fica numa lista explícita (`ROTAS_FORA_DA_BARREIRA`), com o motivo: um teste percorre o roteador e
falha para toda rota `/tenant/{...}` ou `/escritorio/{...}` que não esteja coberta pelo `tenantId`, pelo R2 ou
por essa lista.

**R5.** A recusa:
- chamada de script (XHR, `Accept: application/json` ou corpo JSON) recebe **403 JSON** `{erro}`;
- tela e formulário enviado pela tela recebem **403** com a página solta "Escritório indisponível" (sem o
  layout do sistema: nesse ponto o filtro de dados já está preso no escritório da URL).

O texto é o aviso de conta suspensa ou excluída (`AvisoDeContaSuspensa`), o mesmo da barreira da sessão.

**R6.** Escritório inexistente não é decidido aqui: a rota segue e o controller responde 404, como hoje.

**R7.** Conta "suspensa a pedido" (pedido de exclusão pendente, `BloqueioPorPedidoDeExclusao::suspensaAPedido`;
23/10, Segurança C). O Tenant segue ativo, mas a rota recusa do mesmo jeito quem tem vínculo ativo e não é
Master, **primário inclusive**: na sessão a equipe já não entra e o primário fica só no Meu Perfil, onde desiste
(`/perfil/...`, fora desta barreira). O texto é `BloqueioPorPedidoDeExclusao::MENSAGEM`, o mesmo da barreira (b)
da sessão. R1, R3, R4 e R5 valem iguais. Pedido decidido (desistência, reativação) volta a passar.

## Onde

Um listener único, `App\Tenant\EventListener\BarreiraDoEscritorioInativoNaRota`, em `kernel.request`
com prioridade 3: depois do roteamento (32), do firewall (8), da barreira da sessão (6) e do
`TenantUrlScopeListener` (4), que só escopa os dados no `{tenantId}` e não autoriza. Não depende de cada
controller lembrar de checar.

## Testes

- Funcional, por rota: as 43 rotas com escritório no caminho (40 com `tenantId` e as 3 do R2). Com A suspenso
  e B na sessão, o admin de A (não Master) recebe a recusa. Com A ativo, a mesma chamada não passa pela recusa.
- Usuário sem vínculo com A: não vê o nome nem a situação de A (segue para o controller).
- Chamada de script: 403 JSON.
- Excluído (em quarentena): recusado do mesmo jeito.
- Master: passa com A suspenso.
- R7: com pedido de exclusão pendente em A (ainda ativo), as 43 rotas recusam o admin não primário; o primário
  também é recusado (JSON na chamada de script); sem vínculo não vê a situação; pedido desistido volta a passar.
- `escritorio_sair`: passa com A suspenso.
- Arquitetura: toda rota com `tenantId` no caminho é coberta (nova rota entra sozinha), e a lista do R2 só tem
  nomes que existem.

## Fora

- Item 7 do #125 (`existePendente` em toda página): anotado pelo coordenador.
- Perguntas (a), (b) e (c) da revisão: vão ao pacote do dono. Notas da revisão cruzada da b5: o primário de
  conta suspensa não exclui o escritório, só o Master; `trabalho_externo_solicitacao` (escritório no corpo, só
  Master) fica fora, junto com o Trabalho Externo.
- Trabalho Externo: o Convidado age, sem escritório, sobre a demanda de um escritório suspenso (rotas
  `trabalho_externo_*`, liberadas sem escritório na barreira da sessão). É o mesmo tipo de defeito, sem o
  escritório na URL: fica registrado para uma frente própria.
