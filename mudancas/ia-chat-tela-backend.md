---
tipo: novidade
---
## Changelog
- Chat com o BlueJus IA na Central, parte do servidor: a conversa com a IA passa a aparecer só para o próprio dono (lista de conversas, histórico, não lidas e novidades). A lista sempre traz a conversa e a cria na primeira vez. Mensagem comum nessa conversa é recusada: nela só se pergunta.
- Rotas novas: `POST /central/ia/perguntas` (faz a pergunta; 201 nova, 202 se já há uma em andamento; só devolve ids e estado, nunca o texto) e `GET /central/ia/perguntas/{id}` (estado da pergunta, só do próprio usuário). Mesmo limite de pedidos por pessoa da aba Perguntar da pasta.
- A Equipe e os destinos do Encaminhar seguem sem a conversa com a IA: o uso do chat é pessoal e não aparece a colegas.
- Pergunta sem resposta há mais de 10 minutos (fila parada) deixa de bloquear o chat: a próxima pergunta a encerra como falha e segue.
- Sem migration.

## Para o cliente
- Nada muda na tela ainda. Quando o chat com o BlueJus IA abrir na Central, só você verá a sua conversa; ninguém do escritório vê o que você pergunta.
