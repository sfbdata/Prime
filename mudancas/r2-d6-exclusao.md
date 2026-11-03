---
tipo: alteracao
---
## Changelog
- Espelho R2 (frente D6): a exclusão de arquivo em categoria espelhada passa a apagar também a cópia no R2. A outbox grava `excluir`/`pendente` e o worker da réplica apaga o objeto (uma chave por vez, conferida por HEAD), a não ser que o arquivo tenha voltado ao disco. Exclusões em falha entram no alerta da conferência e no `reenfileirar-falhas`; as concluídas saem pela retenção de 90 dias.
- Agendador: `app:documentos:purgar-lixeira` roda todo dia às 0h50 (lixeira de 30 dias da aba Documentos).

## Para o cliente
- Documentos que ficam mais de 30 dias na lixeira das pastas passam a ser apagados de vez, todo dia, automaticamente. Quando um arquivo é apagado de vez, a cópia de segurança dele na nuvem também é apagada. A lixeira e o aviso de exclusão passam a informar o prazo de 30 dias.
