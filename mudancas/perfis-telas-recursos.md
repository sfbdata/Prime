---
tipo: novidade
---
## Changelog
- Painel do Master, nova aba "Telas e recursos" (desenho Perfis 1.2): o Master liga e desliga, para todas as contas, 19 itens do catálogo (10 telas, 7 itens do menu do usuário e 2 funções). Desligar pede o motivo exibido aos usuários e, opcionalmente, a data em que volta sozinho; religar é um clique. Expediente (lista de pastas) e Meu Perfil são essenciais e não desligam.
- Nova tabela global recurso_estado (uma linha por chave; sem linha = ativo). Desligado com data volta a valer como ativo no dia seguinte, sem tarefa agendada.
- Quem não é Master deixa de ver o item desligado (menu lateral, barra de atalhos, menu do usuário, botão Peticionar, BlueJus Intelligence da pasta) e, ao abrir a rota de uma tela desligada, recebe a página "em preparação" (503); chamadas de API da mesma função recebem 503 em JSON. O link público de convite do Trabalho Externo e a Política de Privacidade continuam abertos.
- O Master continua vendo tudo: o item desligado aparece apagado com contorno vermelho tracejado e a tela desligada mostra uma faixa vermelha fixa.
- Cada desligar e religar vai para a trilha de auditoria do painel como "Desativou X" e "Reativou X".

## Para o cliente
- Telas em ajuste podem aparecer como "em preparação" por alguns dias, com a previsão de volta quando houver.
