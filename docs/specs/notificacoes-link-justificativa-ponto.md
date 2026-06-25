# Spec — Destino dos links de notificação (com foco em ponto)

## Motivo
As notificações precisavam levar o usuário direto à página relacionada. O caso de
**justificativa de ponto** toca o domínio de ponto (risco ALTO), por isso esta spec.

## Destino por tipo de notificação

| Tipo | Destinatário | Destino do link |
|---|---|---|
| `ponto_justificativa_enviada` | gestores do tenant | aba de justificativas do colaborador: `app_tenant_user_edit_role` (`tenantId`, `id` = colaborador) + `?tab=justificativas` |
| `ponto_justificativa_abonada` / `_rejeitada` | colaborador | página de ponto do colaborador (`ponto_index`) |
| `servicedesk` | solicitante/responsável | `servicedesk_show` do chamado |
| `tarefa_*` | atribuídos | `tarefa_show` (via relação `tarefa`) |
| `evento_criado` / `evento_cancelado` (agenda) | participantes do evento | `agenda_show` do evento |

## Como o link é resolvido
- A notificação guarda o destino no campo `Notificacao.url` (para ponto/servicedesk),
  ou na relação `Notificacao.tarefa` (para tarefas).
- Renderização (dropdown e página): `url` → senão `tarefa_show` da tarefa → senão `#`.
  O HTML do dropdown via AJAX (`NotificacaoController::listaDropdown`) também passou a
  respeitar `url` (antes ignorava) e a escapar a saída (anti-XSS).

## Ponto — origem do link "enviada"
`PontoController` (envio de justificativa): após persistir as justificativas do
colaborador (`$user`), gera `urlGestor` = `app_tenant_user_edit_role(tenantId, id=$user, tab=justificativas)`
e chama `NotificacaoService::notificarJustificativaEnviada` para cada gestor com
permissão `admin.users.manage`. A página de destino é guardada por essa mesma
permissão — usuário sem acesso não consegue abrir, apenas vê a notificação.

## Não-objetivos
- Não altera o fluxo de aprovação/recusa de ponto em si, apenas o destino do link.

> **Atualização (jun/2026):** notificações de agenda **existem** — `AgendaController`
> cria `evento_criado`/`evento_cancelado` para os participantes, com link `agenda_show`.
> Antes usavam string literal incorreta (`'EVENTO_CRIADO'`); corrigido para as constantes
> `Notificacao::TIPO_EVENTO_CRIADO`/`..._CANCELADO`.
