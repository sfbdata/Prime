---
tipo: interno
---
## Changelog
- Novo domínio `Produto`: enum `Produto` (completo, voto, expediente, ia, ponto, calculo, kanban, assinatura, cobrancas), DTO `ClassificacaoDaRota` (núcleo, pública, plataforma ou produto) e `CatalogoDeProdutos` com título, ícone, rota inicial e se o dono pode ligar cada produto, mais o mapa do nome de cada rota para o produto que a abre (prefixo mais longo vence; rota sem mapa cai no Expediente). Ainda sem uso: a guarda que lê o mapa chega num PR seguinte, desligada.
- Teste de arquitetura `ProdutoDaRotaArquiteturaTest`: toda rota do router tem classificação explícita, os nomes citados existem, nenhum prefixo está morto e rota pública no catálogo é PUBLIC_ACCESS no security.yaml (e caminho aberto sem login nunca exige produto).

## Para o cliente
- Começamos a organizar o BlueJus em ferramentas (Expediente, Voto, I.A., Ponto, Cálculo, Kanban, Assinatura e Cobranças), para que cada escritório possa escolher, mais adiante, quais delas usa. Por enquanto as telas continuam iguais para todos.
