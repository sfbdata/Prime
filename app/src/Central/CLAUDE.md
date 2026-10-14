# src/Central/ — Central de Comunicação

Spec: `docs/specs/central-comunicacao-a1.md` (A1: conversas 1:1) · proposta das próximas fases:
`docs/specs/central-comunicacao-proposta.md`. Namespace `App\Central` · prefixo de rota `central_` · **sem
permissão de módulo** (D3: usa quem tem vínculo ativo com o escritório).

## Regras inegociáveis

1. **Acesso é posse, não permissão.** `AcessoACentral::exigirVinculoAtivo` (vínculo ativo no escritório da
   sessão) + `AcessoACentral::participacao` (ser participante da conversa). Não use `PermissionChecker` aqui: ele
   tem bypass de super admin e de papel de sistema, e ninguém lê conversa alheia — nem o administrador.
2. **Conversa alheia é 404**, nunca 403 (`ConversaNaoEncontradaException`): não confirma que existe.
3. **Fora da auditoria.** `Conversa`, `Participante` e `Mensagem` não são `Auditavel`: o `audit_log` grava o estado
   inteiro e é lido por quem administra o escritório. Não ponha o texto da mensagem em log, notificação nem audit.
4. **Tenant explícito em toda consulta**, além do `TenantFilter`. A barreira de vínculo da plataforma
   (redireciona para `/escritorio/selecionar`) esconde a guarda da Central no teste HTTP — quem prova a guarda é
   `AcessoACentralTest`.
5. **Texto puro.** Twig escapa; `central.js` monta o DOM só com `textContent`/`createElement`. Nunca `innerHTML`.
6. **Polling, não tempo real (D2).** `GET /central/novidades?desde=` com cursor por id; `desde=0` só devolve o cursor.

## Modelo

`central_conversa` (par ordenado `usuario_menor`/`usuario_maior`, único por escritório) → `central_participante`
(cursor `ultima_lida_id`, nunca recua) → `central_mensagem` (índice `(conversa_id, id)`). Não lida = mensagem de
OUTRA pessoa acima do cursor; a própria nunca conta. As três tabelas estão na purga de escritório.

## Tela

`templates/central/_janela.html.twig` é o componente isolado (D6); `/central` o monta em linha. Para virar a janela
flutuante global, basta incluí-lo no layout — decisão do dono, junto com a frente `fidelidade-designer`.
