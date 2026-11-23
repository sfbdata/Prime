---
tipo: correcao
---
## Changelog
- Expediente, lista de pastas: a coluna do número da pasta (NUP) passa de 74px para 110px e o número, o ícone de compartilhar e o cadeado voltam a ficar lado a lado, sem quebrar linha (`flex-wrap: nowrap` em `.exp-nup-cel`). A correção M5.7 (#869) deixava os ícones descerem para baixo do número e a linha da tabela ficava muito alta.

## Para o cliente
- Na lista de pastas do Expediente, o número da pasta, o ícone de compartilhar e o cadeado voltam a aparecer lado a lado, na mesma linha. As linhas da tabela voltam à altura normal e nada invade a coluna do cliente.
