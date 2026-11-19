---
tipo: correcao
---
## Changelog
- Moldura (`base.html.twig`): o tooltip automático de células de tabela cortadas passa a ignorar quem já tem dica própria (`data-tip`, `data-bj-tip`, `data-tip-proprio`, `title` dentro da Central e o tooltip do Bootstrap), conferindo o alvo e o elemento sob o mouse. Acaba a dica dupla (preta do Bootstrap e azul-clara do desenho) na coluna Ação da lista do Expediente e nas outras telas com a dica do desenho.

## Para o cliente
- Ao passar o mouse num texto cortado da lista do Expediente, aparece só uma dica com o texto completo, a do novo visual, em vez de duas sobrepostas.
