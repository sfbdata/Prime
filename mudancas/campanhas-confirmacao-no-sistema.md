---
tipo: alteracao
---
## Changelog
- Painel do Master, Campanhas de e-mail: os botões de enviar, agendar, descartar, sair da edição, voltar modelo ao padrão, excluir modelo, ligar/desligar o envio, cancelar, remover e excluir campanha, e excluir descadastrado, deixam de usar o `window.confirm()` do navegador. Passam pela janela de confirmação do painel (`window.BJMasterConf.perguntar`, nova versão com Promise em `public/js/master/masters.js`).
- Textos novos (título e botão de cada janela) nos catálogos `comercial` e `comercial_relatorio`, em pt_BR e en. `.mp-conf-texto` ganha `white-space: pre-line` para manter o aviso de envio desligado em parágrafo próprio.

## Para o cliente
- Ao confirmar ações nas campanhas de e-mail, o painel agora mostra uma janela do próprio BlueJus, com o botão escrito com a ação (por exemplo "Enviar agora" ou "Excluir"), em vez da caixa cinza do navegador. Esc ou Cancelar fecham a janela sem fazer nada.
