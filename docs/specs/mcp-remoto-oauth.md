# Conector de I.A. (MCP remoto com OAuth 2.1) para todos os escritórios

**Data:** 11/08/2026 (desenho original). **Revisão:** 08/11/2026, conferida contra o master `a53a77b1c` (v2.42.0).
**Risco:** **ALTO**. Mexe em identidade (`User`/`Tenant`) e abre um caminho novo de acesso aos dados de todos os
escritórios, fora do navegador. Pelo ciclo do projeto: spec (este documento), revisão contra a spec e **re-revisão antes de
seguir** em cada PR de identidade.
**Status:** desenho revisado em 08/11/2026, não implementado. Não existe código, tabela nem migration desta feature.

**Predecessor:** [MCP de investigação em produção (v1)](../superpowers/specs/2026-08-10-mcp-investigacao-prod-design.md),
em produção; a seção 10 dele previa esta etapa.
**Relacionadas:** `chat-ia-cadastro.md`, `chat-ia-prazos-e-metas.md`, `chat-pagina-inteira.md`, `designer-central-ia.md`
(D9 "token pessoal por usuário" e Fase 4 "conector MCP": esta spec é o caminho delas), `ia-anthropic-ligar.md`,
`inteligencia-provedor-anthropic.md`, `pasta-cadeado.md`, `docs/AUTORIZACAO.md`.

Arquivo:linha citados foram conferidos em 08/11/2026 no commit acima. Páginas da web foram lidas em 07 e 08/11/2026; quando
a página mostra data, ela vem ao lado do link.

---

## 0. O que mudou de 11/08 para cá

| Ponto | Em 11/08/2026 | Em 08/11/2026 |
|---|---|---|
| Objetivo | ler e operar o que a conta permite | o mesmo, com o foco do Samuel: ler documentos e dados, automatizar rotinas, **criar peças e minutas**, acompanhar prazos (seção 2) |
| `TenantFilter` | falha aberto | **continua** falhando aberto; há três listeners de tenant no `kernel.request` e nenhuma trava fail-closed (4.1) |
| Tenant explícito sem sessão | não existia | é o padrão do worker da I.A., do Chat I.A e do Agente de Pastas; o Agente foi escrito pensando neste conector (4.2) |
| `PermissionChecker` | 4 métodos | 8 públicos, cache por vínculo e cadeado da pasta; `canActOnResource` saiu em 30/06; `resources.*` está vivo (5) |
| Guardas em listener HTTP | não citadas | cadeado, aba oculta, pasta excluída, recurso desligado pelo Master e barreiras do escritório: o conector pula todas e tem de refazer (5.2) |
| Ferramentas | 5 de leitura e 3 de escrita, desenhadas sem base | 12 de leitura e 5 de escrita, quase todas sobre UseCase que já existe (6) |
| Cliente e Processo | "legado sem UseCase" | `Processo` está no domínio; Cliente tem 8 UseCases; Tarefa continua legado (7) |
| nginx | "chega ao Symfony sem mudança" | **errado já em agosto**: o `location ~ /\. { deny all; }` do HTTPS barra `/.well-known/oauth-*` (3.3) |
| `mcp/sdk` | 0.7.0 | 0.7.1 instalada; o DCR por delegação existe desde a 0.5.0 (`CHANGELOG.md`, "Add OAuth 2.0 Dynamic Client Registration middleware"), e a versão instalada também tem elicitation do lado do servidor e anotações `readOnlyHint`; continua sem servidor de autorização (3.1) |
| `symfony/mcp-bundle` como servidor de autorização | proposta aberta | PR fechado sem merge em 12/07/2026; segue o `league` (3.2) |
| `league/oauth2-server-bundle` | "testado até Symfony 8.0" | v2.0.0 (out/2026) pede Symfony `^7.4` ou `^8.1` e PHP `^8.2`: serve (3.2) |
| Especificação MCP | 2025-06-18, DCR recomendado | a atual é **2026-07-28**: CIMD é o preferido, DCR está **deprecated**, protocolo sem estado (3.4, 3.5) |
| Clientes | só o Claude em mente | Claude, ChatGPT e Gemini pesquisados; cada um aceita um jeito de registro (3.4) |
| Auditoria | "usar o domínio Auditoria" | o `AuditLogSubscriber` tira o escritório da sessão: com bearer, grava sem escritório se nada for feito (4.3) |
| Decisões em aberto | 2 | 6 (seção 14) |

---

## 1. O que existe hoje, e por que não serve

A v1 está em produção: `app/src/Mcp/` (`Command/McpServerCommand.php`, `Service/ConexaoLeitura.php`,
`Tool/ConsultarSqlTool.php`, `Tool/DescreverEsquemaTool.php`). O comando `mcp:server` (`McpServerCommand.php:30`) fala MCP
por **STDIO**, lançado por `ssh bluejus 'docker exec -i -w /var/www/app jusprime_php_prod php bin/console mcp:server'`, com
duas ferramentas: `consultar_sql` (SQL livre) e `descrever_esquema`. O `mcp/sdk` está fixado em `0.7.1`
(`app/composer.json:32`).

Ela foi desenhada para **um operador: o dono**. Três propriedades a tornam inadequada para outros usuários, e nenhuma é
acidental:

1. **`consultar_sql` lê o banco inteiro.** Sem filtro de tenant, sem `PermissionChecker`, sem UseCase. Decisão consciente da
   spec da v1. Com um segundo usuário conectado, deixa de ser recurso e vira vazamento de dado de todos os escritórios.
2. **O transporte é SSH como `root` na VPS.** Quem tem a chave é dono da máquina. Não é distribuível.
3. **Não há identidade.** O servidor não sabe quem pergunta, só que alguém alcançou o container.

Duas portas novas surgiram depois de agosto, e também não servem como estão:

- **Agente de Pastas** (`/api/agente/*`, `app/src/Inteligencia/Controller/AgenteDePastasController.php`, 14/10/2026): JSON de
  leitura para o Designer. Autentica por **sessão** (firewall `main`, `app/config/packages/security.yaml:83`), tira o
  escritório da sessão e exige o módulo `expediente` (`AgenteDePastasController.php:99`). Os UseCases dele já recebem
  `User` e `Tenant` explícitos "porque o conector MCP da Fase 4 roda sem sessão" (`app/src/Inteligencia/CLAUDE.md`, seção
  Agente de Pastas). São reaproveitados aqui (6.3).
- **Chat I.A** (`/chat-ia`, `app/src/ChatIa/`): o modelo é o da Anthropic, contratado pelo BlueJus, e as ferramentas
  (`propor_cliente`, `propor_pasta`, `consultar_prazos_e_metas`) rodam dentro do nosso servidor. O conector é o inverso: o
  modelo é o da conta da pessoa (Claude, ChatGPT, Gemini) e o BlueJus só responde às ferramentas. As peças de ferramenta do
  chat são reaproveitadas (6.7).

**Consequência de projeto, e é a decisão mais importante deste documento:** a versão remota **não é a v1 com transporte
novo**. É outro conjunto de ferramentas. `consultar_sql` **não vai para o servidor remoto** (6.1). A v1 continua, por STDIO e
SSH, só para o dono.

---

## 2. Objetivo

Nas palavras do Samuel (07/11/2026): *"um conector para sistemas de I.A como ChatGPT, Claude e Gemini para o usuário poder
usar o agente para acessar documentos e dados que o próprio usuário tem acesso, para automatizar rotinas do sistema, criar
peças, minutas, acompanhar prazos etc."*

Em termos de sistema: qualquer pessoa com conta e vínculo ativo num escritório conecta o assistente de I.A. dela ao BlueJus,
entra **como ela mesma**, escolhe o escritório, e o assistente passa a:

- **ler** pastas, processos e movimentações, prazos e metas, agenda, documentos da pasta e modelos de peça;
- **criar** minutas e peças como documento da pasta (a partir de um modelo ou redigidas pelo assistente) e, depois, as
  escritas que a decisão 14.b escolher;

sempre dentro do que a conta dela já permite na interface web, nem mais, nem menos.

Critérios de aceitação centrais:

1. **Um usuário do escritório A não consegue, por nenhum caminho, ler ou alterar dado do escritório B.** Provado por teste
   cross-tenant em cada ferramenta, não por inspeção.
2. **Nenhuma ferramenta devolve o que a tela não mostraria à mesma pessoa**, nem o que as regras da I.A. proíbem sair
   (segredo de justiça, dado do cliente além do nome, financeiro sem permissão).

---

## 3. Arquitetura

```
Cliente de I.A. (Claude, ChatGPT, Gemini)
   │ 1. POST /mcp sem token                          → 401 + WWW-Authenticate (resource_metadata, scope)
   │ 2. GET /.well-known/oauth-protected-resource/mcp  (RFC 9728)
   │ 3. GET /.well-known/oauth-authorization-server    (RFC 8414)
   │ 4. registro: client_id = URL do CIMD | POST /oauth/register (DCR) | pré-registrado
   │ 5. GET /oauth/authorize (PKCE S256, resource=)  → login + escolha do escritório + consentimento
   │ 6. POST /oauth/token (form-urlencoded)          → token com aud = https://bluejus.com.br/mcp
   │ 7. POST /mcp (Authorization: Bearer <token>)    → ferramentas
   ▼
nginx (bluejus.com.br, TLS)                ← precisa de dois ajustes (3.3)
   ▼
Symfony: a mesma aplicação, dois papéis
   ├── Servidor de autorização → league/oauth2-server-bundle + extensões nossas (CIMD, DCR, resource, iss, escritório)
   └── Servidor de recurso (MCP) → mcp/sdk StreamableHttpTransport + validador nosso
   ▼
Portão do conector: usuário, escritório, vínculo e barreiras conferidos; filtro de tenant ligado e conferido (4.1)
   ▼
UseCases e serviços existentes, com tenant e permissão explícitos
```

### 3.1 O que o `mcp/sdk` 0.7.1 dá, e o que falta

Instalado: `mcp/sdk` **0.7.1** (`app/composer.json:32`, `app/composer.lock`). Experimental até a 1.0, segundo os
mantenedores. Caminhos abaixo em `app/vendor/mcp/sdk/`.

**O SDK dá:**

- `src/Server/Transport/StreamableHttpTransport.php`: Streamable HTTP sobre PSR-7/PSR-15, com teto de corpo de 4 MiB
  (`maxBodyBytes`) e recusa de `Mcp-Session-Id` malformado (CHANGELOG 0.7.0).
- Middleware em `src/Server/Transport/Http/Middleware/`:
  - `AuthorizationMiddleware`: responde 401/403 com `WWW-Authenticate`; recebe um `AuthorizationTokenValidatorInterface`
    (`validate(string $accessToken): AuthorizationResult`), que será nosso;
  - `ProtectedResourceMetadataMiddleware`, que delega ao `OAuth/ProtectedResourceMetadataHandler` (RFC 9728; o handler pode
    ser montado direto como controller Symfony);
  - `CorsMiddleware`, `DnsRebindingProtectionMiddleware` e `ProtocolVersionMiddleware`, que formam a pilha padrão e são
    restritivos por padrão: sem `Access-Control-Allow-Origin`, proteção de rebinding pensada para localhost;
  - `ClientRegistrationMiddleware`: atende `POST /register` (RFC 7591) repassando a um `ClientRegistrarInterface`
    (`register(array): array`) que nós implementamos, e acrescenta `registration_endpoint` ao metadado;
  - `OAuthProxyMiddleware`: só **repassa** `/authorize` e `/token` a um servidor de autorização externo. Não serve para nós.
- `OAuth/JwtTokenValidator`, `JwksProvider`, `OidcDiscovery`: validam JWT de um servidor externo por JWKS. Para nós é mais
  simples um validador próprio: o token é nosso, e a conferência no banco a cada requisição é obrigatória (9.2).
- Sessão MCP em `src/Server/Session/`: `InMemorySessionStore` (padrão), `FileSessionStore`, `Psr16SessionStore`.
- `src/Schema/ToolAnnotations.php` com `readOnlyHint` e `destructiveHint` (o ChatGPT e o Gemini Enterprise usam para pedir
  confirmação, 6.6) e `src/Server/ClientGateway.php::elicit()` (pedir confirmação ao usuário pelo protocolo; suporte varia
  por cliente).
- Versões de protocolo até `2025-11-25` (`src/Schema/Enum/ProtocolVersion.php:21-24`).

**Falta, e é trabalho nosso:**

- **O servidor de autorização inteiro.** O `adr/0001-oauth-authorization-server-out-of-scope.md` (aceito em 15/06/2026)
  diz que o SDK não emite, assina, guarda nem renova token, não tem login nem consentimento, e que PR nessa direção é
  recusado.
- CIMD (é papel do servidor de autorização).
- A versão `2026-07-28` do protocolo (sem estado, `server/discover`): o SDK 0.7.1 não a conhece (3.5).
- A ponte PSR-7 e HttpFoundation: `symfony/psr-http-message-bridge` não está instalado (o bundle do `league` v2 exige; entra
  junto). `guzzlehttp/psr7` 2.13.2 e `php-http/discovery` já estão no lock, com fábricas PSR-17 ligadas em
  `app/config/packages/http_discovery.yaml`.

### 3.2 O servidor de autorização é o próprio BlueJus

**Decisão mantida: `league/oauth2-server-bundle` dentro da aplicação Symfony.**

O motivo é identidade: `User`, `UserTenant` e `Tenant` já vivem no BlueJus, com senha, convite, recuperação, vínculo por
escritório, suspensão e quarentena. Um IdP externo (Keycloak, Auth0, Entra) exigiria um segundo diretório de usuários e
federação: mais peças, mais estados inconsistentes, e um lugar novo para o vínculo usuário e escritório divergir.

