---
tipo: correcao
---
## Changelog
- Dashboard da equipe com cadeado de pasta (spec pasta-cadeado, D1 e D3): as metas de pasta Oculta para quem olha saem do card "Metas ativas", da "Meta global batida" e das colunas de metas da tabela (total, ativas, vencidas, prazo próximo, período anterior, "Em revisão" e "Tempo médio"), como já saíam da lista /tarefas/equipe a que os números levam. Os ids das pastas ocultas vêm de AcessoAPasta::idsOcultosPara, numa consulta por requisição. O primário e quem tem nível na pasta continuam contando tudo.
- Tarefa::getPrazoInfo conta os dias pela RegraDoPrazo (dias de calendário, sem a hora gravada no prazo): um prazo de ontem às 23:59 não aparece mais como "Vence hoje" na tela da meta enquanto a Pasta mostra "Vencido há 1 dia". O formato do retorno (dias, cor, texto) não muda.

## Para o cliente
- No Dashboard da equipe, as metas de pastas com cadeado que você não pode ver deixam de entrar nos números.
- O prazo da meta passa a mostrar o mesmo "vence hoje" ou "atrasado" em todas as telas.
