---
tipo: novidade
---
## Changelog
- Pasta: vincular e desvincular cliente da pasta saíram do `PastaController` para `VincularClienteAPastaUseCase` e `DesvincularClienteDaPastaUseCase`, sem sessão; a tela passa a usá-los.
- Conector de I.A. (spec mcp-remoto-oauth, PR 11b): ferramenta `atualizar_cliente`. Direto: `completar` e `contato` em campo vazio, `vincular_a_pasta`, `principal` e `ligar_documento`. Por proposta e `confirmar_acao`: `cadastrar` (lote de 1 a 50, sem os já cadastrados e os repetidos no lote), troca de valor já preenchido e desvincular da pasta (`vincular_a_pasta` com `operacao: desvincular`). Cliente com envio automático do Push ligado recusa e-mail, vínculo, principal e o desvincular que o promoveria. A saída leva só ids, nomes e os nomes dos campos.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT podem completar o cadastro de um cliente: preencher o que está vazio, ligar o cliente a uma pasta, torná-lo o cliente principal e ligar ao cadastro um documento de identidade que já está na pasta.
- Cadastrar clientes novos, inclusive vários de uma vez (até 50), trocar uma informação que já estava preenchida e tirar um cliente da pasta só acontecem depois que você confirma o resumo que o BlueJus mostra. Quem já está cadastrado no escritório sai da lista antes, com o nome do cadastro existente.
- Se o cliente recebe o envio automático do Push, o assistente não troca o e-mail dele nem o liga a outra pasta: isso continua pela tela do BlueJus.
- As telas de vincular e desvincular cliente na pasta seguem iguais.
