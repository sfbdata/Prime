---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 15): página "Como conectar o Claude ou o ChatGPT" no Meu Perfil (`conector_perfil_guia`, `/perfil/conexoes-de-ia/como-conectar`), pela convenção do perfil, com link no cartão "Conexões de I.A." e no cartão do conector do administrador. Mostra a URL exata do recurso (`ServidorDeAutorizacao::RESOURCE`), o passo a passo do claude.ai e do app Claude (Free, Pro, Max, Team e Enterprise), do Claude Code e do ChatGPT (só Business, Enterprise e Edu; gravar, Plus e Pro e a loja ficam "em breve"), a orientação de nunca usar "Always allow" na `confirmar_acao`, "Skip approvals" nem o modo "Auto", o que é a proposta e como desconectar. "O que dá para pedir" sai das ferramentas registradas (`GuiaDoConector`), sem a `confirmar_acao`.

## Para o cliente
- No Meu Perfil, o cartão Conexões de I.A. ganhou o botão "Como conectar", com o passo a passo para usar o BlueJus de dentro do Claude ou do ChatGPT, com a sua conta.
- A página mostra o endereço exato do conector, como adicionar o BlueJus no Claude (site, app no computador e Claude Code) e no ChatGPT dos planos Business, Enterprise e Edu, e a lista do que dá para pedir.
- Ela também explica como o BlueJus pede a sua confirmação antes de apagar ou trocar um dado, e quais opções do app não usar para que a confirmação seja sempre sua.
- O administrador do escritório encontra o mesmo passo a passo no cartão do conector, na tela BlueJus IA, para repassar à equipe.
- Pelo ChatGPT, por enquanto, o BlueJus serve para consultar. Gravar pelo ChatGPT chega em breve.
