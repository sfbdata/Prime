---
tipo: correcao
---
## Changelog
- Pasta: o número digitado à mão no "Editar dados" e na proposta de pasta do Chat I.A passa pela mesma trava por escritório da numeração automática e da restauração (`GerarNumeroDePasta::recusaDoNumero`, `NumeracaoDePasta::emUsoPorOutraPasta`). Número de outra pasta viva é recusado com `NumeroDePastaEmUsoException`; a pasta na lixeira e a de outro escritório não ocupam número. Só o número que muda é conferido, então a pasta do legado que já divide o número segue editável. Acervo e Drive continuam aceitando o número repetido da origem. A recusa sai da transação como valor, para não fechar o EntityManager. No Chat I.A, volta 422 no campo `pasta.nup` e a proposta segue pendente.

## Para o cliente
- Ao trocar o número de uma pasta em "Editar dados", o sistema confere se outra pasta do escritório já usa esse número. Se usa, a troca não é feita e aparece o aviso "O número N já é de outra pasta do escritório. Escolha outro número."
- A mesma conferência vale quando o Chat I.A propõe uma pasta com número: o aviso aparece no campo do número, e é só trocar e confirmar de novo.
- Duas pessoas trocando para o mesmo número ao mesmo tempo não terminam mais com duas pastas iguais.
- Pastas na lixeira não contam: o número delas fica livre.