Conferido em 08/11/2026: a **v2.0.0** (outubro/2026) pede `php ^8.2`, `symfony/framework-bundle ^7.4|^8.1`,
`symfony/security-bundle ^7.4|^8.1`, `symfony/psr-http-message-bridge ^7.4|^8.1` e `league/oauth2-server ^9.2`
(https://raw.githubusercontent.com/thephpleague/oauth2-server-bundle/v2.0.0/composer.json). Compatível com o Symfony 7.4
daqui. Nenhum dos dois pacotes está no lock hoje.

O que o bundle **não** traz e vira extensão nossa, cada uma com teste:

1. **CIMD**: `client_id` que é URL https vira busca do documento de metadados (3.4). Ponto de extensão: o gerenciador de
   clientes.
2. **DCR** (`/oauth/register`): pelo `ClientRegistrationMiddleware` do SDK ou por controller nosso.
3. **`resource`** (RFC 8707) virando o `aud` do token, e recusa de `resource` diferente de `https://bluejus.com.br/mcp`.
4. **`iss` na resposta de autorização** (RFC 9207), que o ChatGPT usa para manter estáveis o redirect e o `client_id`.
5. **Escritório e consentimento**: o token carrega o `tenant_id` escolhido na tela (seção 4) e nasce de um consentimento
   que a pessoa pode revogar.

Conferir no PR 2 se a persistência do bundle aceita gerenciadores próprios (tabelas da seção 13.2) em vez das tabelas
`oauth2_*` dele, que não têm escritório nem consentimento.

**`symfony/mcp-bundle` como servidor de autorização:** o PR `symfony/ai#2135` foi **fechado sem merge em 12/07/2026**. Em
19/06/2026 o autor disse que vai refazer o trabalho como servidor de recurso por padrão e servidor de autorização opcional
sobre o `league/oauth2-server` (https://github.com/symfony/ai/pull/2135). Nada liberado. Reavaliar no PR 2; se tiver saído,
comparar antes de escrever.

### 3.3 Endpoints

| Endpoint | Norma | Quem serve |
|---|---|---|
| `/.well-known/oauth-protected-resource/mcp` e `/.well-known/oauth-protected-resource` | RFC 9728 | `ProtectedResourceMetadataHandler` do SDK, montado como controller |
| `/.well-known/oauth-authorization-server` | RFC 8414 | nosso |
| `/oauth/authorize` | RFC 6749 + 7636 (PKCE S256) + 8707 + 9207 | nosso, no firewall de sessão (é onde a pessoa faz login) |
| `/oauth/token` | RFC 6749, corpo `application/x-www-form-urlencoded` | nosso |
| `/oauth/register` | RFC 7591, corpo JSON | nosso (3.4) |
| `/.well-known/jwks.json` | RFC 7517 | opcional: só se algum cliente validar o token fora daqui |
| `/mcp` | Streamable HTTP | SDK |

**Correção do nginx (a afirmação de agosto estava errada).** O server HTTPS de `bluejus.com.br`
(`nginx/conf.d/nginx.prod.conf:33-183`) termina com `location ~ /\. { deny all; }` (`:180-182`, presente desde 20/05/2026).
Location por expressão regular vence o `location /` (`:123`), que não tem `^~`. Hoje, portanto, `/.well-known/oauth-*`
recebe **403 do nginx** sem chegar ao Symfony. O `^~ /.well-known/acme-challenge/` só existe nos servers da porta 80
(`:8`, `:209`). Precisa de um `location ^~ /.well-known/oauth- { try_files $uri /index.php$is_args$args; }` antes do bloco
de `:180`, e de um curl nas duas URLs no primeiro deploy.

Segundo ajuste: o `/mcp` responde em SSE quando o SDK escolhe fluxo. A resposta leva `X-Accel-Buffering: no`, como o chat já
faz (`app/src/ChatIa/Controller/ChatIaMensagensController.php:87`).

O PR leva a mudança no `nginx.prod.conf`; aplicar na VPS é passo do Samuel no deploy.

### 3.4 Registro do cliente: suportar os três jeitos

**A especificação MCP atual** (2026-07-28,
https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization/client-registration):

- O cliente tenta, nesta ordem: credencial pré-registrada; **CIMD** se o servidor anunciar
  `client_id_metadata_document_supported: true`; **DCR** se houver `registration_endpoint`; por fim, pede os dados ao usuário.
- CIMD é SHOULD para cliente e servidor. DCR é MAY e está **deprecated** ("New implementations should use Client ID Metadata
  Documents instead"), mantido por compatibilidade.
- Servidor que aceita CIMD: SHOULD buscar o documento quando o `client_id` é uma URL; MUST conferir que o `client_id` do
  documento é igual à URL; MUST validar o JSON e os campos obrigatórios (`client_id`, `client_name`, `redirect_uris`); MUST
  validar o `redirect_uri` do pedido contra o documento; SHOULD guardar em cache pelos cabeçalhos HTTP; SHOULD se proteger de
  SSRF; e o consentimento mostra o host do redirect.

**Como cada cliente conecta hoje:**

| Cliente | Transporte | Registro aceito | Redirect | Exigências e limites | Fonte |
|---|---|---|---|---|---|
| Claude (claude.ai, Desktop, celular, Cowork) | Streamable HTTP; SSE antigo ainda aceito, em descontinuação | CIMD hospedado pela Anthropic, só se o metadado tiver `client_id_metadata_document_supported: true` **e** `"none"` em `token_endpoint_auth_methods_supported` (senão cai para DCR); DCR (um cliente novo a cada conexão); client ID e segredo opcional digitados nas configurações avançadas | `https://claude.ai/api/mcp/auth_callback` | 401 obrigatório (ignora `WWW-Authenticate` em resposta 200); `resource` igual à URL digitada; usa só o primeiro item de `authorization_servers`; PKCE S256 sempre; `/token` em form-urlencoded; `invalid_grant` na renovação vencida; 10 s para descoberta, registro e token, 30 s para renovação; tráfego sai de `160.79.104.0/21`; cerca de 150 mil caracteres e 240 s por chamada | https://claude.com/docs/connectors/building/authentication e https://claude.com/docs/connectors/building/index.md |
| Claude Code | HTTP (`claude mcp add --transport http`) | CIMD próprio (`https://claude.ai/oauth/claude-code-client-metadata`), DCR ou `--client-id` | loopback `http://localhost:<porta>/callback` e `http://127.0.0.1:<porta>/callback`, com qualquer porta | 25 mil tokens por resultado (`MAX_MCP_OUTPUT_TOKENS`); elicitation só em terminal interativo | https://code.claude.com/docs/en/mcp |
| ChatGPT (apps, modo desenvolvedor) | Streamable HTTP e SSE | pré-registrado; CIMD, preferido (`client_id` `https://chatgpt.com/oauth/client.json` se o servidor cumprir a RFC 9207, senão `https://chatgpt.com/oauth/{callback_id}/client.json`; autenticação `none` ou `private_key_jwt`); DCR, um registro por conexão | `https://chatgpt.com/connector_platform_oauth_redirect` (com RFC 9207) ou `https://chatgpt.com/connector/oauth/{callback_id}` | `code_challenge_methods_supported` com `S256`, senão "não suportado"; `issuer` idêntico no metadado e em `authorization_servers`; `iss` em toda resposta de autorização, inclusive erro; `resource` vira `aud`; ferramenta sem `readOnlyHint` é tratada como escrita e pede confirmação; `search`/`fetch` não são mais exigidas | https://developers.openai.com/apps-sdk/build/auth e https://developers.openai.com/api/docs/guides/custom-mcp-server |
| Gemini, app (gemini.google.com, "Custom apps") | não informado | DCR; sem DCR, credenciais manuais em "Advanced features" | não encontrado em fonte oficial | só na web, só em inglês, só nos **EUA**, maiores de 18, conta Google pessoal, "Keep Activity" ligado; escrita sempre com confirmação manual | https://support.google.com/gemini/answer/17209137 (sem data) |
| Gemini Enterprise | só Streamable HTTP (SSE não) | só cliente pré-registrado (client ID e segredo digitados pelo admin; PKCE opcional); DCR e CIMD não citados | `https://vertexaisearch.cloud.google.com/oauth-redirect` | configurado pelo admin no Google Cloud (`roles/discoveryengine.editor`); ações com confirmação, salvo `readOnlyHint`; até 100 ações | https://docs.cloud.google.com/gemini/enterprise/docs/connectors/custom-mcp-server/set-up-custom-mcp-server e https://support.google.com/g/answer/17106276 (15/09/2026; Pre-GA na edição Business) |
| Gemini CLI e Antigravity | `httpUrl` (Streamable HTTP) | Gemini CLI: DCR ou `oauth.clientId`; Antigravity: manual, CIMD (`https://antigravity.google/oauth/client-metadata.json`) ou DCR | loopback; Antigravity manual: `https://antigravity.google/oauth-callback` | ferramentas de desenvolvedor; Antigravity com 180 s por ferramenta; no plano gratuito o Gemini CLI foi trocado pelo Antigravity em 18/06/2026 | https://geminicli.com/docs/tools/mcp-server/ e https://antigravity.google/docs/mcp/ |

**Recomendação (substitui a de agosto, que era "pré-registrado primeiro, DCR depois"):**

1. **CIMD é o caminho principal.** Cobre Claude, Claude Code, ChatGPT e Antigravity sem tabela de clientes crescendo.
   Anunciar `client_id_metadata_document_supported: true` **e** `"none"` em `token_endpoint_auth_methods_supported` (sem o
   `none` o Claude cai para DCR). A busca do documento: só https com caminho, nunca IP privado, loopback ou link-local
   (SSRF), tempo curto, tamanho máximo, cache pelos cabeçalhos. **Lista de domínios confiáveis** ligada desde o começo
   (`claude.ai`, `chatgpt.com`, `antigravity.google`); domínio fora dela é recusado até decisão (14.d).
2. **DCR no mesmo PR, apesar de deprecated.** É o único caminho do app Gemini e do Gemini CLI e o fallback do Claude. Com
   limite de taxa por IP e expiração em 24 h do cliente que não autorizou ninguém (o Claude registra um cliente por conexão).
   **`redirect_uris` só de host da mesma lista permitida do CIMD** (uma lista só, num lugar só, para os dois caminhos:
   `claude.ai`, `chatgpt.com` e `antigravity.google` enquanto a 14.d não mudar) ou de loopback (`127.0.0.1` e `localhost`,
   para as ferramentas de linha de comando, RFC 8252). Qualquer outro host é recusado no próprio registro com
   `invalid_redirect_uri` (RFC 7591). Sem isso, o DCR anula a lista do CIMD: quem quisesse fugir dela registraria um cliente
   com redirect para o próprio domínio e mandaria o link de autorização para a vítima. A tela de consentimento continua
   mostrando o host do redirect (9.4).
3. **Pré-registrado por comando de console, sem tela**, quando um escritório pedir o Gemini Enterprise, que só aceita
   client ID e segredo digitados pelo admin dele. Cliente confidencial, segredo guardado só como hash, preso ao escritório
   que pediu.

**Metadado do servidor de autorização** (mínimo para os três): `issuer`, `authorization_endpoint`, `token_endpoint`,
`registration_endpoint`, `response_types_supported: ["code"]`, `grant_types_supported` (`authorization_code` e, conforme
14.c, `refresh_token`), `code_challenge_methods_supported: ["S256"]`,
`token_endpoint_auth_methods_supported: ["none", "client_secret_basic", "client_secret_post"]`,
`client_id_metadata_document_supported: true`, `authorization_response_iss_parameter_supported: true`, `scopes_supported`.

**Metadado do recurso:** `resource: "https://bluejus.com.br/mcp"` (igual à URL que a pessoa digita),
`authorization_servers: ["https://bluejus.com.br"]` (um só), `scopes_supported: ["mcp:leitura", "mcp:escrita"]`.

### 3.5 Versão do protocolo

A versão atual é **2026-07-28** (https://modelcontextprotocol.io/specification/versioning): protocolo sem estado (saem
`initialize`, `Mcp-Session-Id` e a retomada por `Last-Event-ID`; entra `server/discover`), versão declarada por requisição. A
documentação do Claude cita as versões 2025-03-26, 2025-06-18 e 2025-11-25; o SDK 0.7.1 negocia até 2025-11-25.

**Recomendação:** a primeira entrega fala 2025-06-18 e 2025-11-25, o que o SDK e os clientes falam hoje. O `Mcp-Session-Id`
fica no `Psr16SessionStore` sobre um pool de cache próprio (o projeto não tem Redis; o `cache.app` é em arquivo e atende os
workers do mesmo container). A 2026-07-28 entra num PR próprio quando o SDK a suportar. A versão do SDK continua fixa.

---

## 4. O problema do escritório (tenant): o coração desta spec

Um `User` pertence a **N** escritórios via `UserTenant`. Um token OAuth identifica **um usuário**. Portanto o token, sozinho,
não diz de qual escritório a pergunta está falando.

| Opção | Como | Veredito |
|---|---|---|
| Tenant como **parâmetro de cada ferramenta** | o modelo informa o escritório na chamada | ❌ **Rejeitada.** Uma alucinação do modelo troca de escritório sozinha. |
| Tenant **escolhido no consentimento**, virando claim do token | a tela de autorização pergunta "qual escritório?"; o `tenant_id` entra no token | ✅ **Escolhida.** |
| Tenant **inferido** (o único, ou o último usado) | implícito | ❌ Falha silenciosa para quem tem mais de um. |

**Decisão (mantida): uma conexão = um escritório**, fixado na autorização e carregado no token. Para operar outro escritório,
a pessoa autoriza outra conexão.

**Regras que sustentam isso:**

1. **Na autorização**, conferir o mesmo que o navegador confere ao escolher escritório: `User` ativo, `UserTenant` ativo,
   `Tenant` ativo (`TenantContext::setCurrentTenant`, `app/src/Service/Tenant/TenantContext.php:55-76`), o bloqueio por
   pedido de exclusão (`:71-73`) e as barreiras do `TenantContextValidatorListener` (4.1).

   **O primário de escritório suspenso a pedido é recusado pelo conector**, ao contrário do que o `TenantContext` faz.
   `BloqueioPorPedidoDeExclusao::bloqueia()` (`app/src/Tenant/Service/BloqueioPorPedidoDeExclusao.php:66`, chamado em
   `TenantContext.php:71`) barra só quem **não** é primário: o primário entra porque, no navegador, o
   `TenantContextValidatorListener` o prende ao Meu Perfil (`:156`, pelo `primarioNaContaSuspensaAPedido`, `:210-230`: só
   `/perfil` passa; chamada de fundo recebe 403 com a `BloqueioPorPedidoDeExclusao::MENSAGEM`; navegação vai para
   `app_profile`). O conector não tem Meu Perfil: o serviço extraído do listener (4.1) devolve esse caso como **recusa** para
   o portão, com a mesma `MENSAGEM`, na autorização e a cada requisição, e o listener continua mandando o primário ao Meu
   Perfil, sem mudar o navegador. Teste em 12.3.
2. **A cada requisição MCP**, resolver `User`, `Tenant` e `UserTenant` a partir do token, repetir essas barreiras, ligar o
   filtro Doctrine explicitamente e conferir que ficou ligado (fail-closed).
3. **Revogação:** vínculo, usuário ou escritório desativado, suspenso ou em quarentena, ou consentimento revogado, e os tokens
   param de funcionar na requisição seguinte (9.2). **"Sair de todos os aparelhos" e a troca de senha também derrubam o
   conector**, inclusive o token de renovação, porque a pessoa usa os dois justamente quando desconfia que alguém entrou
   na conta dela:
   - "Sair de todos os aparelhos" (`SairDeTodosOsAparelhosUseCase.php:39`) grava o corte em `User::sessoesEncerradasEm`
     (`User::getSessoesEncerradasEm()`, `app/src/Entity/Auth/User.php:243`). No navegador quem aplica o corte é o
     `SessaoEncerradaEmTodosOsAparelhosListener`, que só age com sessão (`hasPreviousSession`), então não vê o bearer. O
     conector grava em cada token e renovação o instante da autorização que os originou (`autorizado_em`, que a rotação da
     renovação **copia**, nunca renova) e recusa, no `/mcp` e no `/oauth/token`, todo token ou renovação com
     `autorizado_em` menor ou igual ao corte (o mesmo "no mesmo segundo também cai" do listener). O consentimento fica: a
     pessoa refaz o login e a conexão volta.
   - Troca de senha: não há data de troca no `User`, e os caminhos que gravam senha são vários (`AlterarSenhaUseCase`,
     `RedefinirSenhaUseCase`, `ConfirmarCodigoSenhaUseCase`, `CriarSenhaPeloLinkUseCase`, `LigarContaAoGoogleUseCase`,
     entre outros). No navegador as outras sessões caem porque o `User` não implementa `EquatableInterface` e o Symfony
     compara o hash da senha a cada pedido. O conector faz o mesmo: grava no token e na renovação uma **impressão da senha**
     (HMAC, com o segredo da aplicação, do hash atual) e recusa quando ela não bate com a do hash de agora. Assim nenhum
     caminho de troca de senha precisa lembrar do conector. Teste em 12.16.

### 4.1 🔴 O `TenantFilter` continua falhando ABERTO

Estado em 08/11/2026, igual ao de agosto no essencial:

- `app/src/Shared/Doctrine/Filter/TenantFilter.php:16-17` documenta *"Sem o parâmetro `tenant` setado, o filtro é inerte (não
  restringe nada)"*, e o código devolve `''` sem parâmetro (`:28-36`). O filtro nasce desligado
  (`app/config/packages/doctrine.yaml:21-23`).
- Quem o liga é o `TenantFilterListener` (`kernel.request`, prioridade 5, `app/src/Shared/EventListener/TenantFilterListener.php:19`),
  lendo `TenantContext::getCurrentTenant()`, que lê a **sessão** (chave `current_tenant_id`, `TenantContext.php:18` e
  `:88-96`; sem sessão, devolve `null`).
- Há mais dois listeners que a spec de agosto não citava: o `TenantContextValidatorListener` (prioridade 6,
  `app/src/EventListener/TenantContextValidatorListener.php:28`), que aplica a cada requisição as barreiras de conta suspensa
  ou excluída, pedido de exclusão, vínculo e situação na equipe (`:150-161`); e o `TenantUrlScopeListener` (prioridade 4,
  `app/src/Shared/EventListener/TenantUrlScopeListener.php:25`), que fixa o filtro pelo `{tenantId}` da rota.
- **Não existe trava fail-closed.** Os `isEnabled('tenant')` do código servem para **desligar** o filtro por um tempo
  (`app/src/ServiceDesk/Repository/SemFiltroDeTenant.php`, `app/src/Ponto/Repository/ConsultaSemFiltroDeEscritorio.php`,
  `app/src/Pasta/EventListener/PushCompartilhadoSemTenantFilterListener.php`), nunca para exigir que esteja ligado. Nenhum
  teste de arquitetura exige tenant explícito.

Uma requisição autenticada por bearer **não tem sessão**. Se nada for feito, os três listeners não fazem nada, o filtro fica
desligado e **toda consulta devolve dados de todos os escritórios, sem erro nenhum**. Este continua sendo o defeito mais
perigoso da feature, porque falha em silêncio e com aparência de sucesso.

**Não mexa no `TenantFilter`.** O comportamento inerte é intencional (console, importações, worker). A correção é **trancar
a porta nova**:

- Um **portão do conector** único (nome sugerido `PortaoDoConector`): resolve pessoa, escritório e vínculo do token, aplica
  as barreiras, liga o filtro com o parâmetro e **confere** (`isEnabled('tenant')` e parâmetro igual ao escritório do
  token) antes de despachar a ferramenta. Qualquer falha recusa a requisição; ela nunca segue com o filtro desligado.
- As barreiras do `TenantContextValidatorListener` saem para um serviço sem sessão que **o listener e o portão usam**, sem
  mudar o comportamento do navegador (testes do listener antes, mover sem reescrever). Duplicar a lógica no conector é o
  jeito de as duas divergirem.
- Teste de arquitetura: toda ferramenta registrada é despachada pelo portão; não existe outro caminho.

### 4.2 Tenant explícito: o padrão que já existe

Desde agosto, três frentes passaram a rodar sem sessão e firmaram o padrão que o conector segue:

- **Worker da I.A.**: o handler confere o `tenantId` da mensagem contra a linha e a pasta pelo escritório
  (`app/src/Inteligencia/MessageHandler/ProcessarAnaliseDeInteligenciaHandler.php:45-47`, `:120-126`, `:153-154`);
  `FonteDeMovimentacoesDoPushDoctrine` põe o tenant em toda consulta (`app/src/Inteligencia/Contexto/FonteDeMovimentacoesDoPushDoctrine.php:36`, `:56`).
  Regra 2 de `app/src/Inteligencia/CLAUDE.md`.
- **Chat I.A**: `ConsultarPrazosEMetasUseCase::executar(User $eu, Tenant $tenant, ...)`
  (`app/src/ChatIa/UseCase/ConsultarPrazosEMetasUseCase.php:53`) tira à mão a meta de outro escritório (`:85-87`); a prova
  sem filtro é `app/tests/ChatIa/Integration/ConsultaDePrazosSemFiltroDeTenantTest.php`.
- **Agente de Pastas**: `BuscarPastasDoAgenteUseCase::executar` (`app/src/Inteligencia/UseCase/BuscarPastasDoAgenteUseCase.php:40`),
  `ConsultaDePastasDoAgente::buscar`/`contar` (`app/src/Inteligencia/Service/ConsultaDePastasDoAgente.php:42`, `:59`) e
  `ListarDocumentosDaPastaDoAgenteUseCase::executar` (`app/src/Inteligencia/UseCase/ListarDocumentosDaPastaDoAgenteUseCase.php:41`);
  a prova é `app/tests/Inteligencia/Unit/ConsultaDePastasDoAgenteTest.php`.

**Regra para o conector:** toda ferramenta passa o `User` e o `Tenant` do portão ao UseCase, e o UseCase filtra por escritório
à mão mesmo com o filtro ligado. Ferramenta cujo caminho passe por algo que lê `TenantContext`, `Security` ou a `Request`
(ex.: `TarefaController::minhas`, `PastaController::show`, `PastaController::downloadDocumento`, o trait
`AutorizacaoCobranca`) **não entra** até existir UseCase sem sessão.

### 4.3 Auditoria sem sessão

O `AuditLogSubscriber` (`app/src/Service/Audit/AuditLogSubscriber.php`) tira o ator de `Security::getUser()` (`:409`) e o
escritório de `TenantContext` (`:418`, via `tenantDoRegistro`, `:448-458`). Com bearer, `getCurrentTenant()` devolve `null`
sem exceção e `tenantDoRegistro` devolve `null`: o registro sai **sem escritório**. O ator sai certo se o firewall do `/mcp`
autenticar a pessoa do token.

Correção: um escopo explícito do conector, no mesmo molde do `escopoDePlataforma` já usado ali (`:416`), que dá ao registro o
escritório do token. Teste: uma escrita pelo conector grava `audit_log` com o escritório e a pessoa do token.

---

## 5. Autorização: reusar o que existe, sem contornar

`app/src/Service/PermissionChecker.php` recebe `User` e `Tenant` como **parâmetros explícitos** e não depende de sessão.
Métodos públicos hoje: `canAccessModule` (`:32`), `canAdminister` (`:55`), `canAccessResource` (`:78`),
`canAccessResourceComVinculo` (`:103`), `idsPermitidosComVinculo` (`:136`), `canAccessResourceIgnoringPastaLock` (`:165`),
`hasPermission` (`:198`), `isAdministradorDoEscritorio` (`:251`) e `reset()` (a classe guarda cache de vínculos e implementa
`ResetInterface`).

**Toda ferramenta passa pelo `PermissionChecker` com a pessoa e o escritório do token.** O que o implementador precisa saber,
com `docs/AUTORIZACAO.md` ao lado:

1. **Os bypasses continuam**: `ROLE_SUPER_ADMIN` (global) e `TenantRole::isSystem()` (por escritório). Um Master conectando
   enxerga tudo do escritório escolhido, **desde que tenha vínculo ativo com ele** (decisão 1).
2. **Módulo e recurso são eixos paralelos** (`docs/AUTORIZACAO.md` seção 7, decisão de 30/06/2026): listagem e criação pedem
   o módulo; item por id pede o recurso. As ferramentas seguem isso: `buscar_*` e `listar_*` sem id exigem o módulo;
   `detalhar_*` e `ler_*` por id exigem o recurso daquele item.
3. **Correção da spec de agosto:** `canActOnResource` **não existe** (saiu em 30/06/2026; `docs/AUTORIZACAO.md` F2 ainda o
   cita, e está desatualizado nesse ponto). E `resources.{x}.{y}` **não é código morto**: é o fallback do
   `canAccessResource` e é conferido por `hasPermission` em `resources.carteira.gerenciar`
   (`app/src/Cliente/UseCase/CriarClienteAvulsoUseCase.php:26`), `resources.cobranca.*` e `resources.agenda.ver_prazos`
   (`app/src/Agenda/Service/PermissoesDaAgenda.php:19`).
4. **A autorização fina mora nos controllers e em listeners**, não no `security.yaml` (que só exige `ROLE_USER` em `^/`,
   `security.yaml:83`). Uma ferramenta que chame UseCase direto pula essas camadas; a seção 5.2 lista cada uma.

### 5.1 Escopos OAuth e permissões do sistema

São eixos diferentes e **os dois valem**:

- **Escopo** diz o que *aquela conexão* pode fazer: `mcp:leitura` e `mcp:escrita` (renomeados de `mcp:read`/`mcp:write` para
  seguir o idioma do projeto).
- **Permissão** diz o que *aquela pessoa* pode fazer naquele escritório.

A ferramenta só executa se **ambos** permitirem. Escopo nunca amplia permissão. `mcp:escrita` só entra no token se a pessoa
marcar a caixa na tela de consentimento (desmarcada por padrão). Ferramenta de escrita chamada sem o escopo responde 403 com
`error="insufficient_scope"` e `scope="mcp:leitura mcp:escrita"` (step-up da especificação atual). `offline_access` só é
anunciado se a decisão 14.c escolher renovação.

### 5.2 As guardas que o conector pula, e como refazer cada uma

| Guarda | Onde mora hoje | O que a ferramenta faz |
|---|---|---|
| Barreiras do escritório (conta suspensa ou excluída, pedido de exclusão, vínculo, situação na equipe) | `TenantContextValidatorListener` (`kernel.request`) | o portão, pelo serviço extraído (4.1); o primário de escritório suspenso a pedido é **recusado** (regra 1 da seção 4) |
| Termos de uso aceitos na versão vigente | `TermoAceiteListener` (prioridade 7, `app/src/EventListener/TermoAceiteListener.php:90-121`), que usa a sessão (`:111`) | o `/oauth/authorize` passa pelo listener como hoje (a pessoa aceita na tela); no `/mcp`, o portão confere `AceiteTermoRepository::existeAceiteVigente` (`app/src/Termo/Repository/AceiteTermoRepository.php:39`) a cada requisição e recusa com "aceite os Termos de Uso no BlueJus" (8) |
| "Sair de todos os aparelhos" | `SessaoEncerradaEmTodosOsAparelhosListener` (prioridade 7), que só age com sessão | `autorizado_em` do token e da renovação contra `User::getSessoesEncerradasEm()` (regra 3 da seção 4) |
| Troca de senha | o Symfony, ao reler o `User` da sessão (sem `EquatableInterface`, compara o hash da senha) | impressão da senha no token e na renovação (regra 3 da seção 4) |
| Cadeado da pasta | `BarreiraDoCadeadoListener` (`kernel.controller_arguments`, `app/src/Pasta/EventListener/BarreiraDoCadeadoListener.php:39`) | `AcessoAPasta::podeVer`/`podeEditar` (`app/src/Pasta/Service/AcessoAPasta.php:42`, `:47`) no item; `idsOcultosPara` (`:118`) e `idsDosProcessosOcultos` em lista. `podeVer` usa o escritório **da pasta**: conferir antes que a pasta é do escritório do token |
| Aba oculta | `BarreiraDeAbaOcultaListener` (`app/src/Pasta/EventListener/BarreiraDeAbaOcultaListener.php:37`, que lê o escritório da sessão), pelo nome da rota (`MAPA`, `:50-79`) | `VisibilidadeDasAbasDaPasta::abasOcultasPara` (`app/src/Pasta/Service/VisibilidadeDasAbasDaPasta.php:115`), com a aba que a tabela de 6.2 dá para cada ferramenta |
| Pasta excluída é só leitura | `PastaSomenteLeituraListener` | escrita confere `Pasta::estaExcluida()` (`app/src/Pasta/Entity/Pasta.php:450`) |
| Tela ou função desligada pelo Master | `BloqueioDeRecursoDesativado`, por prefixo do nome da rota (`CatalogoDeRecursos::ROTAS`, `app/src/Master/Service/CatalogoDeRecursos.php:59-74`: `peticionar`, `metas`, `expedientes`, `assistente`...); o Master passa (`BloqueioDeRecursoDesativado.php:50`) | `RecursosDoSistema::estaAtivo(chave)` (`app/src/Master/Service/RecursosDoSistema.php:58`) na ferramenta, com a chave que a tabela de 6.2 dá; chave nova `conector_ia` no catálogo para o Master desligar o conector inteiro |
| Meta aberta por id | `AcessoAMeta::exigir` (`docs/AUTORIZACAO.md`, "Meta aberta por id") | a mesma guarda antes de ler ou escrever meta por id |
| Só acompanhar meta não basta | `PastasSoAcompanhadasSemVer::ids` (`app/src/Tarefa/Service/PastasSoAcompanhadasSemVer.php:40`) | já vem dentro de `ListaDasMinhasMetasDaTela` |

Cada linha ganha um teste funcional do conector que prova a recusa (seção 12).

---

## 6. Superfície de ferramentas

### 6.1 O que NÃO entra

- **`consultar_sql`**: não há como oferecer SQL livre e garantir isolamento entre escritórios; filtrar SQL arbitrário por
  texto é exatamente o que a v1 rejeitou por ser burlável. Nenhuma ferramenta aceita SQL, DQL ou nome de tabela.
- **Arquivo bruto por link**: o armazenamento não tem URL assinada, e `EntregaDeArquivo` responde dentro de uma requisição de
  sessão. O conteúdo sai só por `ler_documento` (6.4).
- **Ponto eletrônico** (risco ALTO por definição), **cobrança** (os UseCases de leitura existem, mas a guarda, o trait
  `AutorizacaoCobranca`, lê a sessão), **exclusões**, **cadastro direto de cliente** (6.5).

### 6.2 Regras comuns a toda ferramenta

1. **Despacho único pelo portão** (4.1). Pessoa, escritório e vínculo vêm do token; o modelo nunca escolhe pessoa nem
   escritório. Todo id (pasta, processo, documento, modelo, meta) é buscado por id **e** escritório; alheio, inexistente ou
   oculto dá a mesma resposta "não encontrado".
2. **Permissão**: módulo para lista, recurso para item (5); as guardas de 5.2; o escopo (5.1); a chave do Master.
3. **Segredo de justiça nunca sai** (regra 4 de `app/src/Inteligencia/CLAUDE.md`; `Processo::emSegredoDeJustica()`,
   `app/src/Processo/Entity/Processo.php:686`: `nivelSigilo > 0` ou "Sim" manual). Em lista, pasta, processo, meta ou
   compromisso ligados a processo em segredo saem e só a **contagem** aparece (`ProcessoRepository::idsDasPastasEmSegredoDeJustica`,
   `app/src/Processo/Repository/ProcessoRepository.php:148`). Por id, a ferramenta recusa com "em segredo de justiça: não sai
   pelo conector" (`MontadorDeContextoDaPasta::para` já lança `ContextoBloqueadoException`). A pessoa continua vendo na tela.
   Regra derivada da regra 4, porque o destino é um modelo de I.A.; mudá-la é decisão do Samuel.
4. **Do cliente sai só o nome** (regra 7): CPF/CNPJ, RG, e-mail, telefone e endereço nunca entram em lista nem em detalhe.
   Duas exceções, ambas já previstas pela regra 7 ou fora do modelo: o conteúdo de documento lido por `ler_documento` (com o
   aceite que cubra documentos, 14.a) e a peça **preenchida no servidor** por `gerar_minuta_de_modelo`, em que o dado vai para
   o documento da pasta e a resposta da ferramenta leva só id e título.
5. **Financeiro** só se `VisibilidadeDoFinanceiroDaPasta::podeVer(User, Tenant, Pasta)`
   (`app/src/Inteligencia/Service/VisibilidadeDoFinanceiroDaPasta.php:37`) disser sim.
6. **Texto livre do banco** passa pelo `ProtecaoDoTextoDoChat` (`app/src/ChatIa/Service/ProtecaoDoTextoDoChat.php`), que
   neutraliza (`NeutralizadorDeConteudo::neutralizar`) e mascara CPF, CNPJ, telefone e e-mail conforme a preferência do
   escritório (`MascaradorDeDadosPessoais`), e sai dentro de blocos com os nomes de `NeutralizadorDeConteudo::DELIMITADORES`
   (`app/src/Inteligencia/Service/NeutralizadorDeConteudo.php:28`). O método depende do tamanho:
   - texto de **várias linhas** (anotação, observação, descrição de meta, texto de movimentação, texto de documento):
     `comoDado` (`:38`), que neutraliza linha a linha e mantém as quebras;
   - valor **de uma linha** (nome de arquivo, nome da pessoa, título de meta): `rotulo` (`:49`), com valor padrão quando
     fica vazio.
7. **Resposta**: JSON estruturado (`structuredContent`) mais um texto curto. Cabe no menor cliente: até 100 mil caracteres por
   resultado (o Claude aceita cerca de 150 mil; o Claude Code, 25 mil tokens, o que o guia do usuário avisa), com paginação
   (`pagina`, `proxima_pagina`). Tempo-alvo abaixo de 10 s (o Claude espera 240 s; o Antigravity, 180 s).
8. **Anotações MCP**: leitura com `readOnlyHint: true`; escrita com `readOnlyHint: false`, `destructiveHint: false` (só cria)
   e `idempotentHint: false`.
9. **Log** no canal `mcp`, que já existe (`app/config/packages/monolog.yaml`, arquivo `mcp.log`): pessoa, escritório, cliente
   OAuth, ferramenta, ids, contagens, duração e resultado. **Nunca** texto de busca, título, nome ou conteúdo (regra 3 da
   Inteligencia).
10. **Entrada fechada**: JSON Schema com `additionalProperties: false`; valor fora da lista vira erro de ferramenta com
    mensagem fixa em português, nunca "todas as pastas" em silêncio.
11. **Aba oculta e chave do Master, ferramenta por ferramenta** (tabela abaixo). A tela barra pela rota; o conector não tem
    rota de tela, então cada ferramenta confere a aba e a chave da tela de onde a informação vem. Aba oculta faz a
    ferramenta responder "não encontrado" quando o pedido é por id na aba inteira (a mesma resposta de 6.2.1); a chave
    desligada responde "função desligada pelo administrador do sistema". Toda ferramenta exige também a chave nova
    `conector_ia`.

A coluna "rota da tela" diz o que a barreira de hoje cobre. "Nenhuma" quer dizer que a tela também não barra.
`BarreiraDeAbaOcultaListener::MAPA` está em `app/src/Pasta/EventListener/BarreiraDeAbaOcultaListener.php:50-79`;
`CatalogoDeRecursos::ROTAS`, em `app/src/Master/Service/CatalogoDeRecursos.php:59-74`. A Lista (`lista`) e o Meu Perfil são
essenciais (`base: true`, `:35` e `:39`) e não desligam.

| Ferramenta | Rota da tela | Aba (`AbaDaPasta`) | Chave do Master | Observação |
|---|---|---|---|---|
| `quem_sou_eu` | nenhuma | nenhuma | só `conector_ia` | |
| `buscar_pastas` | `inteligencia_agente_pastas_buscar` (o Agente) e a Lista | nenhuma | nenhuma além de `conector_ia` | a busca é a da Lista, que é essencial; `assistente` desliga o Agente, não a Lista, então não vale aqui |
| `minhas_demandas` | `demandas_index` | nenhuma | nenhuma | |
| `detalhar_pasta` | `pasta_show` | cada bloco segue a sua aba (o `PastaDoContextoDaIa`, `app/src/Central/Service/PastaDoContextoDaIa.php:60-63`, passa as abas ocultas ao montador) | `expedientes` | |
| `listar_prazos_e_metas` | `tarefa_minhas` | nenhuma (Minhas Metas não passa pela barreira de aba) | `metas` | |
| `listar_agenda` | `agenda_index` | nenhuma | nenhuma | |
| `buscar_processos`, `detalhar_processo` | `processo_index`, `processo_show` | nenhuma (a tela de processos não é rota de pasta) | nenhuma | o cadeado entra por `idsDosProcessosOcultos` (5.2) |
| `listar_documentos_da_pasta` | aba Documentos de `pasta_show`; no Agente, `inteligencia_agente_pastas_documentos` | **Documentos** | `expedientes` | o UseCase reaproveitado **não** barra a aba inteira (6.4) |
| `ler_documento` | `pasta_documento_view`, `pasta_documento_download` (`pasta_documento*`) | **Documentos** | `expedientes` | o `LeitorDeDocumentosDaPasta` já barra (`:119-121`) |
| `listar_modelos_de_peca`, `ler_modelo_de_peca` | `pasta_modelos_listar`, `pasta_modelos_abrir` (`pasta_modelos_*`) | **Detalhes** | `expedientes` | o banco de modelos mora na aba Detalhes da pasta (`app/templates/pasta/show.html.twig:323`); nenhuma chave cobre `pasta_modelos_*` hoje, e `peticionar` não vale aqui |
| `gerar_minuta_de_modelo` | `pasta_modelos_usar` | **Detalhes**, como a tela, e **Documentos**, a mais que a tela | `expedientes` | Documentos a mais porque a resposta devolve o documento criado, que fica na aba Documentos |
| `salvar_minuta_na_pasta` | `pasta_peticionar_texto` | **Documentos** | `peticionar` | |
| `anotar_na_pasta` | anotação: `pasta_enviar_mensagem`; observação: `pasta_detalhes_observacao_enviar` | anotação: nenhuma; observação: **Detalhes** | `expedientes` | |
| `criar_meta` | `tarefa_criar_para_pasta` | nenhuma | nenhuma | |
| `criar_compromisso` | `agenda_*` | nenhuma | nenhuma | |

Onde a tabela pede `expedientes` sem que a rota da tela o peça (`pasta_modelos_*`, `pasta_documento*`,
`pasta_enviar_mensagem`), é porque a informação só aparece dentro da tela da pasta, que a chave desliga. A conferência
fica junto da ferramenta, num lugar só, e o teste 12.9 percorre a tabela linha por linha.

### 6.3 Leitura, fatia L1 (UseCases já prontos para rodar sem sessão)

**`quem_sou_eu`**
- Entrada: nenhuma.
- Saída: nome da pessoa, escritório, escopos e validade da conexão, e os módulos que ela tem (para o assistente não oferecer o
  que vai ser recusado).
- Reusa: o portão e `PermissionChecker::canAccessModule` (`:32`) para cada módulo do catálogo
  (`app/src/DataFixtures/PermissionFixture.php:17-32`).
- Permissão: só o token. Grava: não. É a ferramenta que prova a cadeia no PR 5.

**`buscar_pastas`**
- Entrada: `texto?` (a busca livre da Lista), os filtros do Agente de Pastas (`FiltroDoAgenteInput`: situação, marcador,
  responsável, etc.), `pagina?`.
- Saída: `total`, `omitidas_por_segredo` e pastas (id, número, NUPs, clientes só pelo nome, ação, situação, responsáveis,
  data da última movimentação).
- Reusa: `BuscarPastasDoAgenteUseCase::executar(FiltroDoAgenteInput, Tenant, ?User $quem)`
  (`app/src/Inteligencia/UseCase/BuscarPastasDoAgenteUseCase.php:40`), sobre `ConsultaDePastasDoAgente` (`:42`), a mesma
  consulta da Lista. **Passar sempre `$quem`**: sem ele o cadeado não entra. Filtro inválido é erro
  (`FiltroDoAgenteInvalidoException`), nunca lista inteira.
- Permissão: módulo `expediente`, como o `/api/agente` (`AgenteDePastasController.php:99`).
- Regras: segredo omitido e contado (a busca do Agente hoje não tira: acrescentar no conector); cliente só pelo nome.
- Grava: não.

**`minhas_demandas`**
- Entrada: `pagina?`, `ordenar?`.
- Saída: as pastas na mão da pessoa, com os campos de `buscar_pastas`.
- Reusa: `ListarMinhasDemandasUseCase::executar(User, Tenant, filtros, pagina, porPagina, ordenar, direcao)`
  (`app/src/Pasta/UseCase/ListarMinhasDemandasUseCase.php:35`); o responsável é sempre a própria pessoa (`:44`).
- Permissão: módulo `pastas` (`app/src/Pasta/Controller/DemandasController.php:240`).
- Regras: segredo omitido e contado; conferir no PR se o UseCase aplica o cadeado (senão, `AcessoAPasta::idsOcultosPara`).
- Grava: não.

**`detalhar_pasta`**
- Entrada: `pasta_id`.
- Saída: os blocos do contexto da pasta (pasta, clientes só com nome e natureza, processos vinculados, movimentações
  recentes, metas, anotações, observações, checklist, documentos só com metadados, financeiro quando pode), dentro do
  orçamento de 60 mil caracteres (`MontadorDeContextoDaPasta::ORCAMENTO_TOTAL`, `app/src/Inteligencia/Contexto/MontadorDeContextoDaPasta.php:74`).
- Reusa: `PastaDoContextoDaIa::liberar(User, Tenant, int)` (`app/src/Central/Service/PastaDoContextoDaIa.php:52`: id e
  escritório, pasta excluída, `podeVer`, decide financeiro e abas, confere sigilo) e `MontadorDeContextoDaPasta::para(...)`
  (`:108`), como o `ContextoDaPastaNoChat::ler` (`app/src/ChatIa/Service/ContextoDaPastaNoChat.php:45`) já junta. Diferença:
  o `ContextoDaPastaNoChat` exige o aceite de envio da I.A. do escritório (`:47`); no conector vale o aceite da decisão 14.a.
  A primeira versão devolve os blocos como texto (já neutralizados e com orçamento); JSON por seção fica para quando um
  cliente precisar.
- Permissão: recurso `pasta` view (`AcessoAPasta::podeVer`) e abas ocultas.
- Regras: segredo recusa; financeiro conforme 6.2.5.
- Grava: não.

**`listar_prazos_e_metas`**
- Entrada: a mesma do chat: `periodo` (`hoje`, `proximos_7_dias`, `atrasadas`) e `papel?` (`responsavel`, `criei`,
  `acompanhando`, `todas`), com o schema de `app/src/ChatIa/Service/FerramentasDaConsulta.php:18-44`.
- Saída: `PrazosEMetasOutput` (`app/src/ChatIa/DTO/PrazosEMetasOutput.php:18-24`): total, até 20 metas (número, título,
  pasta, cliente só pelo nome, prazo, dias, prioridade, quem) e `omitidasPorSegredo`.
- Reusa: `ConsultarPrazosEMetasUseCase::executar(User, Tenant, ConsultaDePrazosInput)`
  (`app/src/ChatIa/UseCase/ConsultarPrazosEMetasUseCase.php:53`), pela porta `ListaDasMinhasMetas` e a implementação
  `ListaDasMinhasMetasDaTela` (cadeado e "só acompanhar não basta" já aplicados).
- Permissão: módulo `tarefas` (`AcessoAoModuloTarefas::pode`, `app/src/Tarefa/Service/AcessoAoModuloTarefas.php:24`). A
  `GuardaDaConsultaDePrazos` (`app/src/ChatIa/Service/GuardaDaConsultaDePrazos.php:32`) também exige o aceite de envio da
  I.A.; no conector vale a decisão 14.a.
- Regras: outro escritório e segredo já saem dentro do UseCase.
- Grava: não. Extensão natural, que também serve ao chat: `sem_prazo`, `aguardando_revisao` e o prazo jurídico da pasta
  (`PastaPrazo`).

**`listar_agenda`**
- Entrada: `de`, `ate` (até 31 dias), `so_minha?` (padrão `true`).
- Saída: compromissos (data, hora, título, local, tipo, número da pasta vinculada) e prazos de pasta quando a pessoa pode
  vê-los.
- Reusa: `ListarCompromissosUseCase::executar(Tenant, User, de, ate, soMinha, verPastas, verPrazos, administra)`
  (`app/src/Agenda/UseCase/ListarCompromissosUseCase.php:47`), com `verPrazos` = `resources.agenda.ver_prazos` e
  `verPastas` = módulo `pastas` (`PermissoesDaAgenda.php:19-30`).
- Permissão: módulo `agenda` (`app/src/Agenda/Controller/AgendaNovaController.php:58`).
- Regras: os aniversários que a agenda mostra são **da equipe**, não de clientes (`ListarCompromissosUseCase.php:25` e
  `:174`, pelo `AniversariosDaEquipe`). No conector **ficam fora**: a ferramenta descarta esses itens, porque data de
  nascimento de colega é dado pessoal e não ajuda no trabalho que o assistente faz; se o piloto pedir, voltam por decisão.
  Compromisso ligado a pasta oculta ou a processo em segredo sai e é contado; conferir no PR se o UseCase aplica o cadeado.
- Grava: não. Coordenar com a etapa 2 do chat (agenda), que precisa da mesma ferramenta.

### 6.4 Leitura, fatia L2 (falta um pedaço em cada)

**`buscar_processos`**
- Entrada: `numero?` (CNJ) ou `termo?`.
- Saída: processos (id, NUP, classe, assunto, tribunal, órgão, situação, números das pastas vinculadas visíveis) e
  `omitidos_por_segredo`.
- Reusa: `ProcessoRepository::findByNumeroProcessoDoTenant(string, Tenant)` (`:212`), `buscarPorTermoDoTenant(string, Tenant, ...)`
  (`:255`), `PastaRepository::listarVinculadasAoProcesso(Processo, Tenant, ?User)` (`app/src/Pasta/Repository/PastaRepository.php:495`).
- Falta: um UseCase de leitura sem sessão (a regra está no `ProcessoController`).
- Permissão: módulo `processos` (`app/src/Processo/Controller/ProcessoController.php:85`); processos de pasta oculta saem
  (`AcessoAPasta::idsDosProcessosOcultos`).
- Regras: segredo omitido e contado; partes só pelo nome.
- Grava: não.

**`detalhar_processo`**
- Entrada: `processo_id`, `movimentacoes?` (padrão 20, máximo 50).
- Saída: dados do processo e as últimas movimentações (data, tipo, texto curto, fonte).
- Reusa: `ProcessoRepository::findOneByIdDoTenant` (`:53`) e `Processo::getMovimentacoes()` (`Processo.php:326`).
- Falta: um método paginado de "movimentações recentes" no `MovimentacaoProcessoRepository` (hoje só há `ultimasPorProcesso`,
  que devolve datas, `app/src/Processo/Repository/MovimentacaoProcessoRepository.php:46`).
- Permissão: `idsDosProcessosOcultos` e recurso `processo` view, na ordem de `ProcessoController.php:222-225`.
- Regras: segredo recusa.
- Grava: não.

**`listar_documentos_da_pasta`**
- Entrada: `pasta_id`.
- Saída: documentos (id, nome, categoria, seção, data, tamanho, tipo), sem conteúdo.
- Reusa: `ListarDocumentosDaPastaDoAgenteUseCase::executar(int, User, Tenant)` (`:41`: id e escritório, `view`; a lixeira
  sai pelo `LixeiraFilter`, ligado por padrão).
- Falta: a **barreira da aba Documentos inteira**. O UseCase só tira o contrato de honorários quando o Financeiro está oculto
  (`documentosVisiveis`, `:53-58`); o próprio comentário (`:53-54`) diz que a aba Documentos oculta "já é barrada na rota",
  e quem barra é o `BarreiraDeAbaOcultaListener` pela rota `inteligencia_agente_pastas_documentos` (`MAPA`, `:68`), que o
  conector não atravessa. A ferramenta confere `abasOcultasPara` antes de chamar o UseCase e, com Documentos oculta,
  responde "não encontrado". O cadeado já vem do `canAccessResource` do UseCase (`PermissionChecker.php:116-118`,
  `cabeNoCadeadoDaPasta`); a ferramenta chama `AcessoAPasta::podeVer` (5.2), que é a mesma checagem, por uniformidade.
- Permissão: recurso `pasta` view, aba Documentos e chave `expedientes` (tabela de 6.2).
- Regras: segredo recusa (o UseCase do Agente não confere: acrescentar).
- Grava: não.

**`ler_documento`**
- Entrada: `documento_id`.
- Saída: conforme 14.f. Na recomendação, PDF e imagem como binário marcado "experimental" (PR 7), e peça do editor (HTML) e
  DOCX como **texto** (até 60 mil caracteres, com aviso de corte, PR 7b).
- Reusa, para PDF e imagem: `LeitorDeDocumentosDaPasta::anexos(Tenant, Pasta, ids, abasOcultas, ?User)`
  (`app/src/Inteligencia/Contexto/LeitorDeDocumentosDaPasta.php:73`: recurso de quem pede, abas gravadas e de agora com a
  aba Documentos barrada (`:119-121`), aceite, escritório e pasta, lixeira, tipo pelo conteúdo, teto e páginas) e os limites
  de `RegraDaLeituraDeDocumentos` (`app/src/Inteligencia/Service/RegraDaLeituraDeDocumentos.php:32-40`: PDF 10 MB, imagem
  3 MB, 100 páginas). Duas travas desse leitor mudam o plano de agosto:
  - **só PDF e imagem**: o tipo sai do conteúdo (`RegraDaLeituraDeDocumentos::tipoPeloConteudo`) e o resto é recusado com
    "o conteúdo não é PDF nem imagem" (`:145`). HTML e DOCX não têm caminho pronto;
  - **exige o aceite atual da I.A.** (`RegraDaLeituraDeDocumentos::aceiteCobre`, `:122`, que lê
    `ConfiguracaoDeInteligencia::aceiteCobreDocumentos`). Esse aceite fala do provedor que o BlueJus contrata, não do que a
    pessoa escolhe (14.a).
- HTML e DOCX: **fora da primeira fatia (PR 7)**. Entram no PR 7b, com um método novo no mesmo leitor (nome sugerido
  `textos`) que reusa a conferência do pedido (`conferirPedido`, `:109`) e as conferências de escritório, pasta, lixeira e
  teto do `anexo` (`:130`), reconhece HTML da peça e DOCX pelo conteúdo, converte a peça em texto sem as imagens embutidas
  e o DOCX pelo `ExtratorDeTextoDocx::extrair` (`app/src/ChatIa/Service/ExtratorDeTextoDocx.php:35`, hoje só dos anexos do
  chat; vai para um lugar comum aos dois domínios), e passa o resultado por `ProtecaoDoTextoDoChat::comoDado` (6.2.6).
  Não é uma guarda nova: é o mesmo portão do leitor com outro tipo de saída, para que a I.A. e o conector não tenham duas
  regras de quem lê o quê.
- Armazenamento (skill `armazenamento-de-arquivos`): leitura sempre pela porta `ArmazenamentoDeArquivos::ler`
  (`app/src/Shared/Armazenamento/ArmazenamentoDeArquivos.php:76`), com a chave da fábrica `ChavesDePasta::documento`
  (`app/src/Pasta/Armazenamento/ChavesDePasta.php:62`), nunca caminho. O serviço ativo é o `ArmazenamentoEspelhado`: lê do
  disco e recupera do R2 o que saiu do disco. A autorização vem antes de montar a chave.
- Falta (PR 7): a conferência do aceite vira uma porta do leitor, com a implementação de hoje para a I.A. (sem mudar nada
  nela) e a do conector conforme 14.a; se a 14.a escolher a opção 3, a porta não é necessária. Falta (PR 7b): o método
  `textos` descrito acima, o reconhecimento de HTML e DOCX pelo conteúdo e a conversão da peça em texto.
- Permissão: recurso `pasta` view, aba Documentos e chave `expedientes` (tabela de 6.2).
- Regras: segredo recusa; aceite que cubra documentos (14.a); o log guarda só id e sha256, como o
  `contexto_resumo.documentosLidos` da I.A.; imagem embutida na peça não sai (é servida hoje sem isolamento por escritório,
  `docs/specs/peca-imagem-isolamento-tenant.md`).
- Grava: não.

**`listar_modelos_de_peca`**
- Entrada: `pasta_id` (âncora da permissão), `materia?`, `tipo?`, `texto?`.
- Saída: modelos (id, título, matéria, tipo, usos).
- Reusa: `ListarModelosDePecaUseCase::executar(Tenant, User, FiltroDosModelosInput)`
  (`app/src/Pasta/UseCase/ListarModelosDePecaUseCase.php:41`).
- Permissão: recurso `pasta` view na pasta-âncora, a mesma guarda da tela de modelos (`app/src/Pasta/Controller/PastaModelosController.php:265-277`);
  **aba Detalhes** da pasta-âncora visível, porque a tela barra `pasta_modelos_*` pela aba Detalhes
  (`BarreiraDeAbaOcultaListener::MAPA`, `:71`) e o banco de modelos mora nela (`app/templates/pasta/show.html.twig:323`);
  chave `expedientes` (tabela de 6.2). Correção da versão anterior desta spec: a chave `peticionar` **não** vale aqui, porque
  ela cobre só as rotas `pasta_peticionar*`. Não existe módulo "peticionar".
- Grava: não.

**`ler_modelo_de_peca`**
- Entrada: `modelo_id`, `pasta_id`.
- Saída: o corpo do modelo em texto, com as variáveis (`cliente`, `cpf`, `processo`, `pasta`, `cidade`, `data`, `advogado`,
  `oab`; `app/src/Pasta/Service/PreenchedorDeModelo.php:19`) **sem preencher**.
- Reusa: `ModeloDePeca` (`app/src/Pasta/Entity/ModeloDePeca.php`, tabela `modelo_de_peca`, com escritório), lido por id e
  escritório.
- Permissão e regras: as de `listar_modelos_de_peca`.
- Grava: não. Serve para o assistente redigir uma minuta no estilo do escritório, que volta por `salvar_minuta_na_pasta`.

### 6.5 Escrita

**Recomendadas para entrar primeiro (W1, decisão 14.b):**

**`gerar_minuta_de_modelo`**
- Entrada: `pasta_id`, `modelo_id`.
- Saída: id e título do documento criado, link para abrir no BlueJus, e as variáveis que ficaram vazias (`___`).
- Reusa: `PrepararDadosDaPecaUseCase::executar(ModeloDePeca, Pasta, User, Tenant, agora)`
  (`app/src/Pasta/UseCase/PrepararDadosDaPecaUseCase.php:27`) e
  `UsarModeloNaPastaUseCase::executar(ModeloDePeca, Pasta, Tenant, User, array $valores)`
  (`app/src/Pasta/UseCase/UsarModeloNaPastaUseCase.php:42`), que grava pelo `SalvarPecaTextoUseCase` com categoria de peça.
- Permissão: recurso `pasta` edit (como "usar modelo" na tela, `PastaModelosController.php:166`); aba **Detalhes** visível,
  como a tela (`pasta_modelos_usar`, `MAPA`, `:71`), e aba **Documentos** visível, a mais que a tela, porque a resposta
  devolve o documento criado; pasta não excluída; chave `expedientes` (tabela de 6.2); escopo `mcp:escrita`.
- Regras: o preenchimento é **no servidor**; CPF e qualificação vão para o documento, nunca para a resposta.
- Grava: sim, um documento novo na pasta (desfaz pela lixeira).

**`salvar_minuta_na_pasta`**
- Entrada: `pasta_id`, `titulo` (até 200 caracteres), `conteudo` (Markdown simples ou HTML, até 200 mil caracteres),
  `secao_id?`.
- Saída: id, título e link do documento.
- Reusa: `SalvarPecaTextoUseCase::executar(Pasta, ?PastaSecao, string $conteudoHtml, string $titulo, string $categoria, Tenant, ?User)`
  (`app/src/Pasta/UseCase/SalvarPecaTextoUseCase.php:60`), que já saneia o HTML (`SanitizadorDePecaDeTexto`, `:91`), grava pela
  chave de `ChavesDePasta::novoDocumento` e calcula o sha256.
- Falta: converter Markdown em HTML antes do saneador.
- Permissão: recurso `pasta` edit; aba **Documentos** visível, como a tela (`pasta_peticionar_texto`, `MAPA`, `:67`); pasta
  não excluída; chave `peticionar`, a mesma da tela; escopo `mcp:escrita`.
- Regras: categoria fixa entre as de `PastaDocumento` (`app/src/Pasta/Entity/PastaDocumento.php:27-33`); autor é a pessoa
  (nunca "IA"), com a marca "pelo conector de I.A." no registro da pasta.
- Grava: sim, um documento novo na pasta (desfaz pela lixeira).

**Candidatas da segunda leva (W2, conforme o uso do piloto):**

**`anotar_na_pasta`**
- Reusa: `EnviarMensagemPastaUseCase::executar(Pasta, User, string, Tenant, ?PastaMensagem)`
  (`app/src/Pasta/UseCase/EnviarMensagemPastaUseCase.php:96`) para anotação, ou
  `EnviarObservacaoDetalhesUseCase::executar(Pasta, User, string, Tenant, ?PastaObservacaoDetalhes)` (`:42`) para observação.
- Permissão: recurso `pasta` edit; aba conforme a tabela de 6.2 (anotação: nenhuma; observação: Detalhes); chave
  `expedientes`; pasta não excluída. Regras: visível à equipe e pode notificar; o texto passa pelo
  saneamento do UseCase. Grava: sim.

**`criar_meta`**
- Falta: um `CriarMetaUseCase`. A montagem está no `TarefaController::novaMetaDaPasta`, privado
  (`app/src/Controller/TarefaController.php:282`); o `GravarMetaNovaUseCase` (`app/src/Tarefa/UseCase/GravarMetaNovaUseCase.php:39`)
  só numera e grava a entidade pronta.
- Permissão: módulo `tarefas` e ver a pasta (`TarefaController.php:211`, `:226`). Regras: responsável só da equipe do
  escritório; delegar notifica. Grava: sim.

**`criar_compromisso`**
- Reusa: `SalvarCompromissoUseCase::executar(CompromissoInput, ?int, User, Tenant, bool)`
  (`app/src/Agenda/UseCase/SalvarCompromissoUseCase.php:45`).
- Permissão: módulo `agenda`. Efeito: sincroniza com o Google de quem tem a agenda ligada. Grava: sim.

**Fora por agora:**

- `registrar_prazo`: o `SalvarPrazoDaPastaUseCase` existe (`app/src/Pasta/UseCase/SalvarPrazoDaPastaUseCase.php:37`), mas uma
  data de prazo jurídico errada causa dano. Só com decisão.
- `registrar_andamento`: não existe UseCase (`MovimentacaoProcesso` só nasce no `ProcessoController`, no mapper do Datajud e
  no cadastro); `anotar_na_pasta` cobre o uso.
- Cadastro de cliente e pasta: o chat já faz com proposta e clique (`docs/specs/chat-ia-cadastro.md`); no conector, só se a
  decisão 14.b pedir, e pelo mesmo caminho da proposta (a I.A. nunca grava cadastro direto).
- Excluir, arquivar, concluir meta, cobrança, ponto.

### 6.6 Confirmação da escrita e injeção pelo conteúdo

Uma escrita disparada por interpretação errada do modelo, ou por texto plantado num documento, tem de passar por um humano.
São três camadas, e nenhuma sozinha basta:

1. **Escopo `mcp:escrita` desmarcado por padrão** na tela de consentimento.
2. **Confirmação no cliente**: ChatGPT e Gemini pedem confirmação para toda ferramenta sem `readOnlyHint` (fontes em 3.4); o
   Claude deixa a pessoa escolher, ferramenta por ferramenta, se pergunta sempre. Não dá para depender só disso.
3. **Só escrita que cria e se desfaz**: documento novo, que vai para a lixeira; a resposta diz o que foi criado e dá o link.

A *elicitation* do protocolo (`ClientGateway::elicit` no SDK) só está documentada no Claude Code, em terminal; não serve de
barreira única. Escrita que não se desfaz (cadastro, prazo) só entra pelo caminho "preparar no conector, confirmar na tela do
BlueJus", como a proposta do chat.

**Injeção indireta:** documento, anotação ou movimentação pode trazer texto que tenta mandar o assistente gravar algo. Não
controlamos o prompt do cliente; as defesas são as três camadas acima e a marcação de dado (6.2.6) em toda resposta de
leitura.

### 6.7 Reuso com o Chat I.A

Hoje uma ferramenta do chat são quatro peças separadas: a definição (`FerramentaDeLinguagem(nome, descricao, esquemaDaEntrada)`,
`app/src/Inteligencia/DTO/FerramentaDeLinguagem.php`, neutra de fornecedor; só `paraAnthropic()` é acoplado), a guarda
(`GuardaDasPropostas`, `GuardaDaConsultaDePrazos`), o executor e o despacho por nome dentro do
`TransmitirRespostaDoChatUseCase`.

**Recomendação:** um registro de ferramentas com `nome`, `schema`, `anotacoes`, `guarda(User, Tenant)` e
`executar(User, Tenant, array)`, usado pelo chat e pelo conector, para a mesma ferramenta não ter duas regras. Entra no PR 6
movendo o que existe, sem mudar o comportamento do chat.

---

## 7. Legado que a feature encosta

- **`Processo` está no domínio** (`app/src/Processo/Entity/Processo.php`, com escritório), mas a regra da tela continua no
  `ProcessoController` (814 linhas). Faltam UseCases de leitura (6.4).
- **`Cliente`** tem 8 UseCases em `app/src/Cliente/UseCase/`; o `ClienteController` (572 linhas) não tem listagem, e o
  `ClienteRepository::findByFilters` não recebe escritório e não tem chamador. Busca de cliente fica fora da primeira entrega:
  as pastas já trazem o nome. `Cliente` usa herança JOINED (`ClientePF`/`ClientePJ`); consulta nova considera as duas.
- **`Tarefa` é legado** (`app/src/Entity/Tarefa/Tarefa.php`); criar meta exige extrair UseCase do controller (6.5).
- **`PastaController`** (2944 linhas) tem o detalhe da pasta e o download, presos à sessão. O conector não passa por ele.
- **Cobrança**: `MontarDetalheObjetoUseCase` e `ListarCasosUseCase` existem, mas a guarda lê a sessão. Fora até ter guarda
  sem sessão.

Não refatorar o legado por causa desta feature, e não replicar regra de negócio dentro da ferramenta: se precisar de regra,
extrair UseCase e dizer isso no PR.

---

## 8. Transporte e infraestrutura

- **Transporte**: `StreamableHttpTransport` do SDK num controller Symfony em `/mcp`, convertendo a requisição para PSR-7 com
  `symfony/psr-http-message-bridge` (novo) e as fábricas PSR-17 já ligadas.
- **Middleware**: CORS continua fechado (os clientes remotos chamam de servidor a servidor); o `Origin`, quando vier, é
  validado; proteção de rebinding com a lista de hosts `bluejus.com.br`; versão do protocolo conforme 3.5. Não copiar o
  exemplo de servidor local do SDK.
- **Sessão MCP**: `Psr16SessionStore` com pool próprio (3.5); o `InMemorySessionStore` padrão não serve com vários workers.
- **Firewall**: um firewall novo para `^/mcp`, sem sessão (`stateless`), com autenticador de bearer, declarado antes do
  `main`. `/.well-known/oauth-*`, `/oauth/token` e `/oauth/register` com `PUBLIC_ACCESS`, como `^/api/webhooks/`
  (`security.yaml:71`). `/oauth/authorize` fica no `main` (login, "lembrar de mim").
- **Listeners de `kernel.request` que precisam deixar as rotas novas passar** (a lista é por nome de rota; os nomes abaixo
  são sugestão):
  - `TenantContextValidatorListener::ROTAS_IGNORADAS` (`app/src/EventListener/TenantContextValidatorListener.php:31`)
    recebe a rota do `/mcp`, a do `/oauth/authorize`, a do `/oauth/token`, a do `/oauth/register` e as duas do
    `/.well-known/oauth-*`. **Por quê:** no `/mcp` o firewall de bearer autentica a pessoa, mas não há sessão, então
    `hasCurrentTenant()` é falso e o listener responde com redirecionamento para `tenant_selecionar` (`:135-145`): o
    cliente de I.A. recebe um 302 para uma página HTML em vez da resposta MCP, e a conexão quebra. As barreiras que o
    listener aplicaria são do portão (4.1), que as aplica com o escritório do token. No `/oauth/authorize`, a pessoa ainda
    não tem escritório escolhido para o conector, e é a própria tela que o escolhe. Token, registro e metadados são chamados
    de servidor para servidor, sem pessoa logada (o listener já sai cedo sem `User`), mas entram na lista para que uma sessão
    de navegador logada e sem escritório também não seja redirecionada.
  - `TermoAceiteListener::ROTAS_IGNORADAS` (`app/src/EventListener/TermoAceiteListener.php:34`) recebe as mesmas, **menos
    o `/oauth/authorize`**. **Por quê:** o listener guarda o aceite na sessão (`:111`) e, sem ela, responderia 302 para
    `termo_aceite` ou 403 em JSON para quem não aceitou a versão vigente; no `/mcp` essa conferência passa a ser do portão
    (5.2). O `/oauth/authorize` continua passando por ele: quem não aceitou os termos aceita na tela, antes de conectar.
  - O `SessaoEncerradaEmTodosOsAparelhosListener` sai cedo sem sessão anterior e não precisa de mudança; a regra dele vai
    para o validador do token (seção 4, regra 3).
  - Teste funcional (12.18): bearer válido no `/mcp`, sem cookie, recebe a resposta MCP, nunca 302, e a resposta não traz
    `Set-Cookie`.
- **Limites de taxa** (no `framework.yaml`, junto dos limitadores que já existem): `conector_registro` (DCR, por IP), `conector_token` (por
  cliente e IP), `conector_chamada` (por pessoa) e `conector_cimd` (buscas de documento, por domínio).
- **nginx**: os dois ajustes de 3.3.
- **Rede**: o Claude sai de `160.79.104.0/21`; ChatGPT e Google têm faixas publicadas. Não há WAF hoje; se entrar um, liberar
  as faixas, inclusive para os endpoints OAuth.
- **Chaves de assinatura do token**: par gerado e guardado no `.env.prod` pelo Samuel (segredo; nunca lido pelo Claude), com
  a rotação descrita no guia de deploy.
- **Deploy**: dependência nova só existe em produção depois do deploy da imagem.

---

## 9. Segurança

1. **Vida do token: 7 dias. Decidido pelo dono (11/08/2026).** Escolha de comodidade: a pessoa autoriza uma vez por semana,
   não todo dia. Como isso vira token e renovação é a decisão 14.c.
2. **Revogação imediata, e ela é o que torna os 7 dias aceitáveis. Decidido.** O token é um papel assinado: diz "pessoa X,
   escritório Y" e **não se atualiza** quando a realidade muda. Por isso, **a cada requisição MCP** (não uma vez na conexão),
   o servidor confere **no banco**, e recusa se qualquer um falhar, mesmo com token dentro da validade:
   - `User::isActive` (`app/src/Entity/Auth/User.php:38`; o `UserChecker` só barra no login);
   - `UserTenant::isActive` e a situação na equipe aprovada (`app/src/Entity/Auth/UserTenant.php:40`, `:44`);
   - `Tenant::isActive` e `excluidoEm` nulo (`app/src/Entity/Tenant/Tenant.php:56`, `:59`);
   - pedido de exclusão (`BloqueioPorPedidoDeExclusao`, `app/src/Tenant/Service/BloqueioPorPedidoDeExclusao.php:43`),
     **inclusive para o primário**: `bloqueia()` (`:66`) deixa o primário passar porque o navegador o prende ao Meu Perfil,
     e o conector, que não tem Meu Perfil, recusa (seção 4, regra 1);
   - termos de uso aceitos na versão vigente (5.2);
   - "Sair de todos os aparelhos": token e renovação com `autorizado_em` menor ou igual a `User::getSessoesEncerradasEm()`
     são recusados (seção 4, regra 3);
   - troca de senha: impressão da senha gravada no token e na renovação diferente da do hash atual é recusada (seção 4,
     regra 3);
   - consentimento e token não revogados.

   Tudo isso vale também no `/oauth/token` ao trocar a renovação: renovação que falha em qualquer item é revogada com a
   cadeia inteira e responde `invalid_grant`, que é o que os clientes esperam para pedir login de novo (3.4).

   São as mesmas barreiras do navegador (4.1); a lista de agosto (só os dois `isActive`) ficou curta. Custa uma leitura dos
   registros que o servidor já carrega para aplicar permissão. **Sem isso, um token de 7 dias significa 7 dias de acesso
   para quem acabou de ser desligado.** Não implemente os 7 dias sem a conferência por requisição: são uma decisão só.
3. **Audiência (RFC 8707)**: `aud` = `https://bluejus.com.br/mcp`; token com outra audiência é recusado, e o token do
   conector não vale em nenhuma outra rota do sistema.
4. **Confused deputy**: consentimento por cliente; nunca reaproveitar consentimento entre `client_id` diferentes. A tela
   mostra o nome do cliente e o host do redirect, com aviso quando só há redirect de loopback (Claude Code, CLIs).
5. **CIMD**: proteção de SSRF e lista de domínios confiáveis (3.4).
6. **Redirect e código**: igualdade exata do `redirect_uri`; loopback com porta livre só para `127.0.0.1` e `localhost`
   (RFC 8252). No **DCR**, o registro só aceita `redirect_uris` de host da mesma lista permitida do CIMD ou de loopback, e
   recusa o resto com `invalid_redirect_uri` (3.4, item 2); a tela de consentimento continua mostrando o host (item 4). PKCE S256 obrigatório para todo cliente. Código de uso único, válido por até 10 minutos, preso ao
   `redirect_uri` e ao `code_challenge`. Renovação (se houver) com rotação e `invalid_grant`.
7. **Auditoria**: escrita registrada pelo `audit_log` com o escritório certo (4.3); chamadas no canal `mcp` sem conteúdo
   (6.2.9). Correção da spec de agosto: "registrar os argumentos" vira registrar ids e valores de lista, nunca texto
   (regra 3 da Inteligencia).
8. **Dado pessoal e provedor do modelo**: decisão 14.a.
9. **Purga e exclusão**: as tabelas novas com escritório entram no `PurgarEscritorioUseCase`; excluir a conta ou o vínculo
   revoga consentimentos e tokens.
10. **Injeção indireta pelo conteúdo**: 6.6.

---

## 10. O que fica fora desta spec

- `consultar_sql` remoto (6.1); a v1 STDIO/SSH continua, só para o dono.
- Ponto eletrônico, cobrança, exclusões, prazo jurídico, andamento manual (6.5).
- Cadastro de cliente direto; o cadastro por proposta já existe no chat.
- Arquivo bruto por link.
- O app Gemini de consumidor, enquanto for só dos EUA (3.4).
- A versão 2026-07-28 do protocolo (PR próprio, 3.5).
- Cliente MCP próprio do BlueJus (aqui somos só servidor).

---

## 11. Riscos conhecidos

| Risco | Por que importa |
|---|---|
| **`TenantFilter` falha aberto** | Rota nova que esqueça de amarrar o escritório devolve tudo, sem erro. Item nº 1 do plano (PR 1). |
| **Guardas em listeners HTTP** | Cadeado, aba oculta, pasta excluída, função desligada, termos de uso, "Sair de todos os aparelhos" e barreiras do escritório não rodam no conector; cada uma precisa ser refeita e testada (5.2). E dois desses listeners quebram o `/mcp` se não o deixarem passar: redirecionam para `tenant_selecionar` ou `termo_aceite` (8). |
| **Auditoria sem escritório** | Escrita pelo conector gravaria `audit_log` com escritório nulo (4.3). |
| **nginx barra `.well-known`** | Falha silenciosa: o cliente só diz "não consegui conectar" (3.3). |
| **`mcp/sdk` experimental (0.7.1)** | API muda entre versões menores; a 2026-07-28 vai exigir atualização. Versão fixa, como na v1. |
| **Servidor de autorização dentro da aplicação** | Uma falha nele é uma falha no sistema inteiro, não num serviço isolado. |
| **DCR deprecated, mas necessário** | O Claude registra um cliente por conexão; sem expiração, a tabela cresce sem limite. |
| **Clientes mudam rápido** | Nomes, planos e regras de ChatGPT e Gemini mudaram várias vezes em 2026; o guia do usuário leva data e é revisto a cada trimestre. |
| **Injeção indireta** | Texto de documento pode instruir o assistente a gravar (6.6). |
| **Limites de tamanho por cliente** | Resposta grande demais é cortada ou recusada pelo cliente (6.2.7). |
| **Dado pessoal saindo para o provedor do modelo** | Decisão de negócio e de termos de uso, não só técnica (14.a). |

---

## 12. Testes que sustentam a feature

Escritos **antes** da implementação, e cada um provado reintroduzindo o defeito (disciplina que na v1 pegou seis premissas
erradas do plano, todas invisíveis à leitura):

1. **Cross-tenant**: pessoa do escritório A pede recurso do escritório B, por **cada** ferramenta, e recebe negativa (não
   lista vazia por acaso: negativa). Este é o teste que sustenta a spec.
2. **Sem escritório resolvível**, a requisição é recusada; o filtro nunca fica desligado. Com o filtro desligado à força, a
   ferramenta ainda não devolve dado de outro escritório (guarda explícita), e o portão recusa.
3. **Vínculo inativo, situação suspensa, escritório suspenso ou em quarentena, pedido de exclusão, pessoa desativada**: cada
   um recusa o token na requisição seguinte. Caso próprio: o **primário** de escritório suspenso a pedido é recusado no
   `/oauth/authorize` e no `/mcp` com a `BloqueioPorPedidoDeExclusao::MENSAGEM`, enquanto o mesmo primário, no navegador,
   continua chegando ao Meu Perfil (o teste do listener segue verde).
4. **Permissão**: pessoa sem o módulo recebe negativa mesmo com escopo `mcp:leitura`.
5. **Escopo**: token só com `mcp:leitura` não executa ferramenta de escrita (403 `insufficient_scope`), mesmo que a pessoa
   tenha a permissão.
6. **Revogação**: revogado o consentimento ou desativado o vínculo, o token para de funcionar.
7. **Fluxo OAuth completo** ponta a ponta, com PKCE, contra o servidor de verdade: CIMD, DCR e pré-registrado.
8. **Escrita** exige o escopo e gera registro de auditoria com o escritório e a pessoa do token.
9. **Cada guarda de 5.2** recusada pelo conector: cadeado, aba oculta, pasta excluída na escrita, função desligada no Master,
   meta por id, termos de uso. Para aba e chave, o teste percorre a **tabela de 6.2 linha por linha**: com a aba da linha
   oculta, a ferramenta recusa (inclusive `listar_documentos_da_pasta` com a aba Documentos oculta, que o UseCase sozinho
   deixaria passar); com a chave da linha desligada, recusa; e uma ferramenta nova sem linha na tabela derruba o teste.
10. **Segredo de justiça** (do tribunal e manual): lista omite e conta; por id recusa.
11. **Cliente só pelo nome**: nenhuma resposta de leitura traz CPF, CNPJ, e-mail, telefone ou endereço (teste que procura
    esses padrões nas respostas de todas as ferramentas, com dados de fábrica).
12. **CIMD**: `client_id` diferente da URL, redirect fora do documento, domínio fora da lista e endereço privado são recusados.
    **DCR**: registro com `redirect_uris` de host fora da lista permitida recebe `invalid_redirect_uri` e não cria cliente;
    host da lista e loopback passam; a tela de consentimento de um cliente registrado por DCR mostra o host do redirect.
13. **Metadados**: as duas URLs `.well-known` respondem pelo Symfony (funcional) e o `nginx.prod.conf` tem a location
    `^~ /.well-known/oauth-` antes do bloqueio de arquivos ocultos (arquitetura).
14. **Anotações**: toda ferramenta de leitura tem `readOnlyHint: true` e toda de escrita exige `mcp:escrita` (arquitetura).
15. **Log `mcp`** sem conteúdo: nenhuma linha leva texto de busca, título, nome ou conteúdo.
16. **"Sair de todos os aparelhos" e troca de senha**: depois de cada um, o token de acesso é recusado no `/mcp` e a
    renovação recebe `invalid_grant` no `/oauth/token`, inclusive a renovação já rodada uma vez depois da autorização (prova
    que a rotação copia o `autorizado_em`). Troca de senha testada pelo perfil (`AlterarSenhaUseCase`) e pelo link
    (`RedefinirSenhaUseCase`); autorização feita depois do corte ou da troca funciona.
17. **`ler_documento`**: HTML e DOCX recusados na primeira fatia com mensagem fixa (até o PR 7b); PDF e imagem recusados
    sem o aceite que a 14.a definir; documento da aba Documentos oculta recusado.
18. **`/mcp` sem sessão**: bearer válido, sem cookie, recebe a resposta MCP, nunca 302 para `tenant_selecionar` ou
    `termo_aceite`, e a resposta não traz `Set-Cookie`. Pessoa sem o aceite da versão vigente dos termos é recusada pelo
    portão com mensagem fixa.

---

## 13. Decomposição

### 13.1 PRs

Cada PR entrega software que funciona e é revisável sozinho. Estimativa em dias de trabalho de uma sessão, com testes e
revisão.

| # | Branch sugerida | Entrega | Depende de | Risco | Estimativa |
|---|---|---|---|---|---|
| 1 | `conector-portao` | Portão sem HTTP: barreiras do escritório extraídas do `TenantContextValidatorListener` para um serviço sem sessão (o listener passa a usá-lo, sem mudar comportamento), com o primário de escritório suspenso a pedido recusado no conector (seção 4, regra 1) e a conferência dos termos de uso (5.2); filtro ligado e conferido (fail-closed); escopo de auditoria do conector; teste de arquitetura | nada | ALTO | 2,5 dias |
| 2 | `conector-tabelas` | `composer` (`league/oauth2-server-bundle` ^2.0, `symfony/psr-http-message-bridge`), entidades e **uma** migration com as tabelas de 13.2 (precisa das decisões 14.a e 14.c, ver abaixo), purga do escritório | nada (paralelo ao 1) | ALTO | 1,5 dia |
| 3 | `conector-autorizacao` | `/oauth/authorize` (login, escolha do escritório, consentimento com escopos), `/oauth/token`, metadado RFC 8414, PKCE S256, `resource` para `aud`, `iss`, regras de redirect, `autorizado_em` e impressão da senha gravados no token e na renovação e conferidos na renovação (seção 4, regra 3), rotas nas listas dos dois listeners (8), chaves de assinatura (passo do Samuel), forma da decisão 14.c | 1, 2 | ALTO, com re-revisão | 3 dias |
| 4 | `conector-registro` | CIMD (busca segura, lista de domínios, cache), DCR em `/oauth/register` (limite, expiração, redirect só da lista permitida ou loopback) e comando de console para cliente pré-registrado | 3 | ALTO | 2 dias |
| 5 | `conector-mcp` | Firewall do `/mcp`, validador do token com o portão a cada requisição (inclusive corte de "Sair de todos os aparelhos" e impressão da senha), metadado RFC 9728 nas duas URLs, `/mcp` nas listas dos dois listeners (8), `StreamableHttpTransport`, sessão MCP, nginx (3.3), limites de taxa, log `mcp`, chave `conector_ia` do Master, ferramenta `quem_sou_eu` | 1, 3 (o 4 para cliente real) | ALTO, com re-revisão | 3 dias |
| 6 | `conector-leitura-1` | Registro de ferramentas compartilhado com o chat (mover sem reescrever); `buscar_pastas`, `minhas_demandas`, `detalhar_pasta`, `listar_prazos_e_metas`, `listar_agenda`; aba e chave de cada uma pela tabela de 6.2; teste cross-tenant em cada | 5 | MÉDIO | 3 dias |
| 7 | `conector-leitura-2` | UseCase de processo e movimentações recentes; `buscar_processos`, `detalhar_processo`, `listar_documentos_da_pasta` (com a barreira da aba Documentos), `ler_documento` só com PDF e imagem (porta do aceite conforme 14.a), `listar_modelos_de_peca`, `ler_modelo_de_peca` (aba Detalhes) | 6 | MÉDIO | 3 dias |
| 7b | `conector-leitura-texto` | `ler_documento` com peça do editor (HTML) e DOCX como texto: método novo no `LeitorDeDocumentosDaPasta` que reusa a conferência do pedido, reconhecimento pelo conteúdo, conversão da peça, `ExtratorDeTextoDocx` num lugar comum (6.4); só se a 14.f escolher a opção 2 | 7 | MÉDIO | 1,5 dia |
| 8 | `conector-telas` | "Conexões de I.A." no Meu Perfil (listar e revogar), chave e aceite do escritório conforme 14.a, item no painel do Master; depende do desenho (14.e) | 3 | MÉDIO | 2 dias |
| 9 | `conector-escrita-1` | `gerar_minuta_de_modelo`, `salvar_minuta_na_pasta`, escopo `mcp:escrita`, auditoria com escritório | 7 e decisão 14.b | ALTO | 2 dias |
| 10 | `conector-escrita-2` | O que a decisão 14.b escolher entre `anotar_na_pasta`, `criar_meta` (com o `CriarMetaUseCase` extraído do `TarefaController`) e `criar_compromisso` | 9 | MÉDIO | 3 dias |
| 11 | `conector-abertura` | Guia passo a passo do usuário (Claude, ChatGPT e, se decidido, Gemini Enterprise), novidades, `mudancas/`, ajuste de limites depois do teste com os clientes reais (smoke do Samuel), diretórios se 14.d pedir | todos | BAIXO | 1,5 dia |

**Total: cerca de 28 dias de trabalho** (25 sem o PR 10; 26,5 se a 14.f não escolher o PR 7b). Caminho crítico
1 → 3 → 5 → 6 → 7 → 9 → 11, com o PR 2 em paralelo ao 1: **cerca de 18 dias**; os PRs 4, 7b, 8 e 10 correm ao lado. A
revisão de 08/11 somou 2,5 dias à versão anterior desta seção: 0,5 no PR 1 (primário recusado e termos), 0,5 no PR 5
(corte de "Sair de todos os aparelhos" e senha) e 1,5 no PR 7b (HTML e DOCX, que o leitor de hoje recusa).

- **Uma migration só (PR 2), desde que as decisões 14.a e 14.c venham antes do PR 2.** As duas decidem se entram
  `conector_ia_escritorio` (14.a, opção 2) e `conector_ia_renovacao` (14.c, opção 2 ou 3). Se alguma não vier a tempo, o
  PR 2 sai sem a tabela correspondente e ela entra numa **segunda migration**, no PR que a usa primeiro:
  `conector_ia_renovacao` no PR 3, `conector_ia_escritorio` no PR 7 (porta do aceite) ou no PR 8 (telas), o que vier antes.
  Cada migration pede número ao Coordenador e respeita a regra de uma migration por vez entre os PRs abertos.
- Arquivos quentes (avisar o Coordenador no início de cada PR que os toca): `composer.json`/`composer.lock`,
  `security.yaml`, `framework.yaml`, `nginx.prod.conf`, a migration.
- Os PRs 1, 3, 4, 5 e 9 mexem em identidade ou abrem escrita: revisão contra esta spec e re-revisão antes do seguinte.
- **Piloto:** depois do PR 7 o escritório piloto já usa só leitura, pelo Claude e pelo ChatGPT; escrita depois do PR 9.

### 13.2 Tabelas novas

Sem número de migration: o Coordenador numera. Guardar só o **hash** de código e token, nunca o valor. Os nomes finais dependem
de o bundle aceitar gerenciadores próprios (3.2); a recomendação é gerenciadores próprios, para ter escritório e consentimento.

| Tabela | Para quê | Colunas principais | Escritório |
|---|---|---|---|
| `conector_ia_cliente` | o app de I.A. registrado (CIMD, DCR ou pré-registrado) | `identificador` (único; a URL no CIMD), `tipo` (`cimd`, `dcr`, `pre_registrado`), `nome`, `redirect_uris` (JSON), `segredo_hash` (só pré-registrado confidencial), `tenant_id` (nulo, salvo pré-registrado de um escritório), `metadados_buscados_em`, `metadados_validos_ate`, `ativo`, `criado_em`, `ultimo_uso_em` | **global por natureza** (o ChatGPT é o mesmo cliente para todos os escritórios): exceção declarada à regra "toda consulta filtra tenant"; nenhum dado de escritório passa por ela |
| `conector_ia_codigo` | código de autorização | `identificador_hash`, `cliente_id`, `user_id`, `tenant_id`, `redirect_uri`, `code_challenge`, `escopos`, `resource`, `expira_em` (até 10 min), `usado_em` | sim |
| `conector_ia_token` | token de acesso | `identificador_hash` (jti), `cliente_id`, `user_id`, `tenant_id`, `consentimento_id`, `escopos`, `audiencia`, `autorizado_em` (instante do login que originou a cadeia), `impressao_da_senha` (HMAC do hash da senha), `criado_em`, `expira_em`, `revogado_em`, `ultimo_uso_em` | sim |
| `conector_ia_renovacao` | token de renovação, só se 14.c escolher renovação | `identificador_hash`, `token_id`, `autorizado_em` (copiado na rotação), `impressao_da_senha`, `expira_em` (limite absoluto da autorização), `revogado_em`, `substituido_por` | sim (pelo token) |
| `conector_ia_consentimento` | a "conexão" que a pessoa vê e revoga | `user_id`, `tenant_id`, `cliente_id`, `escopos`, `concedido_em`, `revogado_em`, `ultimo_uso_em`; uma ativa por pessoa, escritório e cliente; `Auditavel` (conceder, revogar) | sim |
| `conector_ia_escritorio` | chave e aceite do escritório, só se 14.a escolher a opção 2 | `tenant_id` (único), `ligado`, `aceite_em`, `aceite_versao`, `aceito_por_user_id`; `Auditavel` | sim |

Toda tabela com escritório entra no `PurgarEscritorioUseCase`.

---

## 14. Decisões do dono e o que ainda falta

**Decidido em 11/08/2026 (mantido):**

1. **Quem pode conectar:** **todo usuário que tem conta no sistema.** O controle de acesso do sistema continua valendo
   integralmente: é preciso conta e `UserTenant` **ativo** com o escritório, igual ao navegador. O que a decisão diz é que
   **o conector não acrescenta um filtro próprio por pessoa**: não existe "só sócio usa" nem "só quem tem a permissão X pode
   conectar". Quem usa o sistema usa o conector, e enxerga o que a permissão dele já permite.

   **Consequência para o plano:** não sobra nenhuma camada de "só gente de confiança chega aqui" para servir de rede. Todo o
   controle mora nas checagens por requisição: escritório certo (seção 4) e permissão certa (seção 5). Se uma falhar, não há
   um segundo muro atrás. Por isso o teste cross-tenant (seção 12, item 1) é **o** teste que sustenta a feature.
2. **Vida do token: 7 dias, com conferência do vínculo a cada requisição** (seção 9, itens 1 e 2). As duas metades são
   inseparáveis.

**Ainda em aberto (para o Samuel; cada uma com opções e recomendação):**

**a) Dado pessoal saindo para o provedor do modelo (LGPD).** **Antes do PR 2**, porque decide se a tabela
`conector_ia_escritorio` entra na migration única (13.1); e antes de abrir a outros escritórios.

O que já está decidido para a I.A. de hoje: ela fala só com a Anthropic, que o BlueJus contrata; o dado só sai com o aceite do
escritório (tela `/admin/inteligencia`, "nos termos do Anexo I"; versão 2 para documentos, `VersaoDoAceiteDaIa`); do cliente
sai só o nome; CPF, e-mail e telefone digitados são mascarados; segredo de justiça nunca sai. A retenção zero (ZDR) foi
decidida como pedido à Anthropic (24/10/2026), mas o piloto roda com a retenção padrão, só no escritório do Farlei, com todos
cientes (26/10/2026); a base legal do art. 33 da LGPD está com o jurídico (`docs/specs/ia-anthropic-ligar.md`).

**Pelo conector é diferente:** o provedor é o que a pessoa escolheu (OpenAI, Google ou Anthropic), pela conta dela e sob o
contrato dela. O BlueJus não tem contrato, ZDR nem DPA com esse provedor, e um plano de consumidor pode usar a conversa para
treino, conforme a configuração da conta. O aceite atual da I.A. não cobre isso: o texto fala do provedor que o BlueJus
contrata.

- Opção 1: abrir a todos os escritórios com as regras de saída de 6.2 e uma linha nos termos de uso.
- Opção 2: piloto no escritório do Farlei. Para os demais, o conector nasce desligado e o administrador do escritório liga
  com um **aceite próprio do conector** (texto novo e com versão, dizendo que o dado vai para a conta de I.A. de cada pessoa e
  recomendando plano empresarial ou sem treino); o Master tem a chave geral (`conector_ia`); regras de saída de 6.2; conteúdo
  de documento só com a versão do aceite que cobre documentos.
- Opção 3: como a 2, mas reaproveitando o aceite atual da I.A. (`ConfiguracaoDeInteligencia::aceiteDeEnvioDado`). Mais
  simples, mas o texto não cobre provedor escolhido pela pessoa.

**Recomendação: opção 2.** A chave é do escritório, não um filtro por pessoa, então não contraria a decisão 1; confirmar.
Pela regra de 04/11/2026 (o jurídico não trava a implementação), o trabalho segue e o texto do aceite e dos termos vai para o
pacote jurídico (`bluejus-coordenacao/pacote-juridico.md`); abrir a outro escritório espera o texto dos advogados.

**b) Quais escritas entram primeiro.** Antes do PR 9.

