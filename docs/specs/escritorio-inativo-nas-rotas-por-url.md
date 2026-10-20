# Escritório suspenso ou excluído nas rotas que recebem o escritório pela URL

Risco ALTO (identidade Tenant e ponto eletrônico). Pedido do coordenador em 20/10/2026, a partir da revisão
cruzada do #125 feita pela b5 (item 3). Base: master. Sem migration.

## Defeito

O `TenantContextValidatorListener` barra a conta suspensa (`isActive=false`) ou excluída (em quarentena) só
quando ela é o escritório **da sessão**. As rotas que recebem o escritório no caminho (`/tenant/{tenantId}/...`:
equipe, cargos, ponto manual, justificativas, horas pagas, jornada, home office, feriados, sedes, editar) agem
sobre o escritório da URL, não o da sessão. Por isso, o admin do escritório A suspenso que tem acesso ao
escritório B entra com B na sessão e continua mexendo na equipe e no **ponto** de A.

O defeito vale para a suspensão pelo Master, que já está em produção (spec `perfis-1.2-contas.md` C1, C2).

## Regra

**R1.** Se uma rota age sobre um escritório que não está ativo (`Tenant::isActive() !== true`: suspenso, ou
excluído e em quarentena), ela recusa quem não é Master.

**R2.** O escritório da rota é:
- o parâmetro `tenantId` do caminho, em qualquer rota que o tenha;
- o parâmetro `id`, nas rotas cujo caminho começa no próprio escritório: `app_jornada_tenant_get`,
  `app_jornada_tenant_save` (`/tenant/{id}/jornada`) e `escritorio_excluir` (`/escritorio/{id}/excluir`).

**R3.** O Master (`RegraDosMasters::ehMaster`) passa. Ele é quem suspende, reativa e decide a exclusão, e
precisa ver a conta. A barreira da sessão (C12, sem bypass) continua como está.

**R4.** `escritorio_sair` fica fora. Sair de um escritório tira o próprio acesso, e tirar acesso nunca trava.

**R5.** A recusa segue o padrão do `BloqueioDeRecursoDesativado`:
- chamada de API (método não seguro, `Accept: application/json` ou XHR) recebe **403 JSON** `{erro}`;
- tela recebe **403** com a página "Escritório indisponível".

O texto é o aviso de conta suspensa ou excluída (`AvisoDeContaSuspensa`), o mesmo da barreira da sessão.

**R6.** Escritório inexistente não é decidido aqui: a rota segue e o controller responde 404, como hoje.

## Onde

Um listener único, `App\Tenant\EventListener\BarreiraDoEscritorioInativoNaRota`, em `kernel.request`
com prioridade 3: depois do roteamento (32), do firewall (8), da barreira da sessão (6) e do
`TenantUrlScopeListener` (4), que só escopa os dados no `{tenantId}` e não autoriza. Não depende de cada
controller lembrar de checar.

## Testes

- Funcional, por rota: as 42 rotas com escritório no caminho. Com A suspenso e B na sessão, o admin de A
  (não Master) recebe a recusa. Com A ativo, a mesma chamada não passa pela recusa.
- Excluído (em quarentena): recusado do mesmo jeito.
- Master: passa com A suspenso.
- `escritorio_sair`: passa com A suspenso.
- Arquitetura: toda rota com `tenantId` no caminho é coberta (nova rota entra sozinha), e a lista do R2 só tem
  nomes que existem.

## Fora

- Item 7 do #125 (`existePendente` em toda página): anotado pelo coordenador.
- Perguntas (a), (b) e (c) da revisão: vão ao pacote do dono.
