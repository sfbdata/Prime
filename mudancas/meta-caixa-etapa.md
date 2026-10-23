---
tipo: alteracao
---
## Changelog
- Pasta, Nova meta: campo obrigatório "Caixa / etapa", entre Prazo e Responsáveis (desenho 02 de 22/10, L179). Oito botões na ordem da esteira, com o nome que o escritório deu à etapa e o setor; a sugestão pelo título e pela descrição só pinta o botão e mostra a dica, sem marcar. A regra da sugestão sai do `EtapaDaPasta` por um data-attribute (fonte única). "Preencha:" cobra também a etapa, no fim.
- `tarefa_criar_para_pasta` exige `etapa` (valor do enum): ausente ou inválida responde 422; a etapa escolhida é gravada antes do PrePersist do título.
- Duplicar meta: a cópia herda a caixa / etapa da original.
- Sem migration (a coluna `tarefa.etapa` já existe).

## Para o cliente
- Ao criar uma meta na pasta, você escolhe em que caixa ela entra. O sistema sugere uma pelo título, e você confirma com um clique.
