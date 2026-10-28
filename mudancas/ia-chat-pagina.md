---
tipo: novidade
---
## Changelog
- Chat I.A em página inteira (`/chat-ia`), fase do servidor: conversas particulares por pessoa (criar, listar com busca, renomear, fixar, trocar o modelo e excluir), mensagens com resposta em fluxo (SSE), pasta citada com as mesmas travas do chat da Central (aceite, segredo de justiça), anexos em PDF, imagem e DOCX, título automático, teto diário de tokens por pessoa e por resposta.
- Provedor da Anthropic em fluxo (`stream: true`) e modelo por pedido (Haiku 5.5, Sonnet 5.5 e Opus 5.5).
- Comando `app:inteligencia:testar-conexao` e `scripts/servir-frente.sh`; migration `Version20261028170000` (tabelas `chat_ia_conversa`, `chat_ia_mensagem`, `chat_ia_anexo`).
- Chat I.A em página inteira, a tela (`/chat-ia`): layout próprio claro e escuro, barra lateral recolhível com histórico (Fixadas, Hoje, Últimos 7 dias, Mais antigas, buscar, renomear, fixar, excluir), resposta aparecendo aos poucos com markdown completo, botão Parar, Regenerar, Editar a última pergunta, Copiar, anexos (clipe, arrastar e colar), citar pasta (`#`) e seletor de modelo. Celular com a barra como gaveta.
- Chat I.A: editar a última pergunta mantém pasta e anexos; limite de ritmo ao criar conversa e anexar (e teto de 20 conversas vazias por pessoa); servidor de pré-visualização com 4 processos.
- Chat I.A: criar, renomear, fixar, trocar o modelo, excluir conversa e anexar ou remover arquivo ficam na trilha de auditoria (quem e quando), sem gravar o título, o nome do arquivo nem o texto das mensagens.
