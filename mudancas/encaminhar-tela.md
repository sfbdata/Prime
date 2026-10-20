---
tipo: novidade
---
## Changelog
- Tela "Despachar via Chat I.A" na janela da Central (desenho BC:6218-6238): cartão do item relido pelo servidor (`GET /central/encaminhar/item`), instrução, prioridade Normal, Urgente ou Para hoje e "Para quem?" só com quem vê a pasta; ao escolher, envia, abre a conversa e avisa "Despachado para NOME.".
- API da janela `window.BJCentral` (`abrir`, `despachar`, `conversar`) e os eventos `bj-central:despachar` e `bj-central:conversar`, onde o lançador flutuante está na página. Os itens de menu nas telas da pasta vêm nos próximos PRs.
- Mensagem de despacho mostra o chip "Pasta N" (BC:6041), só como texto por enquanto.