- Candidatas (6.5): minutas (`gerar_minuta_de_modelo`, `salvar_minuta_na_pasta`); anotar na pasta; criar meta (exige extrair
  o UseCase, mais 1 dia); criar compromisso; registrar prazo jurídico; registrar andamento (não existe); cadastro por
  proposta, como no chat.

**Recomendação:** o PR 9 só com as **duas de minuta**. São o pedido direto do Samuel ("criar peças, minutas"), criam documento
novo que se desfaz pela lixeira, e o dado sensível é preenchido no servidor. Depois de duas semanas de uso do piloto, escolher
o PR 10 pelo que mais apareceu (o log `mcp` conta chamadas por ferramenta, sem conteúdo), com `anotar_na_pasta` e
`criar_meta` como favoritas. Prazo jurídico e andamento ficam fora. **Escolher pelo uso real, não pelo custo de implementar.**

**c) Como os 7 dias funcionam na prática.** **Antes do PR 2**, porque decide se a tabela `conector_ia_renovacao` entra na
migration única (13.1). A decisão de 11/08 fica; a dúvida é técnica, com efeito na
experiência: os clientes renovam o token sozinhos se houver token de renovação (o Claude pede `offline_access` quando ele é
anunciado).

- Opção 1: token de acesso de 7 dias, sem renovação. A pessoa refaz o login a cada 7 dias (leitura literal da decisão).
- Opção 2: token de acesso de 1 hora, com renovação e rotação até completar 7 dias da autorização. Mesma experiência (um
  login por semana); um token vazado vale 1 hora.
