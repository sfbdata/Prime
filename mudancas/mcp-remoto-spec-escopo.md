---
tipo: interno
---
## Changelog
- Leva à spec do conector de I.A. por MCP remoto (docs/specs/mcp-remoto-oauth.md) o escopo do piloto e as decisões do dono de 07 a 09/11/2026: tudo de Pastas, Agenda, Clientes e Processos menos funções de administrador, em 17 ferramentas (7 de leitura, 9 de gravação e 1 de confirmação); teto próprio sem os bypasses do Master e do primário; cadastro de cliente e toda exclusão só com proposta confirmada no chat; liberação em três níveis (chave geral, escritório liberado pelo Master, aceite próprio do conector); token de 1 hora com renovação até 7 dias; Claude e ChatGPT no piloto; todos os tipos de documento menos áudio e vídeo; 17 PRs com uma migration só. Só documentação.

## Para o cliente
- Avançamos no planejamento do conector que vai permitir usar o BlueJus de dentro do Claude e do ChatGPT: ficou definido o que ele vai poder fazer nas pastas, na agenda, nos clientes e nos processos, e que cadastrar cliente ou excluir qualquer coisa só acontece depois que você confirmar na própria conversa. Ainda não está disponível.
