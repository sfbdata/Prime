---
tipo: alteracao
---
## Changelog
- Conector de I.A.: a ferramenta `processos_da_pasta` ganha a ação `excluir_processo`, sempre por proposta e só depois do `confirmar_acao`. Apaga o processo do escritório inteiro (todas as pastas, partes, movimentações, documentos, notas técnicas e previsões). Exige o recurso `processo` de excluir, como a tela.
- A regra de `processo_delete` saiu do controller para o `ExcluirProcessoUseCase`, sem mudar o comportamento da tela.

## Para o cliente
- O assistente de I.A. conectado ao BlueJus agora pode excluir um processo, mas só depois que você aprovar o pedido, e o resumo avisa que não dá para desfazer.
- Só quem já pode excluir processos na tela consegue; processo de outro escritório ou em segredo de justiça continua fora do alcance do assistente.
- A tela de excluir processo funciona como antes.
