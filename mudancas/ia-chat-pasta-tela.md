---
tipo: novidade
---
## Changelog
- Chat com o BlueJus IA na tela de uma pasta: a conversa mostra a barra azul "Pasta {NUP}" com o nome do cliente e o botão "Abrir pasta". Enquanto a barra existe, a pergunta leva o contexto da pasta (o servidor revalida acesso, escritório e segredo de justiça).
- Estado vazio ganha a sugestão "Faça um resumo desta pasta", só com a barra. As outras três continuam.
- Nova rota `GET /central/ia/pasta/{pastaId}` (só NUP, cliente principal e link; pasta inexistente, de outro escritório, excluída ou sem acesso responde o mesmo 404; sem cache).
- Pergunta recusada (segredo de justiça ou pasta sem acesso) mostra o aviso fixo do servidor; no 404 a barra some.
- Sem migration.

## Para o cliente
- Ao abrir uma pasta e conversar com o BlueJus IA, a barra no topo mostra qual pasta está em uso. Pergunte, por exemplo, "Faça um resumo desta pasta". Pastas em segredo de justiça não são lidas pela IA e a conversa avisa.
