---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 5): contrato do `/mcp`. Rotas e caminhos do `/mcp` e do metadado do recurso em `ServidorDeAutorizacao`; `TipoDeFerramenta`; `CatalogoFechadoDoConector` com as 17 ferramentas (7 de leitura, 9 de gravação e a `confirmar_acao`), títulos, anotações MCP explícitas, `_meta` com `securitySchemes` e a `ScopePolicy` do SDK (`mcp:escrita` para gravar e confirmar); interface `FerramentaDoConector` (tag `conector.ferramenta`), `ChamadaDoConector`, `RespostaDaFerramenta`, `ErroDaFerramenta`, `RequisicaoDoConector` (por requisição, com `reset`) e `RegistroDasFerramentas`.
- `security.yaml`: firewall `conector_mcp` (`^/mcp$`, sem sessão, autenticador de bearer) e acesso público ao `/mcp` e ao metadado do recurso nas duas URLs. `framework.yaml`: limites `conector_chamada`, `conector_chamada_escritorio`, `conector_documento` e `conector_confirmacao`. Parâmetro `conector.mcp_hosts` (`CONECTOR_MCP_HOSTS`, padrão `bluejus.com.br`).
- Rotas do `/mcp` e do metadado do recurso nas listas do `TenantContextValidatorListener` e do `TermoAceiteListener`; o `/mcp` sem o filtro de escritório da sessão (`TenantFilterListener`). O `IdiomaDaRequisicaoListener` não toca a sessão em requisição sem sessão.
- nginx de produção: `location ^~ /.well-known/oauth-` antes do bloqueio de arquivos ocultos, que barrava os metadados OAuth com 403. O deploy confere e recarrega o nginx sozinho.
- O `/mcp`: autenticação pelo token de acesso a cada pedido (tipo, validade, `resource`, conferência da concessão e o portão do escritório), limite de chamadas por pessoa e por escritório, servidor do SDK MCP sem sessão, metadado RFC 9728 nas duas URLs, log `mcp` de uma linha por pedido sem dado pessoal, e a primeira ferramenta, `quem_sou_eu`.
- Listeners de sessão (`ExpiracaoDaSessaoListener`, `SessaoEncerradaEmTodosOsAparelhosListener`) e a pasta da rota não tocam a sessão em rota sem sessão.

## Para o cliente
- O BlueJus abriu a porta por onde o Claude e o ChatGPT vão conversar com o seu escritório. Por enquanto o app de I.A. só consegue perguntar "quem sou eu" e ver a pessoa e o escritório conectados; consultar e gravar dados chega nas próximas versões, primeiro num escritório piloto. Cada pedido do app passa pelas mesmas regras de acesso da pessoa no sistema.
