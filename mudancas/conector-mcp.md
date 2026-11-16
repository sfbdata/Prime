---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 5): contrato do `/mcp`. Rotas e caminhos do `/mcp` e do metadado do recurso em `ServidorDeAutorizacao`; `TipoDeFerramenta`; `CatalogoFechadoDoConector` com as 17 ferramentas (7 de leitura, 9 de gravação e a `confirmar_acao`), títulos, anotações MCP explícitas, `_meta` com `securitySchemes` e a `ScopePolicy` do SDK (`mcp:escrita` para gravar e confirmar); interface `FerramentaDoConector` (tag `conector.ferramenta`), `ChamadaDoConector`, `RespostaDaFerramenta`, `ErroDaFerramenta`, `RequisicaoDoConector` (por requisição, com `reset`) e `RegistroDasFerramentas`.
- `security.yaml`: firewall `conector_mcp` (`^/mcp$`, sem sessão, autenticador de bearer) e acesso público ao `/mcp` e ao metadado do recurso nas duas URLs. `framework.yaml`: limites `conector_chamada`, `conector_chamada_escritorio`, `conector_documento` e `conector_confirmacao`. Parâmetro `conector.mcp_hosts` (`CONECTOR_MCP_HOSTS`, padrão `bluejus.com.br`).
- Rotas do `/mcp` e do metadado do recurso nas listas do `TenantContextValidatorListener` e do `TermoAceiteListener`; o `/mcp` sem o filtro de escritório da sessão (`TenantFilterListener`). O `IdiomaDaRequisicaoListener` não toca a sessão em requisição sem sessão.
- nginx de produção: `location ^~ /.well-known/oauth-` antes do bloqueio de arquivos ocultos, que barrava os metadados OAuth com 403. O deploy confere e recarrega o nginx sozinho.

## Para o cliente
- O BlueJus começa a preparar a porta por onde o Claude e o ChatGPT vão conversar com o seu escritório, com as regras de quem pode ler e quem pode gravar. A conexão em si chega nas próximas versões, primeiro num escritório piloto.