- Opção 3: renovação que se estende enquanto a pessoa usa (conexão sem fim). Muda a decisão de 11/08.

**Recomendação: opção 2.**

**d) Quais clientes atender primeiro e onde publicar.** Antes do PR 11. Decisão nova, vinda da pesquisa (3.4).

- Claude: todos os planos (o Free com 1 conector personalizado); no Team e no Enterprise, só o Owner adiciona, e cada pessoa
  conecta com a própria conta (https://support.claude.com/en/articles/11175166).
- ChatGPT: o MCP completo, com escrita, está em beta para Business, Enterprise e Edu pelo modo desenvolvedor, liberado pelo
  admin do workspace (https://help.openai.com/en/articles/12584461; vista só pelo trecho da busca, porque a página devolveu
  403). Plus e Pro: fontes não oficiais divergem; conferir no piloto. Publicar no diretório exige revisão e verificação da
  organização.
- Gemini: o app de consumidor só funciona nos EUA, então fica fora para o Brasil. O Gemini Enterprise funciona, mas o admin do
  escritório configura no Google Cloud com um client ID e um segredo nossos (cliente pré-registrado).
- Opção 1: Claude e ChatGPT como conector personalizado (a pessoa cola a URL `https://bluejus.com.br/mcp`).
- Opção 2: a 1 mais Gemini Enterprise.
- Opção 3: a 2 mais publicação nos diretórios do Claude e do ChatGPT.

**Recomendação: opção 1 no piloto**, com a lista de domínios do CIMD em `claude.ai`, `chatgpt.com` e `antigravity.google`.
A mesma lista vale para os `redirect_uris` do DCR (3.4, item 2): incluir um cliente novo é mexer nela, num lugar só.
Gemini Enterprise quando o primeiro escritório pedir (comando de console do PR 4). Diretórios depois do piloto (exigem conta
de teste, revisão e textos próprios).

**e) Telas novas sem desenho.** Antes dos PRs 3 e 8. Decisão nova.

