---
tipo: correcao
---
## Changelog
- Conector de I.A.: o `DespachoDasFerramentas` converte em array, em profundidade e na entrada, todo objeto aninhado dos argumentos (o SDK do MCP entrega `\stdClass`). Hoje nenhuma ferramenta do master aceita objeto no esquema (o SDK já recusa com -32602 antes do despacho), então nada muda para elas; a conversão vale para os campos de objeto que entram a partir da `atualizar_cliente`. Testes pelo `/mcp` com `atualizar_pasta` e `registrar_na_pasta`.

## Para o cliente
- O conector de I.A. fica pronto para receber pedidos com mais de um nível de informação, como os dados de um cadastro numa só chamada. As ferramentas que o Claude e o ChatGPT já usam seguem iguais.
