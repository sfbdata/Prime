---
tipo: novidade
---
## Changelog
- Lixeira do Expediente: "Abrir a lixeira da pasta" abre ali mesmo o modal da lixeira da pasta (Restaurar, Excluir de vez, selecionados), sem ir para a pasta. A cada leitura da lista a linha da pasta é refeita com a contagem nova (ou sai, se a lixeira dela esvaziou).
- O modal da lixeira saiu do `pasta-explorador.js` para `pasta-lixeira.js` (o mesmo na pasta e no Expediente); o mapa de tipos de arquivo foi para `pasta-tipos-de-arquivo.js`. Sem mudança de comportamento na aba Documentos.
- Abrir arquivo excluído: nova rota `pasta_documentos_lixeira_abrir` (GET `/pasta/{id}/documentos/lixeira/{documento}`), só leitura, com a permissão da lixeira da pasta (EDITAR), tenant conferido, contrato oculto respeitado e o arquivo lido pela chave (vale o espelho no R2). No modal, o nome do arquivo e o botão "Abrir" abrem numa aba nova.

## Para o cliente
- Na Lixeira do Expediente, cada pasta abre a lixeira dela na mesma tela, com as mesmas opções de restaurar e excluir de vez, sem precisar sair da página.
- Na lixeira (da pasta e do Expediente), dá para abrir o arquivo excluído numa aba nova para conferir antes de restaurar ou excluir de vez. Abrir não tira o arquivo da lixeira.
