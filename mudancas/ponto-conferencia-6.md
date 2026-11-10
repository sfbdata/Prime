---
tipo: correcao
---
## Changelog
- Ponto Eletrônico, ficha do colaborador: a janela "Incluir batida" (`abrirModalAddBatida`, `tenant/edit_user_role.html.twig`) abre com a hora e o motivo vazios a cada vez, como as janelas Desconsiderar e Restaurar já limpam o motivo. Teste `FichaJanelaIncluirJsTest`.
- Comentários de `AjusteDeBatidaController` e `IncluirBatidaUseCase` passam a citar "Incluir batida", o nome que a ficha mostra.

## Para o cliente
- Na ficha do colaborador, ao incluir uma batida que faltou, a janela abre com a hora e o motivo em branco, sem repetir o que foi digitado antes. Isso evita incluir a batida no horário errado.
