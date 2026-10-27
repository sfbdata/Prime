---
tipo: novidade
---
## Changelog
- Painel BlueJus Intelligence, Modo avançado: cada alerta com ação sugerida (exceto o nível Normal) ganha o botão "Criar tarefa" (desenho 01, dc L967), só para quem acessa o módulo de tarefas.
- Como toda tarefa pertence a uma pasta, o botão abre antes um seletor de pasta (busca por número, cliente ou ação, a mesma do Push: só pastas do escritório, sem excluídas e só as que a pessoa edita no cadeado). Ao escolher, abre a pasta com o modal "Nova meta" já preenchido com a ação e o resumo do alerta, e com o responsável que o alerta nomeia marcado (quando ele está na lista do escritório). O prazo e a criação seguem as regras de sempre.
- Nova rota `GET /dashboard/inteligencia/pastas-para-tarefa` (módulos bi e tarefas, 403 sem eles) e `BuscarPastasParaTarefaDoAlertaUseCase`. Sem migration.

## Para o cliente
- Nos alertas do painel de Inteligência (Modo avançado) há o botão "Criar tarefa": escolha a pasta e a tarefa abre já preenchida com o texto do alerta. Revise o prazo e o responsável e crie.
