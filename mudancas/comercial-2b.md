---
tipo: novidade
---
## Changelog
- Master: no bloco "Nova campanha de e-mail" do desenho 05, a escolha do público (Base de contatos, Usuários primários, Usuários secundários, Todos os cadastrados, Importar lista CSV e Individual), a contagem de destinatários sem repetidos e sem descadastrados, a frequência (envio único, semanal, quinzenal ou mensal), o agendamento e o botão "Enviar campanha", com confirmação.
- Master: bloco "Monitoramento" com a lista das 12 últimas campanhas (nome, modelo, público, data, total e estado); os números de entregas, aberturas e cliques ficam para a próxima fatia.
- E-mails: as campanhas saem pelo servidor em lotes de até 10 por minuto, com teto diário próprio (1.000 por padrão) e sem acumular fila; nada atrasado é recuperado (agendamento com mais de 24 h de atraso é cancelado, a recorrente pula para a próxima data).
- E-mails: no CSV e no Individual só entra quem tem conta ativa ou confirmou o endereço na lista de campanhas; a regra vale de novo a cada envio da recorrente.
- E-mails: a chave `COMERCIAL_CAMPANHAS_LIGADAS` nasce desligada (recusa o envio e, desligada, cancela as campanhas ativas) e o comando `app:comercial:encerrar-campanha <id>` encerra uma campanha sem deploy; o que já estava na fila não sai.
- LGPD: destinatários de campanha com mais de 24 meses ficam com o endereço em hash e sem o nome.
