---
tipo: alteracao
---
## Changelog
- Importar pastas em lote: a pergunta D11 aparece sempre que a pasta escolhida tem subpasta com arquivo (antes, só com arquivo solto e subpasta juntos). Texto com os nomes e a contagem: "A pasta "X" tem N subpastas (A, B, C...). Criar N pastas, uma por subpasta, ou 1 pasta só, "X", com tudo?". Botões "Criar N pastas" (primeiro) e "1 pasta só, "X"". O botão da IA aparece junto da pergunta.
- `scripts/lote-teste/gerar.py` segue a regra nova (pergunta, texto e resposta certa em cada cenário).

## Para o cliente
- Ao importar pastas em lote, quando a pasta escolhida tem subpastas, o BlueJus agora sempre pergunta: criar uma pasta para cada subpasta, ou uma pasta só com tudo. Assim a pasta de um cliente organizada em "Documentos" e "Processo" não vira mais dois clientes por engano.
