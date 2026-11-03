---
tipo: alteracao
---
## Changelog
- Espelho R2 (frente D6): a exclusão de arquivo em categoria espelhada passa a apagar também a cópia no R2. A outbox grava `excluir`/`pendente` e o worker da réplica apaga o objeto (uma chave por vez, conferida por HEAD), a não ser que o arquivo tenha voltado ao disco. Exclusões em falha entram no alerta da conferência e no `reenfileirar-falhas`; as concluídas saem pela retenção de 90 dias.
- Agendador: `app:documentos:purgar-lixeira` roda todo dia às 0h50 (lixeira de 30 dias da aba Documentos).
- Prazo do deploy (decisão do Farlei, 03/11/2026): migration `Version20261103170000` cria `lixeira_purga_inicio` (1 linha, hora do deploy no fuso de São Paulo). O que já estava na lixeira conta os 30 dias a partir dela; até lá a purga não apaga nada e avisa a data (`aguardando_ate=` no log).

## Para o cliente
- Documentos que ficam mais de 30 dias na lixeira das pastas passam a ser apagados de vez, todo dia, automaticamente. O que já estava na lixeira antes desta versão ganha 30 dias contados a partir de hoje. Quando um arquivo é apagado de vez, a cópia de segurança dele na nuvem também é apagada. A lixeira e o aviso de exclusão passam a informar o prazo de 30 dias.
