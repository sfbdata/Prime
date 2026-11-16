---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 3): base do servidor de autorização próprio. Constantes do servidor (`ServidorDeAutorizacao`: issuer, resource, escopos, nomes de rota), `ErroOAuth` (códigos da RFC 6749 com descrição fixa), `SegredoOpaco` (256 bits em base64url, banco guarda só o SHA-256), `ImpressaoDaSenha` (HMAC do hash da senha com o segredo da aplicação) e `LiberacaoDoConector` (os três níveis da decisão 14.a: chave geral, escritório liberado pelo Master, ligado com o aceite vigente).
- Registro do cliente por CIMD: interface `RegistroDeClientes` com a recusa `ClienteRecusado` (motivo e host, sem redirecionar) e a implementação `RegistroDeClientesPorCimd`.
- Chave `conector_ia` nas Funções do catálogo de telas e recursos do Master, sem rota; a rota de ligar e desligar chave do Master passa a aceitar sublinhado.
- Motivo novo `codigo_reutilizado` na revogação da concessão; troca de escopos no consentimento ao reautorizar; busca do código com a linha travada (`FOR UPDATE`).
- Rotas do `/oauth/*` nas listas do `TenantContextValidatorListener`, do `TermoAceiteListener` (sem o authorize) e do `TenantFilterListener` (o authorize e o token, sem o filtro da sessão); `/oauth/authorize` como destino do login.
- Tela de autorização `/oauth/authorize`: valida o pedido do app (PKCE S256, `resource`, `redirect_uri` da lista, `iss` em todo retorno), guarda o pedido na sessão para uso único, lista os escritórios da pessoa e grava consentimento, concessão e código; comando `conector:liberar <escritório> --master=<e-mail>` para o escritório piloto.
- Troca do código e da renovação no `/oauth/token` (rotação com janela de 60 s, renovação reapresentada derruba a concessão, rotações em fila pela concessão) e metadado RFC 8414 em `/.well-known/oauth-authorization-server`.
- Registro do cliente por CIMD com busca segura (sem rede interna, sem redirecionamento, teto de tamanho e tempo), cache pelos cabeçalhos e uso do documento gravado quando a renovação falha.
- Faxina diária `app:conector:faxinar` no Scheduler (01:10): códigos, tokens, concessões e propostas vencidos.
- `security.yaml`: acesso público só ao `/oauth/token` e ao metadado RFC 8414. `framework.yaml`: limites `conector_token` e `conector_cimd`. Parâmetro `conector.cimd_hosts` (`CONECTOR_CIMD_HOSTS`, padrão `claude.ai,chatgpt.com`).

## Para o cliente
- No Painel do Master, a aba de telas e recursos ganhou a chave "Conector de I.A.", que liga e desliga para todos os escritórios a conexão do BlueJus com o Claude e o ChatGPT. A conexão em si chega nas próximas versões, primeiro num escritório piloto.
- Já existe a tela em que cada pessoa autoriza o Claude ou o ChatGPT a acessar o BlueJus, escolhendo o escritório e se o app pode só ler ou também gravar. Ela ainda não aparece para ninguém: só abre quando o app de I.A. pede a conexão, e isso depende da próxima etapa.
