---
tipo: novidade
---
## Changelog
- Chat com o BlueJus IA na Central: a conversa "BlueJus IA" aparece fixa no topo da lista (avatar do desenho, prévia e não lidas). Abre como as outras, com cabeçalho "Assistente · inteligência do BlueJus", sem ligar nem informações de grupo, e menu só com lida/não lida e silenciar.
- Perguntar: o campo envia por `POST /central/ia/perguntas`; enquanto a resposta não chega aparece "digitando..." e o campo fica travado (sem custo duplo). A resposta chega pelo polling como mensagem do BlueJus IA (selo IA, fundo verde claro, alinhada à esquerda). Pergunta que falha ou fica indisponível mostra o aviso fixo do servidor, nunca texto da IA.
- Estado vazio com três sugestões clicáveis e aviso fixo de que a resposta é gerada por IA e pode errar. Textos em `central.ia.*` (translations), entregues à tela por `data-ia-*`.
- A lista repete uma vez o pedido quando duas abas criam a conversa ao mesmo tempo (409).
- Sem migration.

## Para o cliente
- Na Central de Comunicação (página e janela flutuante) há uma conversa fixa com o BlueJus IA. Pergunte em linguagem natural; a resposta chega na própria conversa e conta como não lida se a janela estiver fechada. A resposta é gerada por IA e pode conter erros: confira antes de usar.
