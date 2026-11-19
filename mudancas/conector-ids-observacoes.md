---
tipo: alteracao
---
## Changelog
- Conector de I.A.: a `ler_pasta` passa a mostrar `[registro_id N]` em cada observação das partes `detalhes` e `financeiro`, tirado da assinatura que o `MontadorDeContextoDaPasta` já monta. Com isso o `registrar_na_pasta` responde, edita, destaca e exclui qualquer observação do relatório inicial e do relatório financeiro (spec, decisão 33).

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT passam a conseguir responder, corrigir ou destacar qualquer anotação do relatório inicial e do relatório financeiro da pasta, e não só as que eles mesmos escreveram. Corrigir e excluir continuam pedindo a sua confirmação antes de gravar.
- O que cada pessoa vê continua igual ao da tela: quem não vê o Financeiro da pasta não recebe nada dele.
