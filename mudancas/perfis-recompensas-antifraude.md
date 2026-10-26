---
tipo: novidade
---
## Changelog
- Painel do Master, seção "Recompensas": antifraude. Uma conta indicada fica "Em análise" e NÃO conta para a recompensa (nem como ativa, nem em carência) quando pertence ao mesmo escritório de quem indicou, tem o mesmo CNPJ de escritório ou o mesmo domínio de e-mail corporativo (gmail, hotmail, outlook, yahoo, icloud, bol e uol não valem). CPF e aparelho, que o desenho também cita, não são guardados pelo BlueJus e ficam para depois de a decisão de LGPD.
- Menu ⋮ do indicador ganha "Revisar suspeitas e aprovar" (só aparece com suspeita): mostra a quantidade e os motivos, sem nome nem e-mail de quem foi indicado, e aprova todas as pendentes de uma vez. A aprovação é por conta, fica na auditoria e repetir não grava de novo.
- Situações da lista, na ordem do desenho: Bloqueado, Em análise, Alerta (contas aceitas nos últimos 7 dias iguais ou acima do alerta do programa; só sinaliza), Liberado, em carência e Em andamento, com os motivos no balão.
- "Bloquear recompensa" e "Desbloquear recompensa": o indicador mostra "Bloqueado" e sai do filtro "A pagar"; saldo e extrato não mudam. "Apagar da lista" exige digitar APAGAR (conferido também no servidor): o indicador sai da lista e dos KPIs e o extrato continua guardado. Ainda não há tela para desfazer o "apagar" nem nova indicação o traz de volta.
- "Voltar à regra geral" não desfaz mais bloqueio nem apagado. Novas rotas só do Master, com CSRF: aprovar suspeitas, bloqueio e apagar.
- Nova tabela `aprovacao_de_conta_de_recompensa` e duas colunas nulas (`bloqueado_em`, `apagado_em`) em `condicao_de_recompensa`; nenhum dado existente é alterado. Ninguém é pago nesta entrega.

## Para o cliente
- Nada muda para os escritórios. Só o Master decide quais contas indicadas contam para a recompensa e vê o antifraude.
