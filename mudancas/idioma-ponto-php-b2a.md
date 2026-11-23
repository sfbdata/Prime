---
tipo: alteracao
---
## Changelog
- Ponto: as recusas do ajuste de batida, da análise e da edição de justificativa, da troca de anexo e do abono em lote da equipe passam pelo tradutor, com a chave na exceção (`RecusaDoPonto`, `TextoDoPonto`). Os avisos do Twig que liam a constante (`constant()`) passam a ler o catálogo. O português fica idêntico, cada recusa é conferida contra o texto antigo.

## Para o cliente
- No Ponto, os avisos ao corrigir uma batida, analisar ou editar uma justificativa, trocar o anexo e abonar justificativas da equipe passam a aparecer em inglês para quem usa o sistema em inglês. Em português, os textos seguem os mesmos.