São duas telas que não existem no sistema nem em desenho: a de **autorização** (o login já existe; falta escolher o
escritório, marcar "permitir criar documentos", ver o nome do app e o endereço de retorno) e a de **Conexões de I.A.** (no Meu
Perfil, listar e revogar; para o administrador, ligar e dar o aceite; no Master, a chave geral).

- Opção 1: pedir desenho ao Farlei antes dos dois PRs.
- Opção 2: fazer pela convenção do sistema e trocar quando o desenho vier.

**Recomendação:** pedir o desenho já. A tela de autorização do PR 3 sai pela convenção da tela de login (é passagem, não tela
de uso); a de conexões (PR 8) espera o desenho.

**f) Que documentos o `ler_documento` entrega.** Antes do PR 7. Decisão nova, refeita na revisão de 08/11.

O leitor que existe (`LeitorDeDocumentosDaPasta::anexos`) já faz PDF e imagem com todas as guardas, e recusa o resto (6.4). A
peça do editor (HTML) e o DOCX precisam de um caminho novo no mesmo leitor (PR 7b, 1,5 dia). O MCP manda imagem como
conteúdo padrão; PDF só como recurso embutido, e não achei confirmação oficial de que Claude, ChatGPT e Gemini leem PDF
devolvido por ferramenta.

- Opção 1: só PDF e imagem, como binário marcado "experimental", pelo leitor de hoje. HTML e DOCX ficam fora.
- Opção 2: a 1 mais peça do editor e DOCX como texto (PR 7b).
- Opção 3: extrair o texto do PDF no servidor (dependência nova; PDF escaneado continua sem texto, porque não fazemos OCR).

**Recomendação: opção 2.** A peça do editor é o formato das minutas que o próprio conector grava (6.5): sem o PR 7b, o
assistente cria a minuta e não consegue relê-la. O PR 7b corre ao lado do PR 9 e não atrasa o caminho crítico. A escolha de
extrair texto do PDF sai do teste com o piloto.
