---
tipo: novidade
---
## Changelog
- "Encaminhar via Chat I.A" nos registros da pasta (desenho 02, D:1405, D:1936, D:2463, D:6305, D:6869 e D:6958): botão ↪ espelhado no cartão de registro das abas Dados, Detalhes e Financeiro, item no menu do registro (Dados e Financeiro, depois de Copiar texto), no ⋮ da observação de Detalhes (entre Copiar texto e Citar no novo registro) e no ⋮ do arquivo do Financeiro (depois de Copiar nome do arquivo). O clique dispara `bj-central:despachar` com `{pastaId, tipo, itemId}` (`public/js/pasta-encaminhar.js`); só aparece com a Central disponível e fora da pasta excluída.

## Para o cliente
- Agora dá para encaminhar um registro ou um arquivo do Financeiro a um colega pelo Chat I.A, direto do cartão ou do menu.
