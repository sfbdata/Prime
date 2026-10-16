# Central de Comunicação: o ⋮ do desenho com os comandos reais (F3)

Pedido do dono em 16/10/2026 (print do ⋮ do protótipo): "faz uma solução para as pendências ... para deixar mais pronto
possível". Merge no `master` só depois da aprovação dele na demo. Risco: BAIXO/MÉDIO (uma tabela nova, só da Central;
"Limpar lidas" apaga notificação do sino da própria pessoa, com confirmação).

## 1. Itens (`bluejus-central.js` L6244-6293)

| Item do desenho | Aqui | Como |
|---|---|---|
| Nova conversa | sim | abre a lista de pessoas (on-line / off-line), como no desenho (`ui.verOnline`) |
| Novo grupo, Ver/Ocultar arquivadas | já existiam | |
| Ver resolvidas antigas (N) / Ocultar | sim | avisos resolvidos há mais que a janela; faixa verde "Mostrando resolvidas antigas" (L5785) |
| Resolvidas ficam na caixa por: 1 hora / 1 dia / 1 semana | sim | preferência deste navegador (`resJanelaH`, padrão 1 dia) |
| Marcar todas como lidas | sim | servidor: todos os avisos da Caixa e todas as conversas ativas (cursor no fim; as menções delas como lidas, regra A2) |
| Ver todas as notificações | sim | Caixa de entrada, filtro Tudo (L6263) |
| Status, Não perturbe, Som, Alertas visuais, Tamanho do texto | já existiam | |
| Volume (Baixo/Médio/Alto) | sim | ganho do som 0,45 / 1 / 1,7 (L44); mudar toca uma vez |
| Avisar (Todas/Importantes) | sim | Importantes: salto e som só para nível ≥ 2 (menção, alerta, meta nova...; mensagem comum é nível 1, L140) |
| Enter envia a mensagem | sim | desligado: Enter quebra linha e Ctrl/⌘+Enter envia (L6130) |
| Limpar notificações lidas | sim | confirmação "Apagar N notificação(ões) já lida(s)? Esta ação não poderá ser desfeita." (L6283); apaga só as lidas da Caixa da pessoa neste escritório, pelo serviço do sino |
| Ajuda | sim | "Como funciona", com o que existe de verdade aqui |
| Central de Atenção, Governança e acessos, Ocorrências do controller | **não** | no desenho são painéis de dados fictícios / IA (D1, D3) |
| Receber toques, Receber ligações | **não** | toque e ligações não existem (próximas etapas) |
| Excluir todas as conversas | **não** | ocultar/excluir conversa não tem regra aprovada (D5) |

## 2. Dar baixa (resolver) um aviso (L5603-5630, `resolverNotif`)

- Tabela `central_aviso_resolvido` (tenant, usuário, notificação única, `resolvida_em`): a baixa é da PESSOA, na Central;
  o sino não muda (a notificação não ganha coluna). Apagar a notificação apaga a baixa (CASCADE).
- Resolver marca o aviso como lido; reabrir apaga a baixa. Só o próprio aviso da Caixa, neste escritório (404 senão).
- Cartão resolvido: ícone verde redondo com ✓, selo "RESOLVIDA", título riscado, "Baixa por Você · <quando> · sai da
  caixa em N h", esmaece com a idade; depois da janela some (fica em "Ver resolvidas antigas"). Botão de baixa rápida
  no canto (aparece no hover/foco): resolver / reabrir. Menu de botão direito: Resolver / Reabrir.
- Fora: suspensão de baixas aceleradas, "Baixa por" outra pessoa e o aviso à Controladoria (simulação, D3).

## 3. Testes

PHPUnit: resolver/reabrir (posse, tenant, gestão fora, CSRF, marca lido); marcar tudo (avisos + cursores + menções, só
deste escritório, não toca de outra pessoa); limpar lidas (só lidas, só da Caixa, só minhas, deste escritório); isolamento
com filtro desligado. Playwright: itens do ⋮ (só os reais, na ordem do desenho), cartão resolvido igual ao desenho,
Volume/Avisar/Enter envia, Ajuda.
