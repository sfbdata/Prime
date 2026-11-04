---
tipo: novidade
---
## Changelog
- Editor rico (registro da pasta, Detalhes, Financeiro, nota técnica, anotação de cobrança, despacho da meta): botão "Inserir tabela" (ícone `bi-table`, ao lado de "Inserir símbolo"), com o módulo `table` do Quill 2. Grade para escolher linhas e colunas (até 8 × 8); dentro da tabela, inserir linha acima ou abaixo, coluna à esquerda ou à direita, remover linha, coluna ou a tabela. Sem mesclar células.
- Sanitizador `textoRico`: passa a aceitar `table`, `tbody`, `tr` e `td` (só o atributo `data-row`); `style`, `colspan`, `rowspan` e `th` continuam fora.
- Tabela com borda clara no texto exibido (inclusive no tema escuro), no resumo para impressão e PDF e no extrato da meta.
- Textos derivados (sino, cartão encaminhado à Central, relatório de registros, linha do tempo, leitor de prazos, determinações do juízo) separam as células em vez de grudá-las.

## Para o cliente
- O editor de texto dos registros e observações ganhou o botão "Inserir tabela": escolha quantas linhas e colunas quer e depois inclua ou remova linhas e colunas quando precisar. A tabela aparece no registro, no resumo para impressão e no PDF.
