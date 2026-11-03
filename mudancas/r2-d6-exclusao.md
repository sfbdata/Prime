---
tipo: alteracao
---
## Changelog
- Espelho R2 (frente D6): a exclusão de arquivo em categoria espelhada passa a apagar também a cópia no R2. A outbox grava `excluir`/`pendente` e o worker da réplica apaga o objeto (uma chave por vez, conferida por HEAD), a não ser que o arquivo tenha voltado ao disco. Exclusões em falha entram no alerta da conferência e no `reenfileirar-falhas`; as concluídas saem pela retenção de 90 dias.

## Para o cliente
- Quando um arquivo é apagado de vez do sistema, a cópia de segurança dele na nuvem também é apagada. A lixeira das pastas continua guardando os documentos, sem prazo.
