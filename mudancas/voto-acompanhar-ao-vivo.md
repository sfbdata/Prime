---
tipo: novidade
---
## Changelog
- Voto: caixa "Acompanhar" abaixo do cartão de cada pauta na cédula do eleitor, com o estado da votação, "N de M já votaram" e barra de progresso, atualizada sozinha a cada 10 s por `GET /votar/{token}/pauta/{pauta}/participacao` (só contagens, sem voto por opção; 404 para token ou pauta de outra assembleia). Para de perguntar com a aba oculta e quando a pauta encerra.

## Para o cliente
- Quem vai votar agora pode acompanhar a votação ao vivo: abaixo de cada pauta aparece quantas unidades já votaram, com uma barra que cresce suavemente conforme os votos chegam, sem precisar atualizar a página. O resultado por opção continua só aparecendo depois que a votação encerra, para manter o sigilo.
