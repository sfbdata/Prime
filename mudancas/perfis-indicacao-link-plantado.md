---
tipo: correcao
---
## Changelog
- Link de indicação `/i/{codigo}`: o código só é guardado na sessão quando a pessoa abre o link como página (navegação). Requisição vinda de `<img>`, `<iframe>` ou `fetch` de outro site deixa de atribuir a conta nova a quem indicou.

## Para o cliente
- Nada muda para quem clica no link de indicação. Fecha uma brecha por onde alguém poderia plantar o link em outro site para ser contado como indicador.
