---
tipo: novidade
---
## Changelog
- Busca rápida na página da pasta (Ctrl + K): janela do desenho 02 Expedientes que acha pasta pelo número (ou "Pasta <número>"), cliente pelo nome ou CPF/CNPJ (com ou sem pontuação) e processo pelo número (todos os vinculados), sem acento e sem caixa. Até 8 resultados, só das pastas do alcance da pessoa. Pasta trancada pelo cadeado para quem busca aparece com o cadeado e só com número, cliente e CPF/CNPJ: casa só por eles (nunca pelo processo nem pela ação) e abre na tela "Pasta trancada", com o pedido de acesso de lá.
- Rota nova `GET /expediente/busca-rapida?q=` (`expediente_busca_rapida`), JSON, exige o módulo Pastas.

## Para o cliente
- Dentro de uma pasta, aperte Ctrl + K para buscar outra pasta pelo número, pelo nome ou CPF do cliente ou pelo número do processo, e abrir com Enter.
