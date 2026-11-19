---
tipo: novidade
---
## Changelog
- Cliente: a busca de clientes da pasta (`pasta_clientes_buscar`) saiu do `PastaController` para o `BuscarClientesUseCase`, sem sessão; a tela passa a usá-lo, com a mesma guarda, os mesmos vinculados, o limite de 10 e a máscara do documento.
- Conector de I.A. (spec mcp-remoto-oauth, PR 11c): ação `buscar` da `ler_clientes`, pelo trecho do nome ou pelo CPF ou CNPJ inteiro, com o escopo do acesso restrito e o recurso `cliente` de ver. Até 20 clientes, com `mais_resultados`; sai só id, nome e tipo, sem documento.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT podem procurar um cliente do escritório pelo nome ou pelo CPF ou CNPJ, e só acham os clientes que a pessoa já vê no BlueJus. O CPF e o CNPJ dos clientes não aparecem na resposta.
- A busca de cliente na pasta (o "Adicionar cliente" e a busca do cartão Clientes) segue igual.
