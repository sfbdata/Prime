---
tipo: novidade
---
## Changelog
- Painel do Master: seção "Central de Notificações" (desenho 05 Perfis 1.2, VS 21.10.26) com as sugestões do Contribua de todos os escritórios, as mensagens de suporte e os alertas de indicação numa caixa só: abas Recebidas, Não lidas, Com estrela, Respondidas e Arquivadas, busca, seleção em lote e páginas de 50.
- Estado por Master (lida, estrela, arquivada) em `central_master_estado` e histórico de respostas compartilhado em `central_master_resposta` (migration `Version20261020147500`); as duas tabelas entram na purga do escritório.
- Responder: por e-mail pelo Carteiro (transacional, origem `central_master:resposta`, sem citar a mensagem original) ou, sem e-mail, como aviso na Central do autor; a sugestão respondida fica atendida com a resposta. Alerta não aceita resposta.

## Para o cliente
- Sugestões e mensagens enviadas ao BlueJus agora recebem resposta direto da equipe, por e-mail ou por aviso no sistema.
