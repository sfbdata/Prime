---
tipo: alteracao
---
## Changelog
- Restaurar pasta excluída sempre funciona: se o número antigo já é de outra pasta do escritório, a pasta volta com o próximo número livre (maior número + 1), e o histórico registra "antes era N". A rota `pasta_restaurar` responde JSON para o fetch da Lixeira do Expediente e avisa o Drive só quando o número mudou.
- A Lixeira do Expediente lista todas as pastas excluídas, mesmo sem documentos na lixeira, com o botão "Restaurar pasta" para quem pode excluir a pasta. A janela de restaurar do cabeçalho avisa quando o número ficou ocupado.

## Para o cliente
- Agora dá para restaurar qualquer pasta excluída. Se outra pasta já ficou com o número dela, a pasta volta com o próximo número livre, e o histórico mostra qual era o número de antes.
- A Lixeira do Expediente passa a mostrar todas as pastas excluídas, também as que não têm documentos na lixeira, com o botão "Restaurar pasta".
- Antes de restaurar, o sistema avisa quando o número da pasta já é de outra e qual número ela vai receber.
