---
tipo: correcao
---
## Changelog
- Ponto Eletrônico, ficha do colaborador: a janela "Incluir batida" (`abrirModalAddBatida`, `tenant/edit_user_role.html.twig`) abre com o motivo vazio a cada vez, como as janelas Desconsiderar e Restaurar. Teste `FichaJanelaIncluirJsTest`.
- Comentários de `AjusteDeBatidaController` e `IncluirBatidaUseCase` passam a citar "Incluir batida", o nome que a ficha mostra.

## Para o cliente
- Na ficha do colaborador, ao incluir uma batida que faltou, a janela abre com o campo do motivo em branco, sem repetir o motivo da inclusão anterior.
