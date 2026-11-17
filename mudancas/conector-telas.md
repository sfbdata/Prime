---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 8): telas pela convenção do sistema. Cartão "Conexões de I.A." no Meu Perfil (lista as conexões da pessoa em todos os escritórios e revoga, com confirmação); cartão "Conector de I.A." na aba "Configurações da I.A." do Master (liberar ou bloquear por escritório, com a contagem de conexões ativas); cartão no `/admin/inteligencia` para o administrador ligar o conector com o aceite ou desligar.
- UseCases `ListarConexoesDaPessoa`, `RevogarConexao`, `AlterarLiberacaoDoConectorPeloMaster`, `ObterConectorNoPainelDoMaster`, `AlterarConectorDoEscritorio` e `ObterConectorDoEscritorio`; controllers `ConexoesNoPerfilController`, `ConectorDoEscritorioController` e `ConectorDoMasterController`; textos novos em `translations/conector/`.
- Desligar ou bloquear o conector suspende sem derrubar a conexão: a renovação do token não confere mais a liberação, e o `/mcp` responde 403 "conector desligado" sem desafio, em vez de 401. Religar volta a valer sem reconectar; o limite de 7 dias continua correndo.
- Serviço `SemFiltroDoEscritorio`: o único lugar do conector, fora do portão, que suspende o filtro de escritório (nunca o liga).

## Para o cliente
- No Meu Perfil, um cartão novo mostra os apps de I.A. (Claude, ChatGPT) conectados à sua conta, em cada escritório, com a data da conexão e o último uso. Dá para desconectar qualquer um: o BlueJus pede uma confirmação antes.
- O administrador do escritório liga ou desliga o conector de I.A. na tela da BlueJus IA, depois de ler e aceitar os termos. Desligar suspende na hora: os apps conectados param de acessar os dados, e voltam a funcionar quando o conector é religado, sem precisar conectar de novo.
- Por enquanto o conector só é liberado para o escritório piloto.
