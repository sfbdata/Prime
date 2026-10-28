---
tipo: novidade
---
## Changelog
- Chat I.A em página inteira (`/chat-ia`), fase do servidor: conversas particulares por pessoa (criar, listar com busca, renomear, fixar, trocar o modelo e excluir), mensagens com resposta em fluxo (SSE), pasta citada com as mesmas travas do chat da Central (aceite, segredo de justiça), anexos em PDF, imagem e DOCX, título automático, teto diário de tokens por pessoa e por resposta.
- Provedor da Anthropic em fluxo (`stream: true`) e modelo por pedido (Haiku 5.5, Sonnet 5.5 e Opus 5.5).
- Comando `app:inteligencia:testar-conexao` e `scripts/servir-frente.sh`; migration `Version20261028170000` (tabelas `chat_ia_conversa`, `chat_ia_mensagem`, `chat_ia_anexo`).
