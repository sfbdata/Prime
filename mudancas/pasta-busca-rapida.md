---
tipo: novidade
---
## Changelog
- Busca rápida na página da pasta (Ctrl + K): janela do desenho 02 Expedientes que acha pasta pelo número ou pela ação, cliente pelo nome ou CPF/CNPJ (com ou sem pontuação) e processo pelo número (todos os vinculados), sem acento e sem caixa. Até 8 resultados, só das pastas que a pessoa pode ver; pasta trancada vem com cadeado.
- Rota nova `GET /expediente/busca-rapida?q=` (`expediente_busca_rapida`), JSON, exige o módulo Pastas.

## Para o cliente
- Dentro de uma pasta, aperte Ctrl + K para buscar outra pasta pelo número, pelo nome ou CPF do cliente ou pelo número do processo, e abrir com Enter.
