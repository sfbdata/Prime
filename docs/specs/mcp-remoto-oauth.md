# Conector de I.A. (MCP remoto com OAuth 2.1) para todos os escritórios

**Data:** 11/08/2026 (desenho original). **Revisão:** 08/11/2026, conferida contra o master `a53a77b1c` (v2.42.0).
**Escopo e decisões do dono:** 09/11/2026, conferidos contra o master `940e82dfd`, com o inventário
`bluejus-coordenacao/conector-inventario-tudo.md`. **Correções da revisão do PR #699:** 09/11/2026, no mesmo master.
**Risco:** **ALTO**. Mexe em identidade (`User`/`Tenant`) e abre um caminho novo de acesso aos dados de todos os
escritórios, fora do navegador. Pelo ciclo do projeto: spec (este documento), revisão contra a spec e **re-revisão antes de
seguir** em cada PR de identidade.
**Status:** pronto para o PR 1 de código (09/11/2026); decisões do dono na seção 14. Não existe código, tabela nem
migration desta feature.

**Predecessor:** [MCP de investigação em produção (v1)](../superpowers/specs/2026-08-10-mcp-investigacao-prod-design.md),
em produção; a seção 10 dele previa esta etapa.
**Relacionadas:** `chat-ia-cadastro.md`, `chat-ia-prazos-e-metas.md`, `chat-pagina-inteira.md`, `designer-central-ia.md`
(D9 "token pessoal por usuário" e Fase 4 "conector MCP": esta spec é o caminho delas), `ia-anthropic-ligar.md`,
`inteligencia-provedor-anthropic.md`, `pasta-cadeado.md`, `docs/AUTORIZACAO.md`.

Arquivo:linha citados foram conferidos em 08/11/2026 no commit `a53a77b1c` e, nas seções 6, 13 e 14, em 09/11/2026 no
`940e82dfd`. Páginas da web foram lidas em 07, 08 e 09/11/2026; quando a página mostra data, ela vem ao lado do link.

---

## 0. O que mudou de 11/08 para cá

| Ponto | Em 11/08/2026 | Em 08 e 09/11/2026 |
|---|---|---|
| Objetivo | ler e operar o que a conta permite | **tudo o que a pessoa faz em Pastas, Agenda, Clientes e Processos, menos funções de administrador**, com três pedidos à frente: pasta e esteira, documentos, cadastro de cliente (seção 2, 14.b) |
| `TenantFilter` | falha aberto | **continua** falhando aberto; há três listeners de tenant no `kernel.request` e nenhuma trava fail-closed (4.1) |
| Tenant explícito sem sessão | não existia | é o padrão do worker da I.A., do Chat I.A e do Agente de Pastas; o Agente foi escrito pensando neste conector (4.2) |
| `PermissionChecker` | 4 métodos | 8 públicos, cache por vínculo e cadeado da pasta; `canActOnResource` saiu em 30/06; `resources.*` está vivo (5) |
| Guardas em listener HTTP | não citadas | cadeado, aba oculta, pasta excluída, recurso desligado pelo Master e barreiras do escritório: o conector pula todas e tem de refazer (5.2) |
| Ferramentas | 5 de leitura e 3 de escrita, desenhadas sem base | **17** (7 de leitura, 9 de gravação, 1 de confirmação) sobre as 187 ações do inventário, com teto próprio contra o bypass do Master e do primário (6, 6.3) |
| Exclusão e cadastro | escrita direta | só com uma confirmação presa à proposta, de validade curta e uso único, conferida de novo no servidor; gravar por cima de peça ou anotação conta como exclusão (6.6, 6.7) |
| Documentos | PDF e imagem | todos os tipos da aba Documentos, menos áudio e vídeo (6.5) |
| Cliente e Processo | "legado sem UseCase" | `Processo` está no domínio; Cliente tem 8 UseCases; Tarefa continua legado (7) |
| nginx | "chega ao Symfony sem mudança" | **errado já em agosto**: o `location ~ /\. { deny all; }` do HTTPS barra `/.well-known/oauth-*` (3.3) |
| `mcp/sdk` | 0.7.0 | 0.7.1 instalada; o DCR por delegação existe desde a 0.5.0 (`CHANGELOG.md`, "Add OAuth 2.0 Dynamic Client Registration middleware"), e a versão instalada também tem elicitation do lado do servidor e anotações `readOnlyHint`; continua sem servidor de autorização (3.1) |
| `symfony/mcp-bundle` como servidor de autorização | proposta aberta | PR fechado sem merge em 12/07/2026; segue o `league` (3.2) |
| `league/oauth2-server-bundle` | "testado até Symfony 8.0" | v2.0.0 (out/2026) pede Symfony `^7.4` ou `^8.1` e PHP `^8.2`: serve (3.2) |
| Especificação MCP | 2025-06-18, DCR recomendado | a atual é **2026-07-28**: CIMD é o preferido, DCR está **deprecated**, protocolo sem estado (3.4, 3.5) |
| Clientes | só o Claude em mente | Claude, ChatGPT e Gemini pesquisados; cada um aceita um jeito de registro (3.4); o piloto atende Claude e ChatGPT (14.d) |
| Auditoria | "usar o domínio Auditoria" | o `AuditLogSubscriber` tira o escritório da sessão: com bearer, grava sem escritório se nada for feito (4.3) |
| Decisões | 2 tomadas | 12 tomadas (seção 14) |

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
  Agente de Pastas). São reaproveitados aqui (6.4).
- **Chat I.A** (`/chat-ia`, `app/src/ChatIa/`): o modelo é o da Anthropic, contratado pelo BlueJus, e as ferramentas
  (`propor_cliente`, `propor_pasta`, `consultar_prazos_e_metas`) rodam dentro do nosso servidor. O conector é o inverso: o
  modelo é o da conta da pessoa (Claude, ChatGPT, Gemini) e o BlueJus só responde às ferramentas. As peças de ferramenta do
  chat são reaproveitadas (6.8).

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

- **ler e atualizar tudo da pasta**, inclusive a esteira de andamento do topo (pedido A, 14.b);
- **ler os documentos da pasta**, de todos os tipos menos áudio e vídeo (pedido B, 14.f);
- **cadastrar cliente e completar os dados**, um ou uma lista (pedido C);
- e fazer o resto do que a pessoa faz em **Pastas, Agenda, Clientes e Processos**: processos e movimentações, prazos e metas
  da pasta, agenda, peças e modelos, financeiro da pasta, publicações do Diário da pasta;

sempre dentro do que a conta dela já permite na interface web, nem mais, nem menos, **e nunca uma função de administrador**,
mesmo para o Master e o primário do escritório (6.3).

Critérios de aceitação centrais:

1. **Um usuário do escritório A não consegue, por nenhum caminho, ler ou alterar dado do escritório B.** Provado por teste
   cross-tenant em cada ferramenta, não por inspeção.
2. **Nenhuma ferramenta devolve o que a tela não mostraria à mesma pessoa**, nem o que as regras de saída proíbem (6.2:
   segredo de justiça, documento e contato do cliente, financeiro sem permissão).
3. **Nada é excluído e nenhum cliente é cadastrado sem uma confirmação presa à proposta** (6.7): o servidor só executa com a
   confirmação da mesma pessoa, no mesmo escritório, consentimento e cliente OAuth, de uso único e em até 10 minutos. Ele
   **não prova que uma pessoa leu**: com "Permitir sempre" no claude.ai ou "lembrar" no ChatGPT, o próprio modelo pode chamar
   `confirmar_acao` (risco residual, 11 e 14.g). E **nenhuma ação manda algo ao cliente ou a terceiro**; a única exceção é o
   Google Agenda de quem criou o compromisso e dos participantes, que são da equipe (`gravar_na_agenda`, 14.h).

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
  confirmação, 6.7) e `src/Server/ClientGateway.php::elicit()` (pedir confirmação ao usuário pelo protocolo; suporte varia
  por cliente; usado na 6.7).
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
   (SSRF), tempo curto, tamanho máximo, cache pelos cabeçalhos. **Lista de domínios confiáveis** ligada desde o começo:
   `claude.ai` (cobre o Claude e o Claude Code) e `chatgpt.com` (decisão 14.d); domínio fora dela é recusado. A
   documentação lida não diz em que domínio fica o `client_id` do CIMD do Claude (claude.ai, Desktop, celular); só o do
   Claude Code está escrito (`claude.ai`). **Item de conferência do PR 3:** ver o `client_id` real que o Claude manda ao
   `/oauth/authorize` e, se for outro domínio, ajustar esta lista (e a do DCR, que é a mesma).
2. **DCR no mesmo PR, apesar de deprecated.** É o fallback do Claude (e o único caminho do Gemini, fora do piloto). Com
   limite de taxa por IP e expiração em 24 h do cliente que não autorizou ninguém (o Claude registra um cliente por conexão).
   **`redirect_uris` só de host da mesma lista permitida do CIMD** (uma lista só, num lugar só, para os dois caminhos:
   `claude.ai` e `chatgpt.com`, 14.d) ou de loopback (`127.0.0.1` e `localhost`,
   para as ferramentas de linha de comando, RFC 8252). Qualquer outro host é recusado no próprio registro com
   `invalid_redirect_uri` (RFC 7591). Sem isso, o DCR anula a lista do CIMD: quem quisesse fugir dela registraria um cliente
   com redirect para o próprio domínio e mandaria o link de autorização para a vítima. A tela de consentimento continua
   mostrando o host do redirect (9.4).
3. **Pré-registrado: fora do piloto** (14.d). Só o Gemini Enterprise precisa dele (client ID e segredo digitados pelo
   admin do escritório). Quando entrar: comando de console, sem tela, cliente confidencial, segredo guardado só como hash,
   preso ao escritório que pediu. A tabela `conector_ia_cliente` já nasce com o tipo e o `segredo_hash` (13.2).

**Metadado do servidor de autorização** (mínimo para os três): `issuer`, `authorization_endpoint`, `token_endpoint`,
`registration_endpoint`, `response_types_supported: ["code"]`, `grant_types_supported` (`authorization_code` e
`refresh_token`, decisão 14.c), `code_challenge_methods_supported: ["S256"]`,
`token_endpoint_auth_methods_supported: ["none", "client_secret_basic", "client_secret_post"]`,
`client_id_metadata_document_supported: true`, `authorization_response_iss_parameter_supported: true`, `scopes_supported`.

**Metadado do recurso:** `resource: "https://bluejus.com.br/mcp"` (igual à URL que a pessoa digita),
`authorization_servers: ["https://bluejus.com.br"]` (um só), `scopes_supported: ["mcp:leitura", "mcp:escrita",
"offline_access"]` (5.1).

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

1. **Os bypasses não valem no conector** (teto, 6.3). Nem o de `ROLE_SUPER_ADMIN` (global,
   `PermissionChecker::isGlobalSuperAdmin`, primeiro passo de todo método público) nem o de `TenantRole::isSystem()`
   (primário, por escritório): para a pessoa do token, os dois respondem falso, e ela conta pelo perfil do vínculo e pelas
   regras de item (cadeado, abas ocultas), como qualquer colega. O inventário classifica esse passe como função de
   administrador (seção 5, última linha), e a decisão 14.b tira as funções de administrador.
2. **Módulo e recurso são eixos paralelos** (`docs/AUTORIZACAO.md` seção 7, decisão de 30/06/2026): listagem e criação pedem
   o módulo; item por id pede o recurso. As ferramentas seguem isso: busca, lista e criação exigem o módulo; leitura e
   gravação de um item por id exigem o recurso daquele item.
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
marcar a caixa na tela de consentimento (desmarcada por padrão). Ferramenta de gravação ou a `confirmar_acao` chamada sem o
escopo responde 403 com `error="insufficient_scope"` e `scope="mcp:leitura mcp:escrita"` (step-up da especificação atual).
`offline_access` é anunciado (decisão 14.c), porque o Claude o pede quando ele é anunciado; ele não amplia nada, só deixa
o cliente renovar o token dentro dos 7 dias.

### 5.2 As guardas que o conector pula, e como refazer cada uma

| Guarda | Onde mora hoje | O que a ferramenta faz |
|---|---|---|
| Barreiras do escritório (conta suspensa ou excluída, pedido de exclusão, vínculo, situação na equipe) | `TenantContextValidatorListener` (`kernel.request`) | o portão, pelo serviço extraído (4.1); o primário de escritório suspenso a pedido é **recusado** (regra 1 da seção 4) |
| Termos de uso aceitos na versão vigente | `TermoAceiteListener` (prioridade 7, `app/src/EventListener/TermoAceiteListener.php:90-121`), que usa a sessão (`:111`) | o `/oauth/authorize` passa pelo listener como hoje (a pessoa aceita na tela); no `/mcp`, o portão confere `AceiteTermoRepository::existeAceiteVigente` (`app/src/Termo/Repository/AceiteTermoRepository.php:39`) a cada requisição e recusa com "aceite os Termos de Uso no BlueJus" (8) |
| "Sair de todos os aparelhos" | `SessaoEncerradaEmTodosOsAparelhosListener` (prioridade 7), que só age com sessão | `autorizado_em` do token e da renovação contra `User::getSessoesEncerradasEm()` (regra 3 da seção 4) |
| Troca de senha | o Symfony, ao reler o `User` da sessão (sem `EquatableInterface`, compara o hash da senha) | impressão da senha no token e na renovação (regra 3 da seção 4) |
| Cadeado da pasta | `BarreiraDoCadeadoListener` (`kernel.controller_arguments`, `app/src/Pasta/EventListener/BarreiraDoCadeadoListener.php:39`) | `AcessoAPasta::podeVer`/`podeEditar` (`app/src/Pasta/Service/AcessoAPasta.php:42`, `:47`) no item; `idsOcultosPara` (`:118`) e `idsDosProcessosOcultos` em lista. `podeVer` usa o escritório **da pasta**: conferir antes que a pasta é do escritório do token |
| Aba oculta | `BarreiraDeAbaOcultaListener` (`app/src/Pasta/EventListener/BarreiraDeAbaOcultaListener.php:37`, que lê o escritório da sessão), pelo nome da rota (`MAPA`, `:50-79`) | `VisibilidadeDasAbasDaPasta::abasOcultasPara` (`app/src/Pasta/Service/VisibilidadeDasAbasDaPasta.php:115`), com a aba que a tabela de 6.6 dá para cada ferramenta e ação |
| Pasta excluída é só leitura | `PastaSomenteLeituraListener` | escrita confere `Pasta::estaExcluida()` (`app/src/Pasta/Entity/Pasta.php:450`) |
| Tela ou função desligada pelo Master | `BloqueioDeRecursoDesativado`, por prefixo do nome da rota (`CatalogoDeRecursos::ROTAS`, `app/src/Master/Service/CatalogoDeRecursos.php:59-74`: `peticionar`, `metas`, `expedientes`, `assistente`...); o Master passa (`BloqueioDeRecursoDesativado.php:50`) | `RecursosDoSistema::estaAtivo(chave)` (`app/src/Master/Service/RecursosDoSistema.php:58`) na ferramenta, com a chave que a tabela de 6.6 dá; no conector a chave desligada vale **também para o Master** (teto, 6.3); chave nova `conector_ia` no catálogo para o Master desligar o conector inteiro (14.a) |
| Meta aberta por id | `AcessoAMeta::exigir` (`docs/AUTORIZACAO.md`, "Meta aberta por id") | a mesma guarda antes de ler ou escrever meta por id |
| Só acompanhar meta não basta | `PastasSoAcompanhadasSemVer::ids` (`app/src/Tarefa/Service/PastasSoAcompanhadasSemVer.php:40`) | já vem dentro de `ListaDasMinhasMetasDaTela` |
| Funções de administrador | o bypass do Master e do primário no `PermissionChecker` e na `RegraDosPrimarios` | teto do conector (6.3): lista fechada de ferramentas, modo do conector sem os dois bypasses e sem `admin.*` para a pessoa do token, ramos de administrador da Agenda fechados pela ferramenta |
| Confirmação de exclusão | a tela pede (caixa de confirmação; excluir pasta exige digitar o número) | proposta e confirmação no chat, conferidas no servidor (6.7) |

Cada linha ganha um teste funcional do conector que prova a recusa (seção 12).

---

## 6. Superfície de ferramentas

**Escopo (decisão 14.b, 08/11/2026):** tudo o que o usuário comum faz nos módulos **Pastas, Agenda, Clientes e Processos**,
menos as funções de administrador, mais as partes de outros módulos que a decisão de 09/11/2026 pôs dentro (14.h). A base
é o inventário `bluejus-coordenacao/conector-inventario-tudo.md` (levantado no master `940e82dfd`): 187 ações do usuário
comum nos quatro módulos (51 de leitura, 136 de gravação), mais 13 de administrador (seção 5 dele) e 24 de outros módulos
que aparecem dentro da pasta (seção 1.13 dele).

**17 ferramentas, não 187.** Clientes de I.A. escolhem pior com dezenas de ferramentas (inventário, seção 6, item 9), então
as ações se agrupam pelo que o advogado pede, com um parâmetro `acao`. Duas regras dos clientes decidem o corte:

- **Leitura e gravação nunca na mesma ferramenta.** O diretório da Anthropic exige `title` e as anotações em toda
  ferramenta e a separação entre ler e gravar ("Read-only tools can run without per-call confirmation, and destructive tools
  always prompt", https://claude.com/docs/connectors/building/review-criteria.md); o ChatGPT trata como escrita, com
  confirmação, toda ferramenta sem `readOnlyHint` ("Tools without this hint are treated as write actions",
  https://developers.openai.com/api/docs/guides/custom-mcp-server.md). Uma ferramenta que lesse e gravasse pediria
  confirmação a cada leitura.
- **O que pede confirmação passa por uma proposta** (6.7). A ferramenta de gravação nunca exclui nem cadastra cliente
  direto: devolve uma proposta, e só a `confirmar_acao` executa.

Por isso são 7 de leitura, 9 de gravação e 1 de confirmação; o inventário sugeria cerca de 14 (as 3 a mais vêm da separação
entre ler e gravar).

### 6.1 O que fica fora no piloto

| Grupo | O que | Por quê |
|---|---|---|
| Administrador | as 13 ações da seção 5 do inventário: cadeado da pasta (`ConfigurarCadeadoDaPastaUseCase`, `RemoverCadeadoDaPastaUseCase`, `AlternarTrancaDaPastaUseCase`), pedidos de acesso (`DecidirPedidoDeAcessoUseCase` e o fluxo antigo `app_access_request_approve`/`_deny`), abas ocultas (`OcultarAbaParaTodosUseCase`, `DefinirQuemNaoVeAbaUseCase`, `MostrarAbaParaTodosUseCase`, `ListarEquipeParaOcultarAbaUseCase`), desfazer pela Auditoria (`DesfazerAlteracaoAuditLogUseCase`), Google Drive do escritório (`ConectarDriveUseCase`, `DefinirRootFolderDriveUseCase`, `DesconectarDriveUseCase`), datas da Agenda (`AcrescentarDataUseCase`, `RenomearDataUseCase`, `OcultarDataUseCase`, `RestaurarDatasDoSistemaUseCase`), feriados do escritório, mexer em compromisso de outra pessoa, agenda vista como administrador | decisão 14.b; o teto da 6.3 garante, mesmo para Master e primário |
| Manda algo ao cliente ou a terceiro | e-mails do cliente para avisos (`pasta_push_cliente_email_*`: `CadastrarEmailDoClienteUseCase`, cujo e-mail "nasce recebendo avisos", `TornarEmailDoClientePrincipalUseCase`, `DefinirAvisosDoEmailDoClienteUseCase`, `RemoverEmailDoClienteUseCase`); todo envio do Push ao cliente (`EnviarAnaliseAoClienteUseCase`, `DefinirEnvioAutomaticoDoClienteUseCase`, links públicos `GerarLinkExternoDoPushUseCase` e renovação); encaminhar pela Central (`EncaminharItemDaPastaUseCase`); gravar o e-mail de cliente que tem o envio automático ligado (`atualizar_cliente` recusa, 6.6) | decisão de 09/11/2026 (14.h) |
| Outros módulos | a BlueJus I.A. dentro da pasta (análises, comandos, conversa); o Push além de ler as publicações (marcar lida, monitoramento, buscar agora, "Avisar quando chegar", vincular publicação); Cobrança; Minhas Metas além de criar meta para a pasta e de ler a lista (`ler_agenda`, `minhas_metas`, 14.i): concluir, despachar e as demais ações de meta; Kanban, Ponto e o resto da seção 7 do inventário | decisão de 09/11/2026 (14.h, 14.i) e 14.b |
| Arquivo binário ou tela | enviar arquivo (documento da pasta, do Financeiro, do cliente, imagem do editor, trazer do Google Drive); foto com recorte (pasta e cliente); exportar peça, Word do modelo, resumo em PDF, .zip e CSV; conectar o Google Agenda, notificações do navegador, dispensar o aviso do Google; colunas da lista; registrar a visita da Timeline; importar pastas em lote do Drive | enviar arquivo exigiria o conteúdo em base64 dentro do argumento da ferramenta, com o teto de 4 MiB do corpo no SDK (`maxBodyBytes`, 3.1), e não há fonte oficial de que Claude e ChatGPT repassem um anexo da conversa assim (decisão 14.j: fica fora do piloto); exportar e baixar viram leitura de dados (`ler_pasta`, `buscar_pastas`, `ler_documentos_e_modelos`); o resto só existe no navegador |
| Tela antiga com versão nova que entra | agenda antiga (`agenda_lista`, `agenda_show`, `agenda_novo`, `agenda_editar`, `agenda_cancelar`, `agenda_excluir`); ficha antiga do cliente (`cliente_show`, `cliente_edit`); formulário antigo PF/PJ (`pasta_cliente_novo`); tela antiga de processo (`processo_new`, `processo_edit`); pedido de acesso antigo (`app_access_request_submit`) | regra no controller e a versão nova entra (Agenda nova, janela Cadastro, aba Processo, `pasta_acesso_pedir`); o inventário, seção 6, itens 3 e 4 |
| SQL livre | `consultar_sql` | não há como oferecer SQL livre e garantir isolamento entre escritórios; filtrar SQL arbitrário por texto é exatamente o que a v1 rejeitou por ser burlável. Nenhuma ferramenta aceita SQL, DQL ou nome de tabela |
| Áudio e vídeo | MP3, OGG, M4A, OPUS, MP4, MOV | decisão 14.f |
| Ponto eletrônico | tudo | risco ALTO por definição; fora dos 4 módulos |

### 6.2 Regras comuns a toda ferramenta

1. **Despacho único pelo portão** (4.1). Pessoa, escritório e vínculo vêm do token; o modelo nunca escolhe pessoa nem
   escritório. Todo id (pasta, processo, documento, modelo, meta, cliente, compromisso, publicação) é buscado por id **e**
   escritório; alheio, inexistente ou oculto dá a mesma resposta "não encontrado".
2. **Permissão**: módulo para lista e criação, recurso para item (5); as guardas de 5.2; o escopo (5.1); o teto (6.3); a
   liberação em três níveis (14.a).
3. **Segredo de justiça nunca sai, e pasta ou processo em segredo não existe para o conector** (regra 4 de
   `app/src/Inteligencia/CLAUDE.md`, a regra D4; `Processo::emSegredoDeJustica()`,
   `app/src/Processo/Entity/Processo.php:686-689`: `nivelSigilo > 0` do tribunal **ou** `segredoManual` marcado no cartão).
   Em lista, pasta, processo, meta, prazo, audiência, publicação, compromisso ou documento de identidade (`ler_clientes`)
   ligados a processo em segredo saem e só a **contagem** aparece
   (`ProcessoRepository::idsDasPastasEmSegredoDeJustica`, `app/src/Processo/Repository/ProcessoRepository.php:148`). Por id,
   leitura **e gravação** recusam com "em segredo de justiça: não sai pelo conector" (`MontadorDeContextoDaPasta::para` já
   lança `ContextoBloqueadoException`). A pessoa continua vendo e mexendo na tela. O conector nunca muda o campo de segredo
   manual do processo (6.5, `processos_da_pasta`). Mudar a regra é decisão do Samuel.
4. **Do cliente sai só o necessário** (regra 7 da Inteligencia, adaptada ao escopo novo):
   - em lista, pasta, processo, agenda e publicação, o cliente sai **só pelo nome** (e PF ou PJ);
   - `ler_clientes` (`acao: ler`) devolve nome, tipo, estado civil, profissão, nacionalidade, cidade e UF, e **quais**
     campos de `CamposDoCadastro::ROTULOS` (`app/src/Cliente/Service/CamposDoCadastro.php:29`) estão preenchidos e quais
     faltam (`PendenciasDoCadastro`), **sem o valor** de CPF ou CNPJ, RG, data de nascimento, e-mail, telefones e endereço;
     o mesmo vale para o réu e o representante legal no cadastro da pasta;
   - `conferir_documento` diz só se o CPF ou CNPJ informado já é de um cadastro do escritório, e de qual (nome e id);
   - duas exceções, já previstas antes: o **conteúdo do documento** lido por `ler_documentos_e_modelos` (com o aceite do
     conector, 14.a) e a peça **preenchida no servidor** (`documentos_e_pecas`, `acao: usar_modelo`), em que o dado vai para o
     documento da pasta e a resposta leva só id e título;
   - no cadastro e no completar, os valores que o próprio assistente mandou voltam no resumo da proposta (6.7) para a pessoa
     conferir; o servidor não acrescenta nenhum valor que o assistente não mandou.
5. **Financeiro** só se `VisibilidadeDoFinanceiroDaPasta::podeVer(User, Tenant, Pasta)`
   (`app/src/Inteligencia/Service/VisibilidadeDoFinanceiroDaPasta.php:37`) disser sim, para ler e para gravar.
6. **Texto livre do banco** passa pelo `ProtecaoDoTextoDoChat` (`app/src/ChatIa/Service/ProtecaoDoTextoDoChat.php`), que
   neutraliza (`NeutralizadorDeConteudo::neutralizar`) e mascara CPF, CNPJ, telefone e e-mail (`MascaradorDeDadosPessoais`,
   `app/src/Inteligencia/Service/MascaradorDeDadosPessoais.php:90`), e sai dentro de blocos com os nomes de
   `NeutralizadorDeConteudo::DELIMITADORES` (`app/src/Inteligencia/Service/NeutralizadorDeConteudo.php:28`).

   **No conector a máscara é sempre ligada**, sem seguir a preferência da BlueJus I.A.
   (`ConfiguracaoDeInteligencia::isMascararDadosPessoais`, `app/src/Inteligencia/Entity/ConfiguracaoDeInteligencia.php:192`).
   Motivo: o provedor é o da conta de consumo da pessoa, sem ZDR, e o aceite da BlueJus I.A. não cobre o conector (14.a);
   a preferência do escritório foi dada para o provedor que o BlueJus contrata. Hoje a preferência é lida em três lugares:
   `ProtecaoDoTextoDoChat::mascararSePreciso` (`ProtecaoDoTextoDoChat.php:56-61`, usado por `livre`, `comoDado` e `rotulo`),
   `MontadorDeContextoDaPasta::para` (`app/src/Inteligencia/Contexto/MontadorDeContextoDaPasta.php:117`, que passa o
   `$mascarar` a todas as seções) e `MontadorDeContextoDoPush::mascararNoTenant`
   (`app/src/Inteligencia/Contexto/MontadorDeContextoDoPush.php:258-261`, que o montador da pasta usa nas movimentações).
   Os três passam a perguntar a um serviço **novo** de uma pergunta só (nome sugerido `PoliticaDeMascaramento`, em
   `app/src/Inteligencia/Service/`, com `ResetInterface`): responde "mascara" quando o portão do conector o ligou na
   requisição e, fora dela, devolve a preferência do escritório como hoje (sem configuração, mascara). As assinaturas
   públicas (`livre`, `comoDado`, `rotulo`, `para`) não mudam, e o Chat I.A, a BlueJus I.A. e o worker continuam iguais;
   um teste prova que a requisição seguinte do mesmo processo volta a seguir a preferência. Assim:
   - texto de **várias linhas** (anotação, observação, descrição de meta, texto de movimentação, teor de publicação):
     `ProtecaoDoTextoDoChat::comoDado` (`ProtecaoDoTextoDoChat.php:38`), que neutraliza linha a linha e mantém as quebras;
   - valor **de uma linha** (nome de arquivo, nome da pessoa, título de meta): `ProtecaoDoTextoDoChat::rotulo` (`ProtecaoDoTextoDoChat.php:49`), com valor padrão quando
     fica vazio;
   - **texto extraído de documento** (6.5): só neutralizado, linha a linha, direto pelo `NeutralizadorDeConteudo::neutralizar`
     (nunca pelo `comoDado`, que no conector mascara), **sem a máscara**, porque o conteúdo do documento é a exceção da
     regra 4 coberta pelo aceite do conector, e o PDF e a imagem já vão inteiros. Sem isso, o assistente não conseguiria
     cadastrar o cliente a partir de um DOCX (pedido C, 14.b).
7. **Resposta**: JSON estruturado (`structuredContent`) mais um texto curto. Cabe no menor cliente: até 100 mil caracteres por
   resultado (o Claude aceita cerca de 150 mil; o Claude Code, 25 mil tokens, o que o guia do usuário avisa), com paginação
   (`pagina`, `proxima_pagina`). Tempo-alvo abaixo de 10 s (o Claude espera 240 s).
8. **Anotações MCP** (`ToolAnnotations` do SDK, `app/vendor/mcp/sdk/src/Schema/ToolAnnotations.php:38-42`), todas com
   valor explícito, porque o padrão da especificação para quem não declara é `destructiveHint: true` e `openWorldHint: true`:
   - leitura: `readOnlyHint: true`, `destructiveHint: false`, `openWorldHint: false` (salvo `ler_processos`, que consulta o
     CNJ: `openWorldHint: true`);
   - gravação: `readOnlyHint: false`, `destructiveHint: true` (todas as 9 mudam ou sobrescrevem algo que já existe; pela
     especificação, `false` quer dizer "só acrescenta"), `idempotentHint: false`, `openWorldHint: false` (salvo
     `gravar_na_agenda`, que sincroniza com o Google: `true`);
   - `confirmar_acao`: como a gravação, mais `_meta["anthropic/requiresUserInteraction"]: true` (6.7);
   - `title` em português em toda ferramenta (exigência do diretório da Anthropic).
   As anotações são **dicas**: a especificação diz que o cliente não deve decidir por elas quando o servidor não é confiável
   (https://modelcontextprotocol.io/specification/2025-06-18/schema#toolannotations). Nenhuma regra de segurança depende delas
   (6.7).
9. **Log** no canal `mcp`, que já existe (`app/config/packages/monolog.yaml`, arquivo `mcp.log`): pessoa, escritório, cliente
   OAuth, ferramenta, ação, ids, contagens, duração e resultado. **Nunca** texto de busca, título, nome, valor de campo ou
   conteúdo (regra 3 da Inteligencia).
10. **Entrada fechada**: JSON Schema plano, com `acao` em lista fechada e os campos opcionais de cada ação, e
    `additionalProperties: false`; o servidor valida por ação e recusa campo que a ação não usa. Sem `oneOf`, para não
    depender de como cada cliente o trata. Valor fora da lista vira erro de ferramenta com mensagem fixa em português, nunca
    "todas as pastas" em silêncio.
11. **Aba oculta e chave do Master, ação por ação** (tabela da 6.6). A tela barra pela rota; o conector não tem rota de tela,
    então cada ação confere a aba e a chave da tela de onde a informação vem. Aba oculta responde "não encontrado" quando o
    pedido é por id na aba inteira; a chave desligada responde "função desligada pelo administrador do sistema".
12. **Lote**: no máximo 50 itens por chamada (mover, copiar, restaurar, excluir documentos; aplicar modelo de checklist;
    cadastrar clientes). Mais que isso é erro com mensagem fixa (inventário, seção 6, item 6).
13. **Quem fez**: a gravação sai com a pessoa como autora, como na tela (os UseCases não mudam). O `audit_log` já grava o nome
    da rota da requisição (`AuditLogSubscriber.php:343` e `:429`): com a rota do `/mcp` com nome próprio (sugestão
    `conector_mcp`), toda alteração auditada fica marcada como feita pelo conector, sem coluna nova; a ferramenta e o cliente
    OAuth vão para o log `mcp`. Selo "pelo conector" no Registro da pasta fica fora do piloto (exigiria coluna nova em
    `pasta_mensagem` e mudança de tela sem desenho).
14. **Efeitos internos continuam**: avisos do sino a colegas (menção, responsável, quem acompanha, participante), Registro
    da pasta e Histórico do cadastro saem como na tela. São da equipe do próprio escritório, não do cliente.

### 6.3 Teto próprio do conector

O conector age como a pessoa, e o Master (`ROLE_SUPER_ADMIN`) e o primário do escritório (perfil de sistema,
`TenantRole::isSystem()`) passam por toda checagem (`docs/AUTORIZACAO.md` seção 2; `PermissionChecker`, primeiro passo de
cada método público, ex. `canAccessResourceComVinculo`, `app/src/Service/PermissionChecker.php:103-118`). O inventário põe
esse passe entre as funções de administrador ("Ver e mexer em qualquer pasta, cliente ou processo sem acesso item a item",
última linha da seção 5) e avisa que, sem teto próprio, "exceto funções de administrador" não se cumpre (seção 6, item 1);
no piloto isso pesa já, porque o Farlei é Master. Pela decisão 14.b, o teto vale **mesmo para Master e primário**, em
quatro partes:

1. **Lista fechada.** Só existem as 17 ferramentas desta seção, e nenhuma ação delas chama um UseCase da lista de
   administrador da 6.1. Teste de arquitetura: nenhuma classe do conector depende desses UseCases, e ferramenta nova sem
   linha na tabela da 6.6 derruba o teste.
2. **Modo do conector: sem os bypasses, para a pessoa do token.** Um serviço por requisição (nome sugerido `ModoDoConector`,
   com `ResetInterface`), ligado pelo portão com a pessoa e o vínculo do token, faz responder falso, **só quando a pergunta é
   sobre essa pessoa**:
   - `PermissionChecker::isGlobalSuperAdmin` e o passe do perfil de sistema (`TenantRole::isSystem()`) em todos os métodos
     públicos do `PermissionChecker`;
   - `PermissionChecker::canAdminister`, `hasPermission` com permissão `admin.*` e `isAdministradorDoEscritorio` (usado,
     por exemplo, por `PastasVisiveisNoRelatorio` e `LeituraDasPastasDosAvisos` para mostrar tudo ao administrador);
   - `RegraDosPrimarios::ehMaster` (`app/src/Tenant/Service/RegraDosPrimarios.php:53`) e `ehPrimario` (`:157`), que o cadeado
     (`NivelNoCadeado`, `:91`, `:109`, `:304`) e as abas ocultas (`VisibilidadeDasAbasDaPasta::podeConfigurar`, que passa por
     `RegraDosPrimarios::podeGerirPrimarios`: quem configura não tem aba oculta) usam para deixar passar.

   "Só sobre essa pessoa" importa: `ehPrimario` também escolhe **quem recebe** aviso (ex.:
   `EnviarRelatorioSemProcessoUseCase.php:179`, `EnviarRelatorioPastasParadasUseCase.php:156`), e esses colegas continuam
   recebendo. Fora do `/mcp` nada muda; o modo zera no `reset()` e um teste prova que a requisição seguinte do mesmo processo,
   no navegador, tem os bypasses de volta.
3. **A pessoa conta pelo perfil do vínculo e pelas regras de item**, como qualquer colega: módulo e recurso pelo perfil;
   cadeado (pasta trancada só para quem está na lista dela) e abas ocultas como para os outros. O perfil de sistema recebe
   todas as permissões ao nascer o escritório (`TenantBootstrapService::attachAllPermissions`), então o primário continua
   vendo e mexendo nas pastas, clientes e processos sem cadeado; o que perde é **toda pasta trancada** e toda função
   `admin.*`. Master e primário nunca estão na lista de uma pasta trancada: a grade do cadeado os exclui
   (`EquipeDoCadeado::doEscritorio`, `app/src/Pasta/Service/EquipeDoCadeado.php:28-33`, porque quem configura nunca é
   restringido). Sem o passe, `CadeadoDaPasta::nivelDe` (`app/src/Pasta/Entity/CadeadoDaPasta.php:130`) devolve `Oculto`
   para eles em toda pasta trancada, no modo "Ocultar para todos" e no modo por pessoa. Não há caminho pelo conector para
   abrir uma delas: `pedir_acesso` não resolve, porque um pedido aprovado nunca fura o cadeado
   (`app/src/Service/PermissionChecker.php:226-228`). Isso é aceito por ser o lado seguro; a pasta continua na tela.
   Como o bypass escondia a falta, permissão criada depois do escritório pode não estar ligada ao
   perfil de sistema: o PR 1 confere isso na produção (só `SELECT`, pelo MCP `jusprime-prod`) e, se faltar, o modo trata o
   perfil de sistema como tendo todas as `modules.*` e `resources.*` do catálogo (`PermissionFixture`), nunca as `admin.*`.
   O Master conta pelo perfil do vínculo dele naquele escritório (a decisão 1 já exige o vínculo ativo); existem Masters
   secundários com vínculo comum (`docs/AUTORIZACAO.md`, 2a).
4. **Ramos de administrador dentro de UseCase reaproveitado ficam fechados pela ferramenta**, para quem tem `admin.*` num
   perfil comum: `ListarCompromissosUseCase` sempre com `administra = false` (`:47`); editar, mover, mudar status e excluir
   compromisso só de quem o criou, conferido pela ferramenta antes do UseCase, porque `SalvarCompromissoUseCase` (`:70`),
   `MoverCompromissoUseCase` (`:49`), `AlterarStatusDoCompromissoUseCase` (`:41`) e `ExcluirCompromissoUseCase` (`:31`)
   deixam passar quem tem `admin.users.manage`. Nos UseCases e serviços de Pasta, Cliente, Processo, Agenda, Expediente,
   Tarefa e Inteligencia, o grep de `canAdminister`, `isAdministradorDoEscritorio`, `ehPrimario`, `ehMaster`,
   `isGlobalSuperAdmin` e `admin.` (09/11/2026) só acha estas checagens, as do item 2 e as de funções que ficam fora
   (cadeado, abas ocultas, envio ao cliente); o PR que trouxer um UseCase novo para o conector repete o grep.

O escopo continua valendo junto (5.1): `mcp:escrita` desmarcado por padrão, e escopo nunca amplia permissão.

**Consequência a dizer ao piloto:** o Farlei, que é Master, vê pelo conector o que o perfil dele no escritório vê, e
**nenhuma pasta trancada aparece** para ele pelo conector, embora apareça na tela; o mesmo vale para o primário. Para mexer
numa pasta trancada, só pela tela. O guia do usuário (PR 15) diz isso.

Testes em 12.19.

### 6.4 Leitura (7 ferramentas)

Todas: `readOnlyHint: true`, escopo `mcp:leitura`, sem confirmação.

**`quem_sou_eu`** (PR 5)
- Saída: nome da pessoa, escritório, escopos e validade da conexão, as ferramentas e ações que ela pode usar (pelos módulos que
  tem) e se o cliente de I.A. declarou `elicitation` (para o assistente saber como a confirmação vai acontecer, 6.7).
- Reusa: o portão e `PermissionChecker::canAccessModule` (`:32`) para cada módulo do catálogo
  (`app/src/DataFixtures/PermissionFixture.php:17-32`).

**`buscar_pastas`** (PR 6)
- Faz: a lista do Expediente com busca livre e filtros, Minhas demandas (`minhas: true`), busca rápida por número ou NUP, as
  pastas de um marcador, a contagem, os valores de filtro (`opcoes`: marcadores, situações, responsáveis) e a lixeira do
  escritório (`lixeira: true`); depois do PR 14, os relatórios de pastas paradas e de pastas sem processo.
- Reusa: a API do Agente de Pastas, que já roda com tenant explícito "porque o conector MCP da Fase 4 roda sem sessão"
  (`app/src/Inteligencia/CLAUDE.md`, seção Agente de Pastas; `AgenteDePastasController`, rotas
  `inteligencia_agente_pastas_buscar`, `inteligencia_agente_pastas_documentos` e `inteligencia_agente_opcoes`):
  `BuscarPastasDoAgenteUseCase::executar(FiltroDoAgenteInput, Tenant, ?User $quem)` (`:40`), `ContarPastasDoAgenteUseCase`,
  `ListarOpcoesDoAgenteUseCase`, sobre `ConsultaDePastasDoAgente` (`:42`, `:59`), a mesma consulta da Lista. Mais
  `ListarMinhasDemandasUseCase::executar` (`app/src/Pasta/UseCase/ListarMinhasDemandasUseCase.php:35`),
  `BuscarRapidoUseCase::executar(string, User, Tenant)` (`app/src/Expediente/UseCase/BuscarRapidoUseCase.php:49`) e
  `ListarLixeiraDoEscritorioUseCase::executar(User, Tenant, ?string)` (`app/src/Pasta/UseCase/ListarLixeiraDoEscritorioUseCase.php:71`).
  **Passar sempre `$quem`**: sem ele o cadeado não entra. Filtro inválido é erro (`FiltroDoAgenteInvalidoException`), nunca
  lista inteira.
- Falta: tirar e contar o segredo de justiça (a busca do Agente hoje não tira); conferir no PR se `ListarMinhasDemandasUseCase`
  aplica o cadeado (senão, `AcessoAPasta::idsOcultosPara`); relatórios do Expediente com UseCase extraído (regra hoje no
  controller, PR 14).
- Permissão: módulo `expediente` (como o `/api/agente`, `AgenteDePastasController.php:99`); Minhas demandas, módulo `pastas`
  (`app/src/Pasta/Controller/DemandasController.php:240`).

**`ler_pasta`** (PR 6; publicações no PR 7)
- Entrada: `pasta_id`, `partes` (padrão `resumo`), `publicacao_id?`, `pagina?`.
- Partes:
  - `resumo`: número, ação, situação, prioridade, responsável, carteira, marcadores, clientes só pelo nome, processos
    vinculados;
  - `andamento` (pedido A): a via (judicial ou administrativa), a trilha de fases com nome e número
    (`AndamentoDaPasta::FASES` e `FASES_ADM`, `app/src/Pasta/Service/AndamentoDaPasta.php:36`, `:42`), a fase atual, as datas,
    as puladas, se é estimada ou gravada, os nomes de etapa do "+" e as sugestões de próxima fase;
  - `registro` (mensagens do Registro, paginado), `prazos` (prazos e audiências), `metas` (metas da pasta), `detalhes`
    (Relatório inicial), `checklist`, `financeiro`, `cadastro` (cadastro da pasta e réus, pela regra 4 da 6.2);
  - `publicacoes` (14.h): as publicações do Diário ligadas à pasta; com `publicacao_id`, o texto inteiro;
  - `equipe`: os colegas que podem ser mencionados no Registro (`ListarMencionaveisDaPastaUseCase::executar`,
    `app/src/Pasta/UseCase/ListarMencionaveisDaPastaUseCase.php:58`), só nome e id;
  - `timeline` e `controladoria` (alertas e pontos do relatório inicial): depois do PR 14, com UseCase extraído (regra hoje no
    controller).
- Reusa: `PastaDoContextoDaIa::liberar(User, Tenant, int)` (`app/src/Central/Service/PastaDoContextoDaIa.php:52`: id e
  escritório, pasta excluída, `podeVer`, decide financeiro e abas, confere sigilo) e `MontadorDeContextoDaPasta::para(...)`
  (`app/src/Inteligencia/Contexto/MontadorDeContextoDaPasta.php:108`), como o `ContextoDaPastaNoChat::ler`
  (`app/src/ChatIa/Service/ContextoDaPastaNoChat.php:45`) já junta, com o orçamento de 60 mil caracteres (`MontadorDeContextoDaPasta::ORCAMENTO_TOTAL`, `:74`). A primeira
  versão devolve esses blocos como texto já neutralizado; JSON por parte fica para quando um cliente precisar.
- Falta:
  - **um UseCase de leitura do andamento** (nome sugerido `LerAndamentoDaPastaUseCase`), que junte `AndamentoDaPasta::estado`
    (`:100`), `rotulos` (`:115`) e `sugestoes` (`:255`), os mesmos dados que a tela monta em
    `AndamentoDaPastaOutput::de` (`app/src/Pasta/DTO/AndamentoDaPastaOutput.php:35`), com nome e número de cada fase. Não
    existe hoje rota só de leitura do andamento (inventário 0.A);
  - **publicações sem marcar "lida"**: abrir o teor na tela (`pasta_push_teor`) grava `lida` para o escritório todo
    (`app/src/Pasta/Controller/PastaPushProcessualController.php:88`). O conector **lê sem marcar**, pelo caminho do
    `pasta_push_texto` (`:146`), que "NÃO há efeito colateral nenhum, nem `lida`, nem flush":
    `PublicacaoDjenRepository::findOneByIdENumerosDoTenant` com os números dos processos da pasta e
    `PublicacaoDjenListaItem::resumirTexto`. A lista sai de `MontarPainelDoPushUseCase::executar(Pasta, Tenant)` (conferir no PR
    que não grava). A guarda é a do `texto`, num UseCase sem sessão: escritório da pasta, VER a pasta, publicação casada com
    um processo desta pasta. O teor passa por `comoDado` (6.2.6).
- Permissão: recurso `pasta` view (`AcessoAPasta::podeVer`); cada parte segue a aba de onde vem (6.6), como o
  `PastaDoContextoDaIa` já passa as abas ocultas ao montador (`:60-63`); chave `expedientes`.
- Regras: segredo recusa; financeiro conforme 6.2.5; o contexto da pasta pelo chat exige o aceite da BlueJus I.A.
  (`ContextoDaPastaNoChat.php:47`), e no conector vale o aceite do conector (14.a).

**`ler_documentos_e_modelos`** (PR 7; outros tipos nos PRs 7b e 7c)
- Ações: `listar` (documentos e subpastas da pasta, sem conteúdo: id, nome, categoria, subpasta, data, tamanho, tipo),
  `ler` (`documento_id`: o conteúdo, pela tabela da 6.5), `lixeira` (o que está na lixeira da pasta,
  `ListarLixeiraDaPastaUseCase`), `modelos` (modelos de peça do escritório) e `modelo` (corpo de um modelo, **sem preencher**).
- Reusa:
  - lista: `ListarDocumentosDaPastaDoAgenteUseCase::executar(int, User, Tenant)`
    (`app/src/Inteligencia/UseCase/ListarDocumentosDaPastaDoAgenteUseCase.php:41`), que tira a lixeira e o contrato quando o
    Financeiro está oculto (`:53-58`);
  - conteúdo: `LeitorDeDocumentosDaPasta::anexos(Tenant, Pasta, ids, abasOcultas, ?User)`
    (`app/src/Inteligencia/Contexto/LeitorDeDocumentosDaPasta.php:73`: recurso de quem pede, abas gravadas e de agora com a
    aba Documentos barrada (`:119-121`), escritório e pasta, lixeira, tipo pelo conteúdo, teto e páginas) e os limites de
    `RegraDaLeituraDeDocumentos` (`app/src/Inteligencia/Service/RegraDaLeituraDeDocumentos.php:32-40`);
  - modelos: `ListarModelosDePecaUseCase::executar(Tenant, User, FiltroDosModelosInput)`
    (`app/src/Pasta/UseCase/ListarModelosDePecaUseCase.php:41`) e `ModeloDePeca` (`app/src/Pasta/Entity/ModeloDePeca.php`, com
    escritório) lido por id e escritório, com as variáveis (`app/src/Pasta/Service/PreenchedorDeModelo.php:19`) sem
    preencher.
- Falta:
  - a **barreira da aba Documentos inteira** na lista: o UseCase confia na rota para isso (comentário em `:53-54`), e quem
    barra é o `BarreiraDeAbaOcultaListener` pela rota `inteligencia_agente_pastas_documentos` (`MAPA`, `:68`), que o conector
    não atravessa. A ferramenta confere `VisibilidadeDasAbasDaPasta::abasOcultasPara` antes e, com Documentos oculta,
    responde "não encontrado";
  - o segredo de justiça (o UseCase do Agente não confere);
  - a **porta do aceite** no leitor: hoje ele exige o aceite da BlueJus I.A. (`RegraDaLeituraDeDocumentos::aceiteCobre`,
    `:51`, que lê o aceite em `ConfiguracaoDeInteligencia`). Vira uma porta, com a implementação de hoje para a I.A. (sem
    mudar nada nela) e a do conector, que lê o aceite do conector (14.a);
  - os tipos novos (6.5).
- Armazenamento (skill `armazenamento-de-arquivos`): leitura sempre pela porta `ArmazenamentoDeArquivos::ler`
  (`app/src/Shared/Armazenamento/ArmazenamentoDeArquivos.php:76`), com a chave de `ChavesDePasta::documento`
  (`app/src/Pasta/Armazenamento/ChavesDePasta.php:62`), nunca caminho. A autorização vem antes de montar a chave.
- Regras: segredo recusa; o log guarda só id e sha256, como o `contexto_resumo.documentosLidos` da I.A.; imagem embutida na
  peça não sai (é servida hoje sem isolamento por escritório, `docs/specs/peca-imagem-isolamento-tenant.md`); conteúdo de
  documento que está na lixeira não sai (o leitor tira a lixeira de propósito; restaurar entra em `documentos_e_pecas`).
- Permissão: recurso `pasta` view; aba Documentos (e Detalhes para `modelos` e `modelo`, como a tela `pasta_modelos_*`,
  `MAPA` `:71`); chave `expedientes`. A chave `peticionar` não vale aqui: cobre só `pasta_peticionar*`.

**`ler_processos`** (PR 7; CNJ no PR 13)
- Ações: `buscar` (no escritório, por número CNJ ou termo), `para_vincular` (processos já cadastrados que podem ir para uma
  pasta), `cnj` (consulta ao DataJud pelo número) e `detalhar` (`processo_id`: dados, últimas movimentações paginadas, notas
  técnicas, números das pastas vinculadas visíveis).
- Reusa: `ProcessoRepository::findByNumeroProcessoDoTenant(string, Tenant)` (`:212`), `buscarPorTermoDoTenant` (`:255`),
  `findOneByIdDoTenant` (`:53`), `PastaRepository::listarVinculadasAoProcesso(Processo, Tenant, ?User)`
  (`app/src/Pasta/Repository/PastaRepository.php:495`), `BuscarProcessosParaVincularUseCase::executar(Pasta, string, Tenant,
  User)` (`app/src/Pasta/UseCase/BuscarProcessosParaVincularUseCase.php:52`).
- Falta: um UseCase de leitura sem sessão (a regra está no `ProcessoController`); um método paginado de movimentações recentes
  no `MovimentacaoProcessoRepository` (hoje só `ultimasPorProcesso`, que devolve datas, `:46`); a consulta ao CNJ com UseCase
  extraído do controller (`api_datajud_search` e `pasta_processo_buscar_cnj`, 116 linhas no controller, inventário seção 4),
  que manda ao CNJ só o número do processo (consulta pública; é o que a tela já faz).
- **`cnj` e o segredo:** antes de consultar o DataJud, a ferramenta procura o número no escritório
  (`ProcessoRepository::findByNumeroProcessoDoTenant`, `:212`). Se casar com processo em segredo
  (`Processo::emSegredoDeJustica()`, `app/src/Processo/Entity/Processo.php:686-689`: do tribunal ou manual), recusa com a
  mensagem da 6.2.3 e **não chama o CNJ**; sem isso, a consulta pública devolveria ao assistente os dados e as
  movimentações de um processo que o conector esconde em toda outra ação.
- Permissão: módulo `processos` (`app/src/Processo/Controller/ProcessoController.php:85`); por id,
  `idsDosProcessosOcultos` e recurso `processo` view, na ordem de `ProcessoController.php:222-225`; `para_vincular` e `cnj`,
  recurso `pasta` view e aba Processo, como a tela.
- Regras: segredo omitido e contado na lista, recusa por id e no `cnj`; partes só pelo nome. `openWorldHint: true` (CNJ).

**`ler_clientes`** (PR 11; documentos do cliente no PR 14)
- Ações: `buscar` (por nome), `ler` (`cliente_id`), `conferir_documento` (CPF ou CNPJ), `documentos_de_identidade` (os
  documentos de identidade achados nas pastas do cliente, por metadado; o conteúdo sai pelo `ler_documentos_e_modelos`) e
  `documentos_do_cliente` (lista).
- Reusa: `MontarInteligenciaCadastralUseCase::executar(Cliente, User, Tenant)`
  (`app/src/Cliente/UseCase/MontarInteligenciaCadastralUseCase.php:46`); `CamposDoCadastro` e `PendenciasDoCadastro`
  (`app/src/Cliente/Service/`) para preenchidos e pendências; a guarda do `ClienteResumoController` (recurso `cliente` view,
  `:73`; cada pasta da lista por `canAccessResource('pasta', view)`, `:139`).
- Falta: UseCases de leitura sem sessão, porque hoje a regra está no controller: buscar (`pasta_clientes_buscar`), ler
  (`cliente_cadastro`, `cliente_resumo`), conferir documento (`cliente_cadastro_documento`, `pasta_cadastro_novo_documento`,
  `cliente_cadastro_novo_documento`) e documentos do cliente (`cliente_documento_view`).
- Regras: a regra 4 da 6.2 (sem valores de documento, contato e endereço); pastas do cliente em segredo saem e são contadas.
- **`documentos_de_identidade` e as pastas:** o `MontarInteligenciaCadastralUseCase` monta a lista de pastas em
  `pastasVisiveis` (`:67-73`) e só filtra `canAccessResource` view (`:72`); não tira segredo nem aba oculta. A ferramenta tira também as pastas ligadas a processo
  em segredo (`ProcessoRepository::idsDasPastasEmSegredoDeJustica`, `:148`; contadas) e as pastas em que a aba Documentos
  está oculta para a pessoa (`VisibilidadeDasAbasDaPasta::abasOcultasPara`, `:115`), antes de buscar os documentos. Como o
  filtro entra (parâmetro novo no UseCase ou recorte na ferramenta) o PR decide, sem mudar a tela do cliente.
- Permissão: recurso `cliente` view; buscar e conferir documento, módulo `clientes`; `documentos_de_identidade`, também a
  aba Documentos de cada pasta (6.6, tabela).

**`ler_agenda`** (PR 6)
- Ações: `agenda` (padrão) e `minhas_metas` (14.i).
- Entrada de `agenda`: `de`, `ate` (até 31 dias), `so_minha?` (padrão `true`), `incluir?` (compromissos, prazos, datas do
  sistema, lembretes).
- Reusa: `ListarCompromissosUseCase::executar(Tenant, User, de, ate, soMinha, verPastas, verPrazos, administra)`
  (`app/src/Agenda/UseCase/ListarCompromissosUseCase.php:47`), com `verPrazos` = `resources.agenda.ver_prazos`, `verPastas` =
  módulo `pastas` (`app/src/Agenda/Service/PermissoesDaAgenda.php:19-30`) e **`administra` sempre falso** (6.3);
  `ListarDatasDoSistemaUseCase` e `ListarLembretesUseCase`.
- Permissão: módulo `agenda` (`app/src/Agenda/Controller/AgendaNovaController.php:58`).
- Regras: os aniversários que a agenda mostra são **da equipe**, não de clientes (`ListarCompromissosUseCase.php:25`, pelo
  `AniversariosDaEquipe`): ficam fora, porque data de nascimento de colega é dado pessoal e não ajuda no trabalho. Os
  **prazos e audiências** das pastas (`ListarCompromissosUseCase.php:23-25`, `dosPrazos`, `:150`) já saem sem as pastas
  ocultas pelo cadeado (`AcessoAPasta::idsOcultosPara`, `:52`), mas **não sem o segredo**: a ferramenta tira os prazos e
  audiências de pastas ligadas a processo em segredo (`ProcessoRepository::idsDasPastasEmSegredoDeJustica`) e os conta. O
  mesmo para compromisso ligado a pasta oculta ou em segredo; conferir no PR se `dosEventos` (`:61`) aplica o cadeado ao
  compromisso ligado a pasta.
- **`minhas_metas`** (14.i, só leitura): `periodo` (`hoje`, `proximos_7_dias` ou `atrasadas`, de `PeriodoDaConsulta`,
  `app/src/ChatIa/Enum/PeriodoDaConsulta.php`) e `papel?` (`responsavel`, padrão, `criei`, `acompanhando` ou `todas`, de
  `AbaMetas`, `app/src/Tarefa/Enum/AbaMetas.php`). Reusa `ConsultarPrazosEMetasUseCase::executar(User, Tenant,
  ConsultaDePrazosInput)` (`app/src/ChatIa/UseCase/ConsultarPrazosEMetasUseCase.php:53`), a mesma consulta da tela Minhas
  Metas, que já tira a meta de outro escritório e a de pasta em segredo (contada) e mostra até 20, com o total. Falta: a
  guarda dele, `GuardaDaConsultaDePrazos::podeConsultar` (`app/src/ChatIa/Service/GuardaDaConsultaDePrazos.php:37-40`),
  exige o aceite da BlueJus I.A. (`aceiteDeEnvioDado`); no conector vale o aceite do conector (14.a), então a guarda vira
  porta, como a do leitor de documentos (`ler_documentos_e_modelos`): a do chat sem mudar e a do conector, que confere o
  módulo `tarefas` (`AcessoAoModuloTarefas::pode`) e a liberação do conector. Títulos e descrições saem pela 6.2.6.
  Concluir, despachar e as demais ações de meta continuam fora (6.1). Permissão: módulo `tarefas`; chave `metas` (a da
  tela `tarefa_minhas`).

### 6.5 Que documento sai e como (decisão 14.f)

Todos os tipos que a aba Documentos aceita, menos áudio e vídeo. A lista de tipos é a de `UploadPecaUseCase::MIME_LIMITS`
(`app/src/Pasta/UseCase/UploadPecaUseCase.php:77-111`), conferida pelo conteúdo do arquivo; a peça escrita no editor é
gravada como `text/html` (`SalvarPecaTextoUseCase`, `MIME_DA_PECA`, `app/src/Pasta/UseCase/SalvarPecaTextoUseCase.php:48`).

| Tipo | Como sai | Caminho | PR |
|---|---|---|---|
| PDF (até 10 MB, 100 páginas) | o arquivo, como recurso embutido, marcado "experimental" | `LeitorDeDocumentosDaPasta::anexos`, como hoje | 7 |
| Imagem PNG, JPEG, GIF, WEBP (até 3 MB) | a imagem, como conteúdo de imagem do MCP | o mesmo leitor (`RegraDaLeituraDeDocumentos::tipoPeloConteudo`, `:116-126`) | 7 |
| Imagem TIFF, HEIC, HEIF | convertida em JPEG no servidor e entregue como imagem | o leitor de hoje não reconhece esses tipos (`tipoPeloConteudo` só aceita PNG, JPEG, GIF, WEBP e PDF) e a extensão GD da imagem PHP não lê TIFF nem HEIC: precisa de dependência nova na imagem Docker (ex.: `ext-imagick` com suporte a HEIC), arquivo quente | 7c |
| Peça do editor (`text/html`) | texto, sem as imagens embutidas | método novo no leitor (nome sugerido `textos`) que reusa `conferirPedido` (`:109`) e as conferências do `anexo` (`:130`) | 7b |
| DOCX | texto | `ExtratorDeTextoDocx::extrair` (`app/src/ChatIa/Service/ExtratorDeTextoDocx.php:35`, hoje só do chat), levado a um lugar comum aos dois domínios | 7b |
| DOC, ODT, RTF | texto | leitores `MsDoc`, `ODText` e `RTF` do `phpoffice/phpword`, que já está instalado (`app/composer.json:35`) | 7b |
| TXT, CSV, XML, KML | texto, com a codificação conferida; XML e KML lidos como texto, nunca interpretados (sem risco de entidade externa) | leitura direta | 7b |
| XLSX, XLS, ODS | tabela em texto, com o teto de linhas e colunas da prévia (`PreviaDeDocumentoOffice::LINHAS` 500 e `COLUNAS` 40, `app/src/Pasta/Service/PreviaDeDocumentoOffice.php:25-26`) | leitores `Xlsx`, `Xls` e `Ods` do `phpoffice/phpspreadsheet`, já instalado (`app/composer.json:34`), com a mesma proteção de pacote da prévia (`pacoteSeguro`, `:37`) | 7c |
| PPTX, ODP | o texto dos slides | XML de dentro do pacote pelo `ZipArchive` (`ext-zip` já está no `composer.json`), com a mesma proteção de pacote; não há biblioteca de apresentação instalada | 7c |
| E-mail (.eml) | remetente, destinatários, assunto, data e o corpo em texto; anexos só pelo nome | leitor pequeno no servidor ou dependência nova, a decidir no PR | 7c |
| ZIP | a lista do que tem dentro (nome e tamanho), sem abrir os arquivos | `ZipArchive`, com a proteção de pacote | 7c |
| Assinatura `.p7s` | só a informação de que é um arquivo de assinatura digital, sem conteúdo | o arquivo é a assinatura, não um texto a ler | 7c |
| Áudio (MP3, OGG, M4A, OPUS) e vídeo (MP4, MOV) | recusa com mensagem fixa | decisão 14.f | n/a |

Regras de todos: as mesmas guardas do leitor (recurso, abas, escritório, pasta, lixeira, aceite); teto de 60 mil caracteres
de texto por documento, com aviso de corte; texto pela regra 6.2.6 (neutralizado, sem máscara); o MCP manda imagem como
conteúdo padrão e PDF só como recurso embutido, e não há fonte oficial de que Claude e ChatGPT leem PDF devolvido por
ferramenta: o piloto confere, e se não lerem, extrair o texto do PDF no servidor vira pergunta nova (sem OCR, PDF escaneado
continua sem texto).

### 6.6 Gravação (9 ferramentas)

Todas: escopo `mcp:escrita` (sem ele, 403 `insufficient_scope`, 5.1); `readOnlyHint: false`, `destructiveHint: true`;
pasta excluída é só leitura (`Pasta::estaExcluida()`, `app/src/Pasta/Entity/Pasta.php:450`), salvo restaurar; segredo recusa
(6.2.3); toda **exclusão** devolve uma proposta e só a `confirmar_acao` executa (6.7). "Exclusão" é toda ação que apaga, manda
para a lixeira, exclui de vez, remove ou desvincula um item, inclusive tirar um marcador da pasta, apagar o nome de uma etapa
e trocar um valor já preenchido no cadastro do cliente. **Gravar por cima também conta como exclusão**, porque o texto
anterior se perde: `editar_peca` (o `EditarPecaTextoUseCase` regrava o HTML na mesma chave, sem versão,
`app/src/Pasta/UseCase/EditarPecaTextoUseCase.php:19`, `:84-91`), `registrar_na_pasta` com `editar` (anotação do Registro e
dos relatórios inicial e financeiro) e `atualizar_pasta` com `marcadores_da_pasta` quando a lista tira um marcador que a
pasta tem. Lote (vários documentos, vários marcadores numa chamada) é uma proposta e uma confirmação (6.7, item 5).

**`atualizar_pasta`** (PR 10)
- Ações e UseCases: `criar` (`CriarPastaUseCase`), `editar_dados` (`EditarPastaUseCase`), `situacao`
  (`DefinirSituacaoDaPastaUseCase`; arquivar leva o andamento a Finalizada, `AndamentoDaPasta::finalizarPorArquivamento`,
  `:192`), `responsavel` (`AlterarResponsavelDaPastaUseCase`; só colega ativo do escritório), `prioridade`
  (`AlterarPrioridadeUseCase`), `carteira` (`MoverPastaDeCarteiraUseCase`), `duplicar` (`DuplicarPastaUseCase`), `restaurar`
  (`RestaurarPastaUseCase`), `favoritar`, `acompanhar` e `destacar` (só para a pessoa: `AlternarFavoritoDaPastaUseCase`,
  `AlternarAcompanhamentoDaPastaUseCase`, `DestacarPastaUseCase`), `marcadores_da_pasta`
  (`SincronizarMarcadoresDaPastaUseCase::executar(int, array, User, Tenant)`,
  `app/src/Expediente/UseCase/SincronizarMarcadoresDaPastaUseCase.php:42`, que deixa a pasta com exatamente a lista
  mandada: só acrescentar executa direto; lista que tira um marcador vai por proposta), `marcador` (do escritório:
  `CriarMarcadorUseCase`, `EditarMarcadorUseCase`, `ReordenarMarcadorUseCase`), `nome_do_cliente`
  (`RenomearClienteDaPastaUseCase`), `cadastro_da_pasta` (`SalvarCadastroDaPastaUseCase`), `reu` (adicionar e subir na
  ordem: `GerenciarReusDaPastaUseCase`), `pedir_acesso`
  (`PedirAcessoAPastaUseCase`; avisa os primários) e `enviar_relatorio_sem_processo` (`EnviarRelatorioSemProcessoUseCase`;
  avisa a Controladoria, os primários e cada responsável).
- Exclusões por proposta: excluir a pasta (vai para a lixeira, `ExcluirPastaUseCase`; a tela exige digitar o número, e o
  resumo da proposta traz o número), excluir marcador (`ExcluirMarcadorUseCase`, sai de todas as pastas), tirar marcador da
  pasta (`marcadores_da_pasta` com lista sem ele; tirar vários numa chamada é uma proposta só), remover réu
  (`GerenciarReusDaPastaUseCase`).
- **Atenção ao contrato no arquivar:** `situacao` com Arquivado passa por
  `DefinirSituacaoDaPastaUseCase::executar` (`app/src/Pasta/UseCase/DefinirSituacaoDaPastaUseCase.php:43`, `:65-66`) e
  `AndamentoDaPasta::finalizarPorArquivamento` (`app/src/Pasta/Service/AndamentoDaPasta.php:192`), que marca Finalizada com
  `semPular` (`:204`): `gravar` (`:319-335`) dá data às fases que faltam até a última (as já puladas continuam puladas) e,
  com Contrato alcançada, `sincronizarContrato` grava o contrato do Financeiro como assinado (`:386-389`), **mesmo para
  quem tem o Financeiro oculto**. Mesmo tratamento de `mudar_andamento`: arquivar executa direto (desarquivar não
  reverte a fase, `:25` do UseCase), e a resposta diz que o contrato foi marcado como assinado **só para quem vê o
  Financeiro da pasta** (6.2.5); para os demais, nenhuma palavra sobre o contrato, como na tela.
- Permissão: recurso `pasta` edit (cadeado em "Visualizar" recusa); `criar`, módulo `expediente` ou `pastas`, como a tela.

**`mudar_andamento`** (PR 10; pedido A)
- Ações: `mudar_fase` (`pasta_id`, `fase` pelo nome da trilha da via da pasta ou pelo número, `sem_pular?`, `motivo?`) e
  `nomear_etapa` (`fase_de_inicio`, `texto` até 12 letras, `RotuloDeEtapa::TAMANHO_MAXIMO`,
  `app/src/Pasta/Entity/RotuloDeEtapa.php:25`).
- Reusa: `MudarFaseDaPastaUseCase::executar(Pasta, User, Tenant, int $fase, bool $semPular, string $motivo)`
  (`app/src/Pasta/UseCase/MudarFaseDaPastaUseCase.php:34`, que já confere escritório e `canAccessResource` edit) e
  `NomearEtapaDaPastaUseCase::executar(Pasta, User, Tenant, int, string)` (`app/src/Pasta/UseCase/NomearEtapaDaPastaUseCase.php:37`).
  Nenhum dos dois manda aviso, Push, e-mail ou Google (inventário 0.A); escrevem no Registro e na auditoria.
- **Atenção ao contrato:** chegar a Contrato marca o contrato do Financeiro como assinado, e voltar para antes de Contrato o
  reabre como pendente (`AndamentoDaPasta::marcar`, `:148`, com `sincronizarContrato`, `:179-180` e `:376`), **mesmo para quem
  tem o Financeiro oculto**, porque a rota do andamento não passa pela barreira do Financeiro. Por isso:
  - avançar executa direto, e a resposta diz quando o contrato foi marcado como assinado;
  - **voltar fase e reativar pasta finalizada** (Finalizada sobre Finalizada) **devolvem proposta** (6.7), como o inventário
    recomenda (seção 6, item 13); o resumo diz o que acontece com o contrato;
  - o que a resposta e o resumo dizem do contrato só aparece para quem vê o Financeiro da pasta (6.2.5); para os demais,
    nenhuma palavra sobre o contrato, como na tela;
  - `nomear_etapa` com texto vazio remove o nome: é exclusão, vai por proposta.
- Permissão: recurso `pasta` edit; nenhuma aba (as rotas do andamento não estão no `MAPA`); chave `expedientes`.

**`registrar_na_pasta`** (PR 10)
- Onde: `registro` (aba Dados), `relatorio_inicial` (aba Detalhes) e `relatorio_financeiro` (aba Financeiro, com 6.2.5).
- Ações: `escrever` e `responder` (com menção por id de colega, da parte `equipe` de `ler_pasta`), `editar` e `destacar`.
- Reusa: `EnviarMensagemPastaUseCase::executar(Pasta, User, string, Tenant, ?PastaMensagem)`
  (`app/src/Pasta/UseCase/EnviarMensagemPastaUseCase.php:96`), `EditarMensagemPastaUseCase`,
  `EnviarObservacaoDetalhesUseCase::executar` (`:42`), `EditarObservacaoDetalhesUseCase`, `EnviarObservacaoFinanceiraUseCase`,
  `EditarObservacaoFinanceiraUseCase`, `DestacarComentarioUseCase`.
- Exclusões por proposta: `editar` nos três lugares (grava por cima do texto: `EditarMensagemPastaUseCase::executar`,
  `app/src/Pasta/UseCase/EditarMensagemPastaUseCase.php:61`, `EditarObservacaoDetalhesUseCase::executar`, `:52`, e
  `EditarObservacaoFinanceiraUseCase::executar`, `:52`; o resumo da proposta traz o texto novo),
  `ExcluirMensagemPastaUseCase` (apaga a linha), `ExcluirObservacaoDetalhesUseCase`, `ExcluirObservacaoFinanceiraUseCase`.
- Efeito: avisa o respondido, os mencionados e quem acompanha (colegas, 6.2.14).
- Permissão: recurso `pasta` edit; aba conforme o lugar (6.6, tabela); chave `expedientes`.

**`prazos_e_metas_da_pasta`** (PR 10; 14.h)
- Ações: `salvar_prazo` (criar ou editar prazo ou audiência, com responsável: `SalvarPrazoDaPastaUseCase`,
  `app/src/Pasta/UseCase/SalvarPrazoDaPastaUseCase.php:37`; aparece na Agenda da equipe), `cumprir_prazo`
  (`CumprirPrazoDaPastaUseCase`), `analisar_prazo_da_meta` (`AnalisarPrazoDaMetaNovaUseCase::executar`,
  `app/src/Tarefa/UseCase/AnalisarPrazoDaMetaNovaUseCase.php:22`; não grava) e `criar_meta` (com responsáveis e prazo).
- Falta, para `criar_meta`: um `CriarMetaUseCase`. A montagem está no `TarefaController::criarParaPasta`
  (`app/src/Controller/TarefaController.php:201`, rota `tarefa_criar_para_pasta`) e no privado `novaMetaDaPasta` (`:282`), que
  grava pelo `GravarMetaNovaUseCase` (`app/src/Tarefa/UseCase/GravarMetaNovaUseCase.php:39`), registra a análise pelo
  `RegistrarAnaliseDoPrazoNaMetaUseCase` e avisa pelo `NotificacaoService::notificarTarefaCriada` (`:265`).
- Exclusão por proposta: excluir prazo (`CumprirPrazoDaPastaUseCase` com excluir).
- Regras: prazo jurídico com data errada causa dano: a resposta repete data, tipo, responsável e que o prazo já aparece na
  Agenda; responsável e meta só para colega ativo do escritório; delegar avisa.
- Permissão: prazo, recurso `pasta` edit e aba Metas (`tarefas`, `MAPA` `pasta_prazo_*`) e chave `expedientes`; meta, módulo
  `tarefas` (`AcessoAoModuloTarefas::pode`, `app/src/Tarefa/Service/AcessoAoModuloTarefas.php:24`) e ver a pasta, como a
  rota. A chave `metas` cobre só `tarefa_minhas` e não vale aqui.

**`documentos_e_pecas`** (PR 12)
- Documentos (aba Documentos): `editar_documento` (`EditarDocumentoDaPastaUseCase`), `mover` (`MoverItensDaPastaUseCase`,
  `MoverDocumentoParaSecaoUseCase`), `copiar` (`CopiarDocumentosDaPastaUseCase`), `favoritar`
  (`AlternarFavoritoDeDocumentoUseCase`), `subpasta` (criar, renomear, mover: `CriarPastaSecaoUseCase`,
  `RenomearPastaSecaoUseCase`, `MoverPastaSecaoUseCase`), `reordenar` (`ReordenarDocumentosUseCase`, `ReordenarSecoesUseCase`) e
  `restaurar` da lixeira (`RestaurarItensDaPastaUseCase`).
- Peças: `escrever_peca` (`SalvarPecaTextoUseCase::executar`, `:60`, que saneia o HTML e grava pela chave de
  `ChavesDePasta::novoDocumento`; falta converter Markdown em HTML antes do saneador), `editar_peca`
  (`EditarPecaTextoUseCase::executar`, `:74`; **por proposta**, porque regrava a peça na mesma chave, sem versão: abertura
  da 6.6), `usar_modelo` (`PrepararDadosDaPecaUseCase::executar`, `:27`, e
  `UsarModeloNaPastaUseCase::executar`, `:42`; preenchido **no servidor**, 6.2.4) e `destino_da_peca`
  (`DefinirDestinoDaPecaUseCase`).
- Modelos do escritório (aba Detalhes): `salvar_modelo` (`SalvarModeloDePecaUseCase`; o banco de modelos é do escritório) e
  `favoritar_modelo` (`AlternarFavoritoDeModeloUseCase`, `AlternarMateriaFavoritaUseCase`).
- Checklist de documentação (mora na aba Documentos, `MAPA` `pasta_checklist_*`): `checklist_item` (adicionar, marcar,
  editar, reordenar: `AdicionarChecklistItemUseCase`, `ToggleChecklistItemUseCase`, `EditarChecklistItemUseCase`,
  `ReordenarChecklistItensUseCase`), `checklist_estado` (ligar ou desligar com motivo, `AlterarEstadoDoChecklistUseCase`) e
  `checklist_modelo` (salvar como modelo, aplicar, renomear: `SalvarChecklistComoModeloUseCase`,
  `AplicarChecklistModeloUseCase`, `RenomearChecklistModeloUseCase`); listar os modelos de checklist, com UseCase extraído do
  controller (PR 14).
- Exclusões por proposta: `editar_peca` (gravar por cima), documento e vários documentos para a lixeira
  (`ExcluirItensDaPastaUseCase`), subpasta
  (`ExcluirPastaSecaoUseCase`), excluir de vez da lixeira (`ExcluirDeVezDaLixeiraUseCase`, irreversível), item de checklist
  (`ExcluirChecklistItemUseCase`), modelo de checklist (`ExcluirChecklistModeloUseCase`), modelo de peça
  (`ExcluirModeloDePecaUseCase`).
- Permissão: recurso `pasta` edit; aba Documentos (Detalhes para modelos e `usar_modelo`, como a tela, e Documentos também em
  `usar_modelo`, porque a resposta devolve o documento criado); chave `expedientes`, e `peticionar` em `escrever_peca`, a
  mesma da tela (`pasta_peticionar_texto`).

**`financeiro_da_pasta`** (PR 12)
- Só com `VisibilidadeDoFinanceiroDaPasta::podeVer` sim e a aba Financeiro visível.
- Ações: `contrato` (pendente ou regular: `AlterarSituacaoContratoUseCase`, que também move o andamento,
  `AndamentoDaPasta::aoMudarContrato`, `:212`), `pro_bono` (`AlterarProBonoDaPastaUseCase`), `valor_da_causa`
  (`AtualizarValorCausaUseCase`), `pagamento` (registrar, parcelar, marcar recebido ou desfazer, corrigir valor:
  `RegistrarPagamentoDaPastaUseCase`, `RegistrarParcelamentoDaPastaUseCase`, `AlternarQuitacaoDoPagamentoUseCase`,
  `CorrigirValorDoPagamentoUseCase`), `documento_assinado` (marcar ou desmarcar: `AlterarAssinaturaDoDocumentoUseCase`) e
  `renomear_documento` (regra no controller, UseCase extraído no PR 14).
- Exclusões por proposta: excluir pagamento (`ExcluirPagamentoDaPastaUseCase`, irreversível) e documento do Financeiro para a
  lixeira (`ExcluirItensDaPastaUseCase`).
- Permissão: recurso `pasta` edit; aba Financeiro; chave `expedientes`.

**`atualizar_cliente`** (PR 11; pedido C)
- Ações:
  - `cadastrar` (**sempre por proposta**, decisão de 08/11/2026): lista de 1 a 50 clientes, cada um com `tipo` (`pf` ou
    `pj`), `campos` (só as chaves de `CamposDoCadastro::ROTULOS`; nenhum é obrigatório) e `pasta_id?`. Com pasta,
    `CriarClienteDaPastaUseCase::executar(Pasta, string, array, User, Tenant)`
    (`app/src/Cliente/UseCase/CriarClienteDaPastaUseCase.php:44`; exige editar a pasta e liga o cliente a ela); sem pasta,
    `CriarClienteAvulsoUseCase`, que exige `resources.carteira.gerenciar`, exatamente como o Chat I.A faz pelo
    `ConversorDaProposta`. Cadastro em massa (o assistente varre pastas, acha documentos de clientes e cadastra vários) é
    **uma proposta com a lista e uma confirmação para a lista toda**;
  - `completar` (`cliente_id`, `campos`: `SalvarCadastroDoClienteUseCase::executar(Cliente, array, User, Tenant, ?Pasta)`,
    `app/src/Cliente/UseCase/SalvarCadastroDoClienteUseCase.php:50`): campo vazio é preenchido direto; **trocar um valor
    que já existe vai por proposta** (é exclusão do valor antigo);
  - `contato` (celular ou e-mail: `AtualizarContatoDoClienteUseCase`, `:61`), com a mesma regra de troca;
  - `vincular_a_pasta` (regra hoje no controller, `pasta_cliente_vincular`: UseCase extraído), `principal`
    (`DefinirClientePrincipalUseCase`) e `ligar_documento` (documento de uma pasta ao cadastro:
    `VincularDocumentoDaPastaAoClienteUseCase`).
- Exclusões por proposta: desvincular o cliente da pasta (`pasta_cliente_desvincular`, UseCase extraído), excluir o cliente
  (`cliente_delete`, regra no controller, apaga os arquivos dele: UseCase extraído no PR 14) e excluir documento do cliente
  (`cliente_documento_delete`, PR 14).
- Duplicidade: `CamposDoCadastro::aplicar` recusa CPF ou CNPJ que já é de outro cadastro do escritório
  (`DocumentoJaCadastradoException`). Na proposta, o servidor confere cada item antes e tira da lista, com o motivo e o nome
  do cadastro existente, o que já está cadastrado; na confirmação, confere de novo (6.7).
- **E-mail de cliente com envio automático: recusa, sem exceção.** O e-mail do cadastro aparece como principal na lista de
  avisos do cliente até a primeira mudança (inventário 0.C); se o escritório já ligou o envio automático para aquele
  cliente, gravar o e-mail faria os avisos do Push irem para ele, e o conector não manda nada ao cliente (14.h). Por isso
  `completar` e `contato` com o campo `email` (`CamposDoCadastro::ROTULOS`, `AtualizarContatoDoClienteUseCase::CAMPO_EMAIL`)
  conferem antes a linha do cliente (`EnvioAutomaticoDoClienteRepository::doClienteDoTenant`,
  `app/src/Cliente/Repository/EnvioAutomaticoDoClienteRepository.php:37`) e, com qualquer chave ligada
  (`EnvioAutomaticoDoCliente::ligada`, `app/src/Cliente/Entity/EnvioAutomaticoDoCliente.php:74`, para cada
  `ChaveDoEnvioAutomatico`), recusam a chamada inteira, sem gravar nada, com a mensagem fixa "este cliente recebe o envio
  automático do Push: o e-mail dele só muda pela tela do BlueJus". Na troca por proposta, a confirmação confere de novo
  (6.7, item 4). Sem linha, ou com as três chaves desligadas (o padrão, `ChaveDoEnvioAutomatico::PADRAO`), grava como a
  tela. `cadastrar` não é afetado: cliente novo não tem a linha.
- Permissão: com pasta, recurso `pasta` edit; completar e contato, recurso `cliente` edit; sem pasta,
  `resources.carteira.gerenciar`. Pasta na lixeira recusa.
- Saída: ids e nomes criados ou alterados, e os nomes dos campos mudados, nunca os valores (o Registro e o Histórico do
  cadastro já guardam só o nome do campo).

**`processos_da_pasta`** (PR 13)
- Ações: `vincular` (novo, já cadastrado ou apensado: `CadastrarProcessoPelaPastaUseCase`, `VincularApensadoUseCase`),
  `principal` (`DefinirProcessoPrincipalUseCase`), `dados_do_cartao` (`SalvarDadosManuaisDoProcessoUseCase`, **sem o campo de
  segredo**: a entrada não o aceita, e o conector nunca liga nem desliga segredo), `acao_da_pasta` (`EditarAcaoDaPastaUseCase`),
  `via_administrativa` (`DefinirPastaAdministrativaUseCase`; troca a trilha do andamento) e `nota_tecnica` (criar e editar:
  `CriarNotaTecnicaUseCase`, `EditarNotaTecnicaUseCase`; menção avisa).
- Exclusões por proposta: desvincular processo (`DesvincularProcessoUseCase`; o processo continua existindo), excluir nota
  técnica (`ExcluirNotaTecnicaUseCase`) e excluir processo (`processo_delete`, regra no controller: UseCase extraído no PR 14).
- Permissão: recurso `pasta` edit e aba Processo, como a tela (`MAPA`, `:72-78`); nota técnica e excluir processo, recurso
  `processo` edit; chave `expedientes`.

**`gravar_na_agenda`** (PR 13)
- Ações: `salvar` (criar ou editar compromisso: tipo, horário, "Equipe" ou "Só eu", pasta, participantes, lembrete;
  `SalvarCompromissoUseCase::executar(CompromissoInput, ?int, User, Tenant, bool)`, `:45`), `mover` (`MoverCompromissoUseCase`,
  `:29`), `status` (concluir, cancelar, reabrir: `AlterarStatusDoCompromissoUseCase`, `:35`; cancelar avisa os participantes)
  e `avisar` (`AvisarNoChatUseCase`, `AvisarPrazoNoChatUseCase`: avisos do sino a colegas pelo `AvisoDoCompromisso`).
- Exclusão por proposta: `ExcluirCompromissoUseCase` (`:27`).
- Teto (6.3): editar, mover, mudar status e excluir só de compromisso que a pessoa criou, conferido pela ferramenta.
- **Google Agenda:** o `MudancaNaAgendaParaOGoogleListener` (`app/src/Agenda/EventListener/MudancaNaAgendaParaOGoogleListener.php`)
  sincroniza, depois do flush, a agenda Google de quem criou e dos participantes **que conectaram a própria conta**. É efeito
  normal da gravação, também pelo conector, e fica: vai para a conta Google de cada colega, não para o cliente nem para
  terceiro. A resposta diz que o compromisso vai para o Google de quem tem a agenda ligada. `openWorldHint: true`.
- Permissão: módulo `agenda`; com pasta, ver a pasta.

**Aba e chave do Master por ferramenta e ação** (regra 11 da 6.2). A coluna "rota da tela" diz o que a barreira de hoje cobre;
"nenhuma" quer dizer que a tela também não barra. `BarreiraDeAbaOcultaListener::MAPA` está em
`app/src/Pasta/EventListener/BarreiraDeAbaOcultaListener.php:49-80`; `CatalogoDeRecursos::ROTAS`, em
`app/src/Master/Service/CatalogoDeRecursos.php:59-74`. Toda ferramenta exige também a liberação do conector nos três níveis
(14.a), conferida pelo portão.

| Ferramenta e ação | Rota da tela | Aba (`AbaDaPasta`) | Chave do Master |
|---|---|---|---|
| `quem_sou_eu` | nenhuma | nenhuma | nenhuma |
| `buscar_pastas` | `inteligencia_agente_pastas_buscar`, a Lista, `demandas_index`, `expediente_busca_rapida`, `expediente_lixeira` | nenhuma | nenhuma (a Lista é essencial, `base: true`; `assistente` desliga o Agente, não a Lista) |
| `ler_pasta`: resumo, andamento, registro, equipe, cadastro | `pasta_show` | nenhuma (Dados não tem rota no `MAPA`) | `expedientes` |
| `ler_pasta`: prazos | `pasta_show` | Metas (`tarefas`) | `expedientes` |
| `ler_pasta`: metas | `pasta_show` | Metas (`tarefas`) | `expedientes` |
| `ler_pasta`: detalhes, controladoria | `pasta_show` | Detalhes | `expedientes` |
| `ler_pasta`: checklist | `pasta_show` | Documentos | `expedientes` |
| `ler_pasta`: financeiro | `pasta_show` | Financeiro | `expedientes` |
| `ler_pasta`: publicacoes | `pasta_push_texto` | Push | `expedientes` |
| `ler_documentos_e_modelos`: listar, ler, lixeira | `pasta_documento*`, `inteligencia_agente_pastas_documentos` | Documentos | `expedientes` |
| `ler_documentos_e_modelos`: modelos, modelo | `pasta_modelos_listar` | Detalhes | `expedientes` |
| `ler_processos`: buscar, detalhar | `processo_index`, `processo_show` | nenhuma (não é rota de pasta) | nenhuma |
| `ler_processos`: para_vincular, cnj | `pasta_buscar_processos`, `pasta_processo_buscar_cnj` | Processo | `expedientes` |
| `ler_clientes`: buscar, ler, conferir_documento, documentos_do_cliente | `cliente_cadastro`, `cliente_resumo`, `pasta_clientes_buscar` | nenhuma | nenhuma |
| `ler_clientes`: documentos_de_identidade | `cliente_resumo` | Documentos, de cada pasta (pasta com a aba oculta sai da lista; a tela não barra, porque a rota não é de pasta) | nenhuma |
| `ler_agenda`: agenda | `agenda_index` | nenhuma | nenhuma |
| `ler_agenda`: minhas_metas | `tarefa_minhas` | nenhuma (rotas `tarefa_*` ficam fora do `MAPA`) | `metas` |
| `atualizar_pasta` | `pasta_edit`, `pasta_alternar_situacao` e as demais do cabeçalho, `expediente_marcador_*`, `pasta_reu_*`, `pasta_controladoria_cadastro` | nenhuma (`pasta_reu_*` fica fora do `MAPA` de propósito) | `expedientes` (na criação, nenhuma: `pasta_new` não é `pasta_show`) |
| `mudar_andamento` | `pasta_andamento_mudar`, `pasta_andamento_etapa` | nenhuma | `expedientes` |
| `registrar_na_pasta`: registro | `pasta_enviar_mensagem` | nenhuma | `expedientes` |
| `registrar_na_pasta`: relatorio_inicial | `pasta_detalhes_observacao_*` | Detalhes | `expedientes` |
| `registrar_na_pasta`: relatorio_financeiro | `pasta_financeiro_observacao_*` | Financeiro | `expedientes` |
| `prazos_e_metas_da_pasta`: prazo | `pasta_prazo_*` | Metas (`tarefas`) | `expedientes` |
| `prazos_e_metas_da_pasta`: meta, analise | `tarefa_criar_para_pasta`, `tarefa_analise_do_prazo` | nenhuma (rotas `tarefa_*` ficam fora do `MAPA`) | nenhuma |
| `documentos_e_pecas`: documentos, checklist | `pasta_documento*`, `pasta_secao*`, `pasta_checklist_*` | Documentos | `expedientes` |
| `documentos_e_pecas`: escrever_peca | `pasta_peticionar_texto` | Documentos | `peticionar` e `expedientes` |
| `documentos_e_pecas`: modelos, usar_modelo | `pasta_modelos_*` | Detalhes (e Documentos em `usar_modelo`) | `expedientes` |
| `financeiro_da_pasta` | `pasta_financeiro_*`, `pasta_pagamento_*`, `pasta_alternar_situacao_contrato` | Financeiro | `expedientes` |
| `atualizar_cliente` | `cliente_cadastro_salvar`, `pasta_cadastro_novo_salvar`, `pasta_cliente_vincular` | nenhuma | `expedientes` quando age pela pasta; nenhuma sem pasta |
| `processos_da_pasta` | `pasta_vincular_processo`, `pasta_definir_processo_principal`, `pasta_acao_salvar`, `pasta_administrativa_definir`, `processo_nota_tecnica_*` | Processo (nota técnica: nenhuma) | `expedientes` (nota técnica: nenhuma) |
| `gravar_na_agenda` | `app_agenda_compromisso_*` | nenhuma | nenhuma |
| `confirmar_acao` | a da ação confirmada | a da ação confirmada | a da ação confirmada |

Onde a tabela pede `expedientes` sem que a rota da tela o peça, é porque a informação só aparece dentro da tela da pasta, que a
chave desliga. A conferência fica num lugar só, e o teste 12.9 percorre a tabela linha por linha.

### 6.7 Confirmação: cadastrar cliente e qualquer exclusão (decisão de 08/11/2026)

**O que exige confirmação da pessoa no próprio chat:** cadastrar cliente (um ou uma lista) e **toda exclusão** (definição na
abertura da 6.6), inclusive gravar por cima: `editar_peca` (`documentos_e_pecas`), `editar` em `registrar_na_pasta` e a
lista de `marcadores_da_pasta` que tira um marcador (`atualizar_pasta`). Por regra desta spec, também voltar fase e
reativar pasta (`mudar_andamento`), porque mexem no contrato.

**O que as fontes oficiais dizem** (lidas em 09/11/2026; as páginas da especificação e do claude.com não mostram data):

- A especificação MCP tem a *elicitation*: o servidor pede à pessoa, pelo cliente, uma resposta num formulário
  (`elicitation/create`; resposta `accept`, `decline` ou `cancel`). É opcional: "Clients that support elicitation MUST
  declare the `elicitation` capability"; o servidor "MUST NOT use elicitation to request sensitive information"
  (https://modelcontextprotocol.io/specification/2025-06-18/client/elicitation). A 2025-11-25 acrescentou o modo URL e proíbe
  mandar um modo que o cliente não declarou (https://modelcontextprotocol.io/specification/2025-11-25/client/elicitation). Na
  2026-07-28, sem estado, a elicitation volta como resultado `input_required` e o cliente reenvia a mesma chamada com as
  respostas; o `requestState` "MUST" ter integridade protegida (HMAC ou AEAD) quando influencia autorização, com uso único
  garantido no servidor (https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr).
- **Claude Code** mostra o formulário de elicitation (https://code.claude.com/docs/en/mcp, "Respond to MCP elicitation
  requests"), mas um hook `Elicitation` pode responder sozinho, sem diálogo (https://code.claude.com/docs/en/hooks.md); e
  `_meta["anthropic/requiresUserInteraction"]: true` no `tools/list` faz pedir aprovação a cada chamada, sem "não perguntar de
  novo" (mesma página do MCP).
- **claude.ai, Desktop e celular:** o diretório diz que "destructive tools always prompt"
  (https://claude.com/docs/connectors/building/review-criteria.md), mas a pessoa pode pôr cada ferramenta em "Always allow"
  (https://claude.com/docs/connectors/getting-started).
- **ChatGPT:** "Write actions by default require confirmation"; ferramenta sem `readOnlyHint` é escrita; a pessoa pode
  "remember the approve or deny choice for a given tool for a conversation"
  (https://developers.openai.com/api/docs/guides/custom-mcp-server.md). As anotações "do not replace authorization,
  validation, or confirmation in your server" (https://developers.openai.com/plugins/build/mcp-server).
- As anotações são dicas, e o `tools/call` não traz nenhum campo que diga ao servidor que a pessoa aprovou
  (https://modelcontextprotocol.io/specification/2025-06-18/schema). Conclusão: **o servidor não consegue provar que uma
  pessoa leu**. Ele consegue garantir que nada acontece sem uma confirmação presa à proposta (mesma pessoa, escritório,
  consentimento e cliente OAuth, uso único, até 10 minutos), e que essa confirmação vale só para aquilo, uma vez. Se o
  cliente mostra esse passo à pessoa depende do cliente e da configuração dela.

**Não confirmado em fonte oficial:** se claude.ai, Desktop e celular suportam elicitation (a palavra não aparece em
https://claude.com/docs/llms-full.txt; dois terceiros dizem que não: Relativity, atualizada em 06/11/2026,
https://help.relativity.com/RelativityOne/Content/Relativity/MCP/Anthropic_Claude.htm, e Consilio, sem data,
https://mcp-docs.ai.consilio.com/docs/integrations/claude-ai/); se o "Always allow" do Claude vale para ferramenta com
`destructiveHint: true`; se a caixa de aprovação do Claude e do ChatGPT mostra os argumentos da chamada; se o ChatGPT
clássico na web e no celular mostra a elicitation padrão (a OpenAI documenta formulários no Desktop e na web "Work",
https://github.com/openai/mcp-extensions/blob/main/docs/spec.md, último commit em 05/11/2026); se o claude.ai respeita
`anthropic/requiresUserInteraction` (confirmado só no Claude Code).

**Desenho:**

1. **Proposta.** A ação que exige confirmação nunca executa na primeira chamada. O servidor grava uma proposta
   (`conector_ia_proposta`, 13.2): pessoa, escritório, consentimento e cliente OAuth do token, ferramenta e ação, os dados
   exatos (JSON) e o hash deles, um **resumo** em português do que vai acontecer (ex.: "Excluir 3 documentos da pasta 2024/118:
   procuracao.pdf, rg.jpg, contrato.docx"; ou a lista dos clientes com os valores que o assistente mandou), estado
   `pendente`, validade de **10 minutos** e uso único. O identificador é aleatório e só o hash dele é gravado. A resposta
   leva o id, o resumo e a instrução fixa "mostre o resumo à pessoa e só chame `confirmar_acao` se ela disser sim".
2. **Com elicitation** (o cliente declarou a capacidade `elicitation` ao iniciar a sessão): a própria ferramenta pede a
   confirmação pelo protocolo antes de responder (`ClientGateway::elicit(string $message, ElicitationSchema, int $timeout)`,
   `app/vendor/mcp/sdk/src/Server/ClientGateway.php:178`): modo formulário, o resumo na `message` e um único campo booleano
   "Confirmo". `accept` com `true` executa na mesma chamada; `decline`, `cancel` ou tempo esgotado cancelam a proposta. A
   elicitation nunca pede dado (só sim ou não). Conferir no PR 9 que o `elicit` do SDK funciona com o transporte HTTP e a
   sessão em cache (3.5).
3. **Sem elicitation** (o caso esperado no claude.ai e no ChatGPT clássico): a confirmação é a ferramenta separada
   `confirmar_acao(proposta_id, resumo)`, anotada `destructiveHint: true` e com `_meta["anthropic/requiresUserInteraction"]:
   true`, que o Claude e o ChatGPT mostram para aprovação antes de chamar, **salvo** se a pessoa marcou "Permitir sempre"
   ("Always allow", claude.ai) ou "lembrar" (ChatGPT) para ela: aí o próprio modelo pode chamar `confirmar_acao` sem
   pergunta nenhuma. Ela exige o **resumo exato** além do id: se a caixa de aprovação mostrar os argumentos (não
   confirmado), a pessoa lê o que vai acontecer, e não só um número; e o modelo não consegue mudar o resumo sem que a
   confirmação falhe.
4. **Na confirmação o servidor reconfere tudo**, com trava de linha numa transação, como o
   `ConfirmarPropostaDeCadastroUseCase` do Chat I.A (`app/src/ChatIa/UseCase/ConfirmarPropostaDeCadastroUseCase.php:72`):
   proposta `pendente` e no prazo (vencida vira `expirada`); mesma pessoa, escritório, consentimento e cliente OAuth do token
   atual; resumo e hash iguais; e de novo toda a permissão, o teto, as abas, a chave, a liberação, o segredo e o estado do
   item (pasta excluída, CPF que virou repetido). Usada, vencida, de outra pessoa ou de outra conexão: recusa com mensagem
   fixa, sem dizer se existe. Dois `confirmar_acao` ao mesmo tempo gravam uma vez.
5. **Lote:** uma proposta leva até 50 itens; **uma confirmação vale para a lista toda** (decisão de 08/11/2026). Cada item roda
   na própria transação, e a resposta diz o resultado de cada um (criado, excluído, recusado e por quê). Os itens que já
   falham na proposta (CPF repetido, sem permissão) saem da lista antes, com o motivo, para a pessoa não confirmar o que não
   vai acontecer.
6. **Dados zerados:** depois de confirmada, cancelada ou vencida, a proposta guarda só estado, datas e ids do resultado; os
   dados (que podem ter CPF) somem, como a `PropostaDeCadastro` do chat (`app/src/ChatIa/Entity/PropostaDeCadastro.php:22`). As
   vencidas são varridas ao registrar uma proposta nova, como no chat. `dados` e `resumo` nunca vão para log nem auditoria.
7. **Na versão 2026-07-28** (PR próprio, 3.5): a confirmação vira `input_required`, com o `requestState` assinado por HMAC,
   preso à pessoa e à proposta, com validade curta e uso único conferido no servidor, como a especificação manda.

**Limite, dito com honestidade:** o servidor garante que só executa com uma confirmação presa à proposta (pessoa,
escritório, consentimento, cliente OAuth, uso único, 10 minutos). Ele **não** garante que uma pessoa leu o resumo. A
confirmação mais forte é a elicitation (item 2), onde o cliente tiver; sem ela, com "Permitir sempre" no claude.ai ou
"lembrar" no ChatGPT, o modelo chama `confirmar_acao` sozinho, e um hook do Claude Code pode responder à elicitation sem
diálogo. É risco residual aceito (11, 14.g); o guia do usuário pede para não marcar essas opções, e o modo URL fica como
reforço futuro (alternativas recusadas, abaixo).

**Alternativas recusadas:** recusar cadastro e exclusão quando o cliente não declara elicitation (na 2026-07-28, erro
`MissingRequiredClientCapabilityError`, -32021) deixaria o pedido sem efeito no claude.ai; o modo URL (uma página do BlueJus
onde a pessoa confirma, com conferência da sessão) é a prova mais forte, mas tira a confirmação do chat, contra a decisão, e
o suporte nos clientes não está confirmado. Fica como reforço futuro, se o piloto mostrar abuso.

**O guia do usuário** (PR 15) pede para nunca marcar "sempre permitir" na `confirmar_acao` e explica o que é a proposta.

**Injeção indireta:** documento, anotação, publicação ou movimentação pode trazer texto que tenta mandar o assistente gravar
algo. Não controlamos o prompt do cliente; as defesas são o escopo `mcp:escrita` desmarcado por padrão, a proposta com
confirmação para o que apaga, grava por cima ou cadastra, a resposta que diz exatamente o que foi gravado e a marcação de
dado (6.2.6) em toda resposta de leitura.

### 6.8 Reuso com o Chat I.A

Hoje uma ferramenta do chat são quatro peças separadas: a definição (`FerramentaDeLinguagem(nome, descricao, esquemaDaEntrada)`,
`app/src/Inteligencia/DTO/FerramentaDeLinguagem.php`, neutra de fornecedor; só `paraAnthropic()` é acoplado), a guarda
(`GuardaDasPropostas`, `GuardaDaConsultaDePrazos`), o executor e o despacho por nome dentro do
`TransmitirRespostaDoChatUseCase`.

**Recomendação:** um registro de ferramentas com `nome`, `schema`, `anotacoes`, `guarda(User, Tenant)` e
`executar(User, Tenant, array)`, usado pelo chat e pelo conector, para a mesma ferramenta não ter duas regras. Entra no PR 6
movendo o que existe, sem mudar o comportamento do chat. A proposta do conector (6.7) é parecida com a do chat, mas não
reaproveita a `PropostaDeCadastro`, que é presa a uma conversa do chat (`ConversaDeChat` no construtor) e só cadastra; os
validadores e o conversor do chat (`ValidadorDaProposta`, `ConversorDaProposta`) são reaproveitados no cadastro.

---

## 7. Legado que a feature encosta

Com o escopo novo (14.b), o conector encosta em cerca de 50 ações cuja regra está no controller (inventário, seção 6, item
3). Cada uma ganha UseCase antes de entrar, no PR que a usa; as que sobram vão juntas no PR 14 (13.1). Onde a tela antiga tem
versão nova, entra só a nova (6.1).

- **`Processo` está no domínio** (`app/src/Processo/Entity/Processo.php`, com escritório), mas a regra da tela continua no
  `ProcessoController`. Faltam UseCases de leitura (PR 7), da consulta ao CNJ (PR 13) e de excluir processo (PR 14).
- **`Cliente`** tem UseCases em `app/src/Cliente/UseCase/` para cadastro e contato; o `ClienteController` não tem listagem, e
  o `ClienteRepository::findByFilters` não recebe escritório e não tem chamador. Buscar, ler e conferir documento ganham
  UseCase de leitura no PR 11; vincular e desvincular da pasta, também; excluir cliente e os documentos do cliente, no PR 14.
  `Cliente` usa herança JOINED (`ClientePF`/`ClientePJ`); consulta nova considera as duas.
- **`Tarefa` é legado** (`app/src/Entity/Tarefa/Tarefa.php`); criar meta para a pasta ganha o `CriarMetaUseCase` extraído do
  `TarefaController` (PR 10).
- **`PastaController`** tem o detalhe da pasta e o download, presos à sessão. O conector não passa por ele; a leitura da pasta
  vem do `MontadorDeContextoDaPasta` e da API do Agente de Pastas (6.4), que já rodam sem sessão.
- **Agenda antiga** (`agenda_*`): fica fora; entra a Agenda nova, que já tem UseCases (6.6).
- **Cobrança**: fora (14.h).

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
   não todo dia. Na prática (decisão 14.c, 08/11/2026): token de acesso de 1 hora e renovação com rotação, sem passar de 7
   dias da autorização. Renovação já usada que volta é sinal de roubo: a cadeia inteira é revogada.
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
   - consentimento e token não revogados;
   - a liberação do conector nos três níveis (chave geral, escritório liberado pelo Master, ligado com o aceite vigente,
     14.a).

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
   `redirect_uri` e ao `code_challenge`. Renovação com rotação e `invalid_grant` (14.c).
7. **Auditoria**: escrita registrada pelo `audit_log` com o escritório certo (4.3); chamadas no canal `mcp` sem conteúdo
   (6.2.9). Correção da spec de agosto: "registrar os argumentos" vira registrar ids e valores de lista, nunca texto
   (regra 3 da Inteligencia).
8. **Dado pessoal e provedor do modelo**: decisão 14.a (piloto num escritório, liberação pelo Master, aceite próprio do
   conector) e as regras de saída da 6.2.
9. **Purga e exclusão**: as tabelas novas com escritório entram no `PurgarEscritorioUseCase`; excluir a conta ou o vínculo
   revoga consentimentos e tokens.
10. **Injeção indireta pelo conteúdo**: 6.7.
11. **Teto**: nenhuma função de administrador pelo conector, nem para Master e primário (6.3).
12. **Confirmação**: cadastro de cliente e toda exclusão (gravar por cima inclusive) só com uma confirmação presa à
    proposta, conferida de novo no servidor; o servidor não confia nas anotações para isso, mas não prova que uma pessoa
    leu: com "Permitir sempre" ou "lembrar", o modelo pode confirmar sozinho (6.7, limite; 14.g).

---

## 10. O que fica fora desta spec

- `consultar_sql` remoto (6.1); a v1 STDIO/SSH continua, só para o dono.
- Funções de administrador dos quatro módulos (6.1), garantidas pelo teto (6.3).
- Ponto eletrônico, Cobrança, a BlueJus I.A. dentro da pasta, todo envio ao cliente ou a terceiro (inclusive gravar o
  e-mail de cliente com envio automático ligado), o encaminhar pela Central, o Push além de ler as publicações e Minhas
  Metas além de criar meta para a pasta e de ler a lista (14.h, 14.i).
- Enviar arquivo e baixar arquivo binário (6.1; decisão 14.j).
- Áudio e vídeo (14.f).
- Gemini (app, Enterprise, CLI e Antigravity) e a publicação nos diretórios do Claude e do ChatGPT, no piloto (14.d).
- A versão 2026-07-28 do protocolo (PR próprio, 3.5), com a confirmação por `input_required` (6.7, item 7).
- Confirmação pelo modo URL da elicitation (6.7, alternativas recusadas).
- Selo "pelo conector" no Registro da pasta (6.2, regra 13).
- Cliente MCP próprio do BlueJus (aqui somos só servidor).

---

## 11. Riscos conhecidos

| Risco | Por que importa |
|---|---|
| **`TenantFilter` falha aberto** | Rota nova que esqueça de amarrar o escritório devolve tudo, sem erro. Item nº 1 do plano (PR 1). |
| **Guardas em listeners HTTP** | Cadeado, aba oculta, pasta excluída, função desligada, termos de uso, "Sair de todos os aparelhos" e barreiras do escritório não rodam no conector; cada uma precisa ser refeita e testada (5.2). E dois desses listeners quebram o `/mcp` se não o deixarem passar: redirecionam para `tenant_selecionar` ou `termo_aceite` (8). |
| **Bypass do Master e do primário** | Sem o teto (6.3), "exceto funções de administrador" não se cumpre: o Master passa por toda checagem e o primário também. O modo do conector mexe em `PermissionChecker` e `RegraDosPrimarios`, código de identidade: se valer para outra pessoa que não a do token, um colega deixa de receber aviso de primário; se vazar para a requisição seguinte do mesmo processo, o Master perde o bypass no navegador. Só para a pessoa do token, zerado no `reset()` e testado (12.19). Perfil de sistema sem alguma permissão criada depois do escritório perderia acesso pelo conector: o PR 1 confere na produção. |
| **Confirmação depende do cliente** | O servidor garante que nada é excluído, gravado por cima ou cadastrado sem uma confirmação presa à proposta (pessoa, escritório, consentimento, cliente OAuth, uso único, 10 minutos), mas não prova que uma pessoa leu: com "Permitir sempre" ("Always allow") no claude.ai ou "lembrar" no ChatGPT, o próprio modelo pode chamar `confirmar_acao`; um hook do Claude Code responde à elicitation sozinho; e a elicitation, a confirmação mais forte, não está confirmada no claude.ai (6.7, limite; 14.g). Risco residual aceito. |
| **Contrato mexido pela esteira** | Mudar a fase ou arquivar a pasta marca ou reabre o contrato do Financeiro, mesmo para quem não vê o Financeiro (6.6, `mudar_andamento` e `atualizar_pasta`). Voltar e reativar vão por proposta; a resposta só fala do contrato a quem vê o Financeiro. |
| **Cadastro em massa** | Um erro do assistente se repete em até 50 cadastros de uma vez. Limite, proposta com a lista, duplicados tirados antes e resultado por item (6.7, item 5). |
| **UseCases extraídos de controller** | Cerca de 50 ações do escopo têm a regra no controller (inventário, seção 6, item 3). Cada extração muda a tela também; mover sem reescrever, com os testes da tela escritos antes. |
| **Auditoria sem escritório** | Escrita pelo conector gravaria `audit_log` com escritório nulo (4.3). |
| **nginx barra `.well-known`** | Falha silenciosa: o cliente só diz "não consegui conectar" (3.3). |
| **`mcp/sdk` experimental (0.7.1)** | API muda entre versões menores; a 2026-07-28 vai exigir atualização. Versão fixa, como na v1. A elicitation do SDK com o transporte HTTP precisa de prova no PR 9 (6.7, item 2). |
| **Servidor de autorização dentro da aplicação** | Uma falha nele é uma falha no sistema inteiro, não num serviço isolado. |
| **DCR deprecated, mas necessário** | O Claude registra um cliente por conexão; sem expiração, a tabela cresce sem limite. |
| **Clientes mudam rápido** | Nomes, planos e regras de Claude e ChatGPT mudaram várias vezes em 2026; o guia do usuário leva data e é revisto a cada trimestre. |
| **Injeção indireta** | Texto de documento pode instruir o assistente a gravar (6.7). |
| **Limites de tamanho por cliente** | Resposta grande demais é cortada ou recusada pelo cliente (6.2, regra 7). |
| **Dado pessoal saindo para o provedor do modelo** | O provedor é o da conta da pessoa, sem contrato com o BlueJus. Piloto num escritório, liberação pelo Master, aceite próprio do conector (14.a) e máscara de CPF, CNPJ, telefone e e-mail sempre ligada no texto livre (6.2.6); o texto está com o jurídico. |
| **Arquivo malicioso na leitura** | DOCX, planilha, apresentação e ZIP são pacotes: arquivo forjado pode estourar memória. Mesma proteção de pacote da prévia, tetos de tamanho, XML lido como texto (6.5). |
| **Dependência nova para TIFF e HEIC** | Converter exige extensão nova na imagem PHP (arquivo quente, passo de deploy). Se não puder entrar, esses dois tipos ficam recusados com mensagem fixa (6.5). |
| **Google Agenda dos colegas** | Compromisso gravado pelo conector vai para a agenda Google de quem criou e dos participantes que ligaram a conta, como na tela (6.6, `gravar_na_agenda`). É a única exceção à regra de não mandar nada para fora (14.h). |

---

## 12. Testes que sustentam a feature

Escritos **antes** da implementação, e cada um provado reintroduzindo o defeito (disciplina que na v1 pegou seis premissas
erradas do plano, todas invisíveis à leitura):

1. **Cross-tenant**: pessoa do escritório A pede recurso do escritório B, por **cada** ferramenta e **cada** ação, e recebe
   negativa (não lista vazia por acaso: negativa). Este é o teste que sustenta a spec. Vale também para a `confirmar_acao`
   com proposta do escritório B.
2. **Sem escritório resolvível**, a requisição é recusada; o filtro nunca fica desligado. Com o filtro desligado à força, a
   ferramenta ainda não devolve dado de outro escritório (guarda explícita), e o portão recusa.
3. **Vínculo inativo, situação suspensa, escritório suspenso ou em quarentena, pedido de exclusão, pessoa desativada**: cada
   um recusa o token na requisição seguinte. Caso próprio: o **primário** de escritório suspenso a pedido é recusado no
   `/oauth/authorize` e no `/mcp` com a `BloqueioPorPedidoDeExclusao::MENSAGEM`, enquanto o mesmo primário, no navegador,
   continua chegando ao Meu Perfil (o teste do listener segue verde).
4. **Permissão**: pessoa sem o módulo recebe negativa mesmo com escopo `mcp:leitura`.
5. **Escopo**: token só com `mcp:leitura` não executa ferramenta de gravação nem a `confirmar_acao` (403
   `insufficient_scope`), mesmo que a pessoa tenha a permissão.
6. **Revogação**: revogado o consentimento ou desativado o vínculo, o token para de funcionar.
7. **Fluxo OAuth completo** ponta a ponta, com PKCE, contra o servidor de verdade: CIMD e DCR. Renovação: o token de acesso
   vence em 1 hora, a renovação gira (a antiga, reusada, derruba a cadeia inteira) e nenhuma renovação passa dos 7 dias da
   autorização (14.c).
8. **Gravação** exige o escopo e gera registro de auditoria com o escritório, a pessoa do token e a rota do `/mcp`.
9. **Cada guarda de 5.2** recusada pelo conector: cadeado, aba oculta, pasta excluída na gravação, função desligada no
   Master (também para o Master), meta por id, termos de uso. Para aba e chave, o teste percorre a **tabela de 6.6 linha por
   linha**: com a aba da linha oculta, a ação recusa (inclusive `ler_documentos_e_modelos` com a aba Documentos oculta, que
   o UseCase sozinho deixaria passar, e `ler_clientes` `documentos_de_identidade`, em que os documentos da pasta com a aba
   Documentos oculta não saem); com a chave da linha desligada, recusa; e uma ferramenta ou ação nova sem linha na tabela
   derruba o teste.
10. **Segredo de justiça** (do tribunal e manual): lista omite e conta; por id recusa, **na leitura e na gravação** (cada
    ferramenta de gravação com pasta ligada a processo em segredo); `processos_da_pasta` não aceita o campo de segredo.
    Casos próprios: `ler_agenda` omite e conta os prazos e audiências de pasta ligada a processo em segredo (com e sem
    cadeado); `ler_clientes` `documentos_de_identidade` não traz documento de pasta em segredo; `ler_processos` `cnj` com
    o número de um processo do escritório em segredo recusa **sem chamar o DataJud** (o dublê do cliente HTTP prova zero
    chamadas), e com número que não é do escritório consulta normalmente.
11. **Cliente só pelo necessário**: nenhuma resposta, fora o conteúdo de documento lido, traz CPF, CNPJ, RG, data de
    nascimento, e-mail, telefone ou endereço (teste que procura esses padrões nas respostas de todas as ferramentas, com dados
    de fábrica). `ler_clientes` diz quais campos estão preenchidos sem o valor; `conferir_documento` diz só se existe e de
    quem; o resumo da proposta de cadastro traz só o que o assistente mandou. **Máscara sempre ligada** (6.2.6): num
    escritório que desligou a máscara da BlueJus I.A. (`mascararDadosPessoais` falso), CPF, CNPJ, telefone e e-mail escritos
    em anotação, observação, meta, movimentação e publicação saem mascarados pelo conector; o conteúdo de documento lido sai
    como está; e, no mesmo processo, a requisição seguinte do chat volta a seguir a preferência do escritório.
12. **CIMD**: `client_id` diferente da URL, redirect fora do documento, domínio fora da lista e endereço privado são recusados.
    **DCR**: registro com `redirect_uris` de host fora da lista permitida recebe `invalid_redirect_uri` e não cria cliente;
    host da lista e loopback passam; a tela de consentimento de um cliente registrado por DCR mostra o host do redirect.
13. **Metadados**: as duas URLs `.well-known` respondem pelo Symfony (funcional) e o `nginx.prod.conf` tem a location
    `^~ /.well-known/oauth-` antes do bloqueio de arquivos ocultos (arquitetura). O metadado anuncia `refresh_token` e
    `offline_access`.
14. **Anotações** (arquitetura): toda ferramenta tem `title` e as quatro dicas com valor explícito; as de leitura têm
    `readOnlyHint: true`; as de gravação e a `confirmar_acao` exigem `mcp:escrita`; a `confirmar_acao` tem
    `_meta["anthropic/requiresUserInteraction"]: true`; nenhuma ferramenta lê e grava.
15. **Log `mcp`** sem conteúdo: nenhuma linha leva texto de busca, título, nome, valor de campo, resumo de proposta ou
    conteúdo.
16. **"Sair de todos os aparelhos" e troca de senha**: depois de cada um, o token de acesso é recusado no `/mcp` e a
    renovação recebe `invalid_grant` no `/oauth/token`, inclusive a renovação já rodada uma vez depois da autorização (prova
    que a rotação copia o `autorizado_em`). Troca de senha testada pelo perfil (`AlterarSenhaUseCase`) e pelo link
    (`RedefinirSenhaUseCase`); autorização feita depois do corte ou da troca funciona.
17. **Tipos de documento** (6.5): cada linha da tabela com um arquivo de fábrica sai do jeito descrito; áudio e vídeo
    recusados com mensagem fixa; ZIP lista sem abrir; XML com entidade externa não é resolvido; planilha cortada no teto com
    aviso; pacote forjado recusado; documento na lixeira e documento com a aba Documentos oculta recusados; sem o aceite do
    conector, nenhum conteúdo sai.
18. **`/mcp` sem sessão**: bearer válido, sem cookie, recebe a resposta MCP, nunca 302 para `tenant_selecionar` ou
    `termo_aceite`, e a resposta não traz `Set-Cookie`. Pessoa sem o aceite da versão vigente dos termos é recusada pelo
    portão com mensagem fixa.
19. **Teto** (6.3): Master secundário com vínculo comum não vê pasta sem acesso; Master e primário não veem **nenhuma**
    pasta trancada (modo "Ocultar para todos" e modo por pessoa), nem depois de um pedido de acesso aprovado, e aba oculta
    para todos também some para eles; nenhuma ação de administrador da 6.1 é
    alcançável por Master ou primário (teste de arquitetura sobre as dependências das classes do conector); compromisso
    criado por outra pessoa é recusado para quem tem `admin.users.manage`; a agenda nunca sai com `administra`; o relatório
    sem processo pedido pelo conector continua avisando os outros primários; uma requisição do navegador depois de uma do
    conector, no mesmo processo, tem os bypasses de volta.
20. **Confirmação** (6.7): cada ação marcada como exclusão, o cadastro e voltar ou reativar a fase devolvem proposta e não
    gravam nada (teste que percorre a lista de ações), inclusive gravar por cima: `editar_peca` não muda o arquivo nem o
    `sha256` antes da confirmação, `registrar_na_pasta` `editar` não muda o texto, e `marcadores_da_pasta` com lista que tira
    um marcador vira proposta (lista que só acrescenta executa direto; tirar vários é uma proposta e uma confirmação); a
    proposta é recusada vencida, já usada, de outra pessoa, de outro escritório, de outro cliente OAuth, de consentimento
    revogado e com resumo diferente; duas confirmações ao mesmo tempo
    gravam uma vez; na confirmação, permissão retirada, aba ocultada ou pasta excluída nesse meio tempo recusam; com
    elicitation, `accept` com "Confirmo" executa e `decline`, `cancel` e tempo esgotado não executam; sem a capacidade
    declarada, o servidor nunca manda `elicitation/create`; depois de decidida ou vencida, a proposta não guarda dados.
21. **Esteira** (6.6, `mudar_andamento`): fase pelo nome e pelo número, nas duas vias; avançar até Contrato marca o contrato
    e a resposta diz isso só para quem vê o Financeiro; voltar e reativar viram proposta; cadeado em "Visualizar" recusa;
    pasta excluída recusa; nome de etapa com mais de 12 letras recusado e nome vazio vira proposta. Arquivar pela
    `atualizar_pasta` (`situacao`) leva a Finalizada, marca o contrato como assinado e a resposta diz isso só para quem vê o
    Financeiro; para quem tem o Financeiro oculto, nenhuma palavra sobre o contrato.
22. **Cadastro em lote** (6.6, `atualizar_cliente`): 1 a 50 itens, 51 recusado; CPF ou CNPJ já cadastrado, ou repetido
    dentro da própria lista, sai da proposta com o motivo; CPF que vira repetido entre a proposta e a confirmação dá resultado
    parcial, item por item; sem pasta exige `resources.carteira.gerenciar`; `completar` não troca valor existente sem
    proposta; a resposta traz nomes de campo, nunca valores. **E-mail e envio automático:** `completar` e `contato` com
    `email` de cliente com qualquer chave do envio automático ligada recusam a chamada inteira com a mensagem fixa e não
    gravam nada; com a linha ausente ou as três chaves desligadas, gravam; a chave ligada entre a proposta de troca e a
    confirmação recusa na confirmação.
23. **Publicações**: ler a lista e o texto não muda o `lida` de nenhuma publicação; publicação de processo de outra pasta é
    recusada; aba Push oculta recusa.
24. **Liberação** (14.a): chave geral desligada, escritório não liberado pelo Master, conector não ligado no escritório ou
    aceite em versão antiga: cada um recusa no `/oauth/authorize` e no `/mcp`; desligar depois de conectar derruba na
    requisição seguinte; liberar o escritório A não libera o B. Sem registro, a chave geral vale como ligada e o escritório,
    como não liberado (o nível 2 nasce falso).
25. **Tela que ganhou UseCase**: cada UseCase extraído de controller (6.4 e 6.6, "Falta") tem os testes funcionais da tela
    escritos antes e verdes depois, sem mudar o comportamento.
26. **Minhas Metas** (`ler_agenda`, `minhas_metas`, 14.i): só as metas da própria pessoa, na aba e no período pedidos, sem
    as de outro escritório e sem as de pasta em segredo (contadas); sem o módulo `tarefas` recusa; com a chave `metas`
    desligada recusa; escritório com o aceite da BlueJus I.A. e sem a liberação do conector recusa (a porta é a do
    conector), e o chat continua exigindo o aceite da I.A.; nada é gravado (nenhuma meta muda).

---

## 13. Decomposição

### 13.1 PRs

Cada PR entrega software que funciona e é revisável sozinho. Estimativa em dias de trabalho de uma sessão, com testes e
revisão.

| # | Branch sugerida | Entrega | Depende de | Risco | Estimativa |
|---|---|---|---|---|---|
| 1 | `conector-portao` | Portão sem HTTP: barreiras do escritório extraídas do `TenantContextValidatorListener` para um serviço sem sessão (o listener passa a usá-lo, sem mudar comportamento), com o primário de escritório suspenso a pedido recusado no conector (seção 4, regra 1) e a conferência dos termos de uso (5.2); filtro ligado e conferido (fail-closed); escopo de auditoria do conector; **modo do conector** (6.3, item 2) com o teste de que o navegador não muda; teste de arquitetura | nada | ALTO, com re-revisão | 3 dias |
| 2 | `conector-tabelas` | `composer` (`league/oauth2-server-bundle` ^2.0, `symfony/psr-http-message-bridge`), entidades e **a única migration** (número dado pelo Coordenador) com as 7 tabelas de 13.2, purga do escritório | nada (paralelo ao 1) | ALTO | 2 dias |
| 3 | `conector-autorizacao` | `/oauth/authorize` (login, escolha do escritório, consentimento com escopos, tela pela convenção do sistema, 14.e), `/oauth/token` com token de 1 hora e renovação com rotação até 7 dias (14.c), metadado RFC 8414, PKCE S256, `resource` para `aud`, `iss`, regras de redirect, `autorizado_em` e impressão da senha (seção 4, regra 3), liberação nos três níveis conferida na autorização (14.a), rotas nas listas dos dois listeners (8), chaves de assinatura (passo do Samuel); conferência: o `client_id` real que o Claude manda, para ajustar a lista de domínios da 3.4 | 1, 2 | ALTO, com re-revisão | 3 dias |
| 4 | `conector-registro` | CIMD (busca segura, lista `claude.ai` e `chatgpt.com`, cache) e DCR em `/oauth/register` (limite, expiração, redirect só da lista ou loopback); sem cliente pré-registrado no piloto (14.d) | 3 | ALTO | 1,5 dia |
| 5 | `conector-mcp` | Firewall do `/mcp`, validador do token com o portão a cada requisição (inclusive corte de "Sair de todos os aparelhos", impressão da senha e liberação nos três níveis), metadado RFC 9728 nas duas URLs, `/mcp` nas listas dos dois listeners (8), `StreamableHttpTransport`, sessão MCP, nginx (3.3), limites de taxa, log `mcp`, rota com nome próprio para a auditoria, chave `conector_ia` no catálogo, `quem_sou_eu`, teste de arquitetura da lista fechada e das anotações | 1, 3 (o 4 para cliente real) | ALTO, com re-revisão | 3 dias |
| 6 | `conector-leitura-pasta` | Registro de ferramentas compartilhado com o chat (6.8); `buscar_pastas`, `ler_pasta` (menos publicações, timeline e controladoria), `ler_agenda` (com `minhas_metas` e a porta do aceite na guarda da consulta de prazos, 14.i); `LerAndamentoDaPastaUseCase`; segredo nas listas; máscara sempre ligada (6.2.6); tabela de aba e chave (6.6) com o teste que a percorre | 5 | MÉDIO | 4,5 dias |
| 7 | `conector-leitura-documentos` | `ler_documentos_e_modelos` com PDF e imagem (porta do aceite do conector, barreira da aba Documentos); `ler_pasta` parte `publicacoes`, sem marcar lida; `ler_processos` (buscar, detalhar, para vincular), com UseCase de leitura e movimentações paginadas | 6 | MÉDIO | 3,5 dias |
| 7b | `conector-leitura-texto` | Peça do editor, DOCX, DOC, ODT, RTF, TXT, CSV, XML e KML como texto (6.5): método novo no leitor, `ExtratorDeTextoDocx` num lugar comum | 7 | MÉDIO | 2 dias |
| 7c | `conector-leitura-formatos` | XLSX, XLS, ODS, PPTX, ODP, EML, ZIP, `.p7s`, TIFF e HEIC (6.5); a extensão de imagem nova é arquivo quente e passo de deploy | 7b | MÉDIO | 2,5 dias |
| 8 | `conector-telas` | Pela convenção do sistema (14.e): "Conexões de I.A." no Meu Perfil (listar e revogar); liberar por escritório na aba "Configurações da I.A." do Master; ligar e dar o aceite do conector, para o administrador do escritório | 2, 3 | MÉDIO | 2,5 dias |
| 9 | `conector-confirmacao` | Proposta (`conector_ia_proposta`), `confirmar_acao`, elicitation pelo `ClientGateway::elicit`, `_meta` de interação, escopo `mcp:escrita`, varredura das vencidas (6.7) | 5 | ALTO, com re-revisão | 2,5 dias |
| 10 | `conector-escrita-pasta` | `atualizar_pasta`, `mudar_andamento`, `registrar_na_pasta`, `prazos_e_metas_da_pasta` (com o `CriarMetaUseCase` extraído do `TarefaController`) | 6, 9 | ALTO | 4 dias |
| 11 | `conector-escrita-clientes` | `ler_clientes` (UseCases de leitura extraídos) e `atualizar_cliente` (cadastro em lote por proposta, completar, contato, vincular extraído, principal, ligar documento) | 6, 9 | ALTO | 4 dias |
| 12 | `conector-escrita-documentos` | `documentos_e_pecas` e `financeiro_da_pasta` | 7, 9 | MÉDIO | 3,5 dias |
| 13 | `conector-escrita-processos-agenda` | `processos_da_pasta`, `gravar_na_agenda` (com o teto da Agenda) e a consulta ao CNJ com UseCase extraído | 7, 9 | MÉDIO | 3,5 dias |
| 14 | `conector-legado-restante` | UseCases extraídos de controller e as ações que dependem deles: relatórios do Expediente, timeline e controladoria da pasta, documentos do cliente (lista e exclusão), excluir cliente, excluir processo, renomear documento do Financeiro, lista de modelos de checklist | 11, 12, 13 | MÉDIO | 3 dias |
| 15 | `conector-abertura` | Guia passo a passo do usuário (Claude e ChatGPT, com a orientação sobre "sempre permitir"), novidades, `mudancas/`, ajuste de limites depois do teste com os clientes reais (smoke do Samuel) | 1 a 13 | BAIXO | 1,5 dia |

**Total: cerca de 49,5 dias de trabalho** de uma sessão (o meio dia a mais é a leitura de Minhas Metas no PR 6, 14.i). Com
sessões em paralelo:

- **Caminho crítico até a abertura: 1 → 3 → 5 → 6 → 7 → 7b → 7c → 15, cerca de 23 dias.** O PR 2 corre ao lado do 1; o 4
  e o 8 ao lado do 5; o 9 ao lado do 6; os PRs 10 a 13 terminam até o dia 20,5. O PR 14 termina perto do dia 23,5 e não
  segura a abertura: **a abertura do piloto sai sem o PR 14**, ou seja, sem excluir cliente, excluir processo e os
  documentos do cliente (lista e exclusão), nem o resto da linha 14 da tabela; essas ações entram depois, quando o PR 14
  for integrado.
- **Piloto com os três pedidos do Samuel** (A: pasta e esteira; B: documentos; C: cliente), no escritório do Farlei: depois
  dos PRs 1 a 7b, 8, 9, 10 e 11 (sem o PR 14), **cerca de 19 dias** (A e C perto do dia 17,5; B com PDF e imagem no dia 17
  e com Word e peça do editor no dia 19). Com uma sessão só, o mesmo piloto leva cerca de 35,5 dias.
- A versão de 08/11 somava 28 dias e 18 de caminho crítico. A diferença é o escopo novo (14.b): de 17 ferramentas de uma
  ação cada para as 187 ações do usuário comum nos quatro módulos, menos as que a 6.1 deixa fora; mais a confirmação (PR 9),
  o teto (PR 1), a liberação (PRs 3, 5 e 8) e os tipos de documento (PRs 7b e 7c).

Regras do plano:

- **Uma migration só, no PR 2**, com as 7 tabelas de 13.2 (as decisões 14.a, 14.c e 14.g já estão tomadas). Número dado pelo
  Coordenador, respeitando a regra de uma migration por vez entre os PRs abertos. Se um PR seguinte descobrir que precisa de
  coluna nova, pede ao Coordenador antes.
- Arquivos quentes (avisar o Coordenador no início de cada PR que os toca): `composer.json`/`composer.lock` (PR 2),
  `security.yaml` (PR 5), `framework.yaml` (PR 5), `nginx.prod.conf` (PR 5), a migration (PR 2), o `Dockerfile` da imagem PHP
  (PR 7c).
- Os PRs 1, 3, 5 e 9 mexem em identidade ou na garantia da confirmação: revisão contra esta spec e re-revisão antes do
  seguinte. Os de gravação (10 a 14) são revisados contra a tabela da 6.6 e a lista de exclusões da 6.7.
- **Liberação no piloto:** a chave geral `conector_ia` já nasce ligada (14.a, nível 1); o Master libera só o escritório do
  Farlei (o nível 2, que nasce falso) e o administrador dele liga o conector e dá o aceite. Abrir a outro escritório espera
  o texto dos advogados (14.a).

### 13.2 Tabelas novas

Sem número de migration: o Coordenador numera. Guardar só o **hash** de código, token e id de proposta, nunca o valor. Os
nomes finais dependem de o bundle aceitar gerenciadores próprios (3.2); a recomendação é gerenciadores próprios, para ter
escritório e consentimento.

| Tabela | Para quê | Colunas principais | Escritório |
|---|---|---|---|
| `conector_ia_cliente` | o app de I.A. registrado (CIMD ou DCR) | `identificador` (único; a URL no CIMD), `tipo` (`cimd`, `dcr`, `pre_registrado`; o último fica sem uso no piloto, 14.d), `nome`, `redirect_uris` (JSON), `segredo_hash` (só pré-registrado confidencial), `tenant_id` (nulo, salvo pré-registrado de um escritório), `metadados_buscados_em`, `metadados_validos_ate`, `ativo`, `criado_em`, `ultimo_uso_em` | **global por natureza** (o ChatGPT é o mesmo cliente para todos os escritórios): exceção declarada à regra "toda consulta filtra tenant"; nenhum dado de escritório passa por ela |
| `conector_ia_codigo` | código de autorização | `identificador_hash`, `cliente_id`, `user_id`, `tenant_id`, `redirect_uri`, `code_challenge`, `escopos`, `resource`, `expira_em` (até 10 min), `usado_em` | sim |
| `conector_ia_token` | token de acesso (1 hora, 14.c) | `identificador_hash` (jti), `cliente_id`, `user_id`, `tenant_id`, `consentimento_id`, `escopos`, `audiencia`, `autorizado_em` (instante do login que originou a cadeia), `impressao_da_senha` (HMAC do hash da senha), `criado_em`, `expira_em`, `revogado_em`, `ultimo_uso_em` | sim |
| `conector_ia_renovacao` | token de renovação, com rotação (14.c) | `identificador_hash`, `token_id`, `autorizado_em` (copiado na rotação), `impressao_da_senha`, `expira_em` (limite absoluto: 7 dias do `autorizado_em`), `revogado_em`, `substituido_por` | sim (pelo token) |
| `conector_ia_consentimento` | a "conexão" que a pessoa vê e revoga | `user_id`, `tenant_id`, `cliente_id`, `escopos`, `concedido_em`, `revogado_em`, `ultimo_uso_em`; uma ativa por pessoa, escritório e cliente; `Auditavel` (conceder, revogar) | sim |
| `conector_ia_escritorio` | liberação do Master e aceite do escritório (14.a) | `tenant_id` (único), `liberado_pelo_master` (padrão falso), `ligado` (padrão falso), `aceite_em`, `aceite_versao`, `aceito_por_user_id`; `Auditavel` (quem liberou, ligou ou aceitou, e quando, fica no `audit_log`, como na `ConfiguracaoDeInteligencia`) | sim |
| `conector_ia_proposta` | a ação que espera confirmação (6.7) | `identificador_hash`, `user_id`, `tenant_id`, `consentimento_id`, `cliente_id`, `ferramenta`, `acao`, `dados` (JSON, zerado ao decidir ou vencer), `dados_hash`, `resumo` (zerado junto), `estado` (`pendente`, `confirmada`, `cancelada`, `expirada`), `resultado` (ids, sem texto), `criada_em`, `expira_em` (10 min), `decidida_em` | sim |

A chave geral `conector_ia` não precisa de tabela: entra no `CatalogoDeRecursos` e usa o registro que o `RecursosDoSistema`
já tem. Toda tabela com escritório entra no `PurgarEscritorioUseCase`.

---

## 14. Decisões do dono

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

**Decidido em 07, 08 e 09/11/2026:**

**a) Dado pessoal saindo para o provedor do modelo (LGPD). Decidido em 07 e 08/11/2026: opção 2, com ajuste.**

Pelo conector, o provedor é o que a pessoa escolheu (Anthropic ou OpenAI), pela conta dela e sob o contrato dela; o BlueJus
não tem contrato, ZDR nem DPA com esse provedor, e um plano de consumidor pode usar a conversa para treino, conforme a
configuração da conta. O aceite da BlueJus I.A. não cobre isso, porque o texto dele fala do provedor que o BlueJus contrata.

Como fica, no mesmo molde da BlueJus I.A. (`DisponibilidadeDeInteligencia`, três níveis, todos conferidos na autorização e a
cada requisição):

1. **Chave geral** do Master: `conector_ia` no `CatalogoDeRecursos`, que desliga o conector de todos de uma vez. **Nasce
   ligada**: sem registro, `RecursosDoSistema::estado` devolve ativo (`app/src/Master/Service/RecursosDoSistema.php:55`),
   como toda chave do catálogo. O que nasce falso é o nível 2.
2. **Liberação por escritório, pelo Master**, quando ele quiser, na aba "Configurações da I.A." do painel Master, ao lado da
   liberação da BlueJus I.A. (`master_ia_escritorio`): `conector_ia_escritorio.liberado_pelo_master`, padrão falso, como o campo `liberadaPeloMaster` da
   `ConfiguracaoDeInteligencia` (`ConfiguracaoDeInteligencia::isLiberadaPeloMaster`). Sem a liberação, o aceite do administrador não basta.
3. **Ligado pelo administrador do escritório**, com um **aceite próprio do conector**, com versão (enum novo, nome sugerido
   `VersaoDoAceiteDoConector`; a versão 1 já cobre documentos, porque a 14.f os inclui). O texto diz que o dado vai para a
   conta de I.A. de cada pessoa e recomenda plano empresarial ou sem treino. A guarda é a mesma da tela da BlueJus I.A.
   (`admin.inteligencia.manage`).

Piloto no escritório do Farlei. Regras de saída da 6.2, com a máscara de CPF, CNPJ, telefone e e-mail **sempre ligada** no
texto livre, sem seguir a preferência da BlueJus I.A. (6.2.6). O conector não reaproveita o aceite da BlueJus I.A. A chave é do
escritório, não um filtro por pessoa, então não contraria a decisão 1. O texto do aceite e a linha nos termos de uso vão para
o pacote jurídico (`bluejus-coordenacao/pacote-juridico.md`) sem travar a implementação; abrir a outro escritório espera o
texto dos advogados.

**b) Escopo. Decidido em 08/11/2026, e muda a recomendação antiga** (que era só as duas ferramentas de minuta).

"Criar peças, minutas" foi só exemplo, não uma função especial. O escopo do piloto é **tudo o que os módulos Pastas, Agenda,
Clientes e Processos fazem, menos as funções de administrador** (inventário, seção 5), só no escritório piloto. Com pedido
explícito e prioritário de três coisas:

- **A.** Ler e atualizar tudo da pasta, inclusive a esteira de andamento (`MudarFaseDaPastaUseCase`; mudar a fase pode marcar
  o contrato como assinado ou reaberto): `ler_pasta`, `atualizar_pasta`, `mudar_andamento` e as demais da 6.6.
- **B.** Ler os documentos da pasta: `ler_documentos_e_modelos`, com os tipos da 14.f.
- **C.** Cadastrar cliente e completar os dados: `ler_clientes` e `atualizar_cliente`.

Como fica: 17 ferramentas (6), plano de 17 PRs (13.1) e teto próprio (6.3), que tira pelo conector os dois bypasses, como
o inventário classifica (seção 5, última linha). Para o Farlei, que é Master, isso quer dizer: pelo conector ele vê o que o
perfil dele no escritório vê, **sem nenhuma pasta trancada**. Master e primário nunca estão na lista do cadeado (a grade os
exclui) e o pedido de acesso não fura o cadeado, então pelo conector não há caminho para elas; pela tela, continuam
abertas (6.3, item 3). É aceito por ser o lado seguro.

**A abertura do piloto sai sem o PR 14** (13.1): excluir cliente, excluir processo e os documentos do cliente (lista e
exclusão), além do resto da linha 14 (relatórios do Expediente, timeline e controladoria da pasta, renomear documento do
Financeiro, lista de modelos de checklist), entram depois.

**c) Como os 7 dias funcionam. Decidido em 08/11/2026: opção 2.** Token de acesso de 1 hora, com renovação e rotação até
completar 7 dias da autorização. A pessoa faz login uma vez por semana, e um token vazado vale 1 hora. O metadado anuncia
`refresh_token` e `offline_access` (3.4, 5.1); a tabela `conector_ia_renovacao` entra na migration do PR 2.

**d) Clientes atendidos. Decidido em 08/11/2026: opção 1.** Claude e ChatGPT como conector personalizado (a pessoa cola a URL
`https://bluejus.com.br/mcp`). Gemini fica fora no piloto. A lista de domínios do CIMD e dos `redirect_uris` do DCR fica em
`claude.ai` e `chatgpt.com`, mais loopback para o Claude Code (3.4). Sem cliente pré-registrado e sem diretórios no piloto.

**e) Telas sem desenho. Decidido em 08/11/2026: opção 2.** As quatro telas (a de autorização, no PR 3, e as três do PR 8:
"Conexões de I.A." no Meu Perfil, a liberação por escritório na aba "Configurações da I.A." do Master e a tela em que o
administrador do escritório liga o conector e dá o aceite) saem pela convenção do sistema, sem esperar desenho. Se um
desenho vier depois, troca-se a tela.

**f) Documentos que saem. Decidido em 08/11/2026:** todos os tipos que a aba Documentos aceita, menos áudio e vídeo. Como cada
tipo sai está na tabela da 6.5.

**g) Confirmação no chat. Decidido em 08/11/2026.** Cadastrar cliente e **qualquer exclusão** (gravar por cima inclusive,
6.6) só acontecem depois de uma confirmação no próprio chat; cadastro em massa vale uma confirmação para a lista toda. Como
o servidor garante isso e o que não deu para confirmar em fonte oficial: 6.7.

**Limite, dito com honestidade (revisão de 09/11/2026):** o servidor garante que só executa com uma confirmação presa à
proposta (mesma pessoa, escritório, consentimento e cliente OAuth, uso único, até 10 minutos). Ele **não prova que uma
pessoa leu**. Com "Permitir sempre" no claude.ai ou "lembrar" no ChatGPT, o próprio modelo pode chamar `confirmar_acao` sem
pergunta à pessoa. A confirmação mais forte é a elicitation, onde o cliente tiver (6.7, item 2). Esse risco residual fica
aceito no piloto, está na seção 11, e o guia do usuário (PR 15) pede para não marcar essas opções; a confirmação não é
absoluta.

**h) Partes da pasta que são de outro módulo (inventário, seção 1.13). Decidido em 09/11/2026.**

- **Entram:** criar meta para a pasta (`tarefa_criar_para_pasta` e a análise do prazo, em `prazos_e_metas_da_pasta`) e ler
  as publicações do Diário da pasta (em `ler_pasta`), **sem marcar lida**; e, pela 14.i, ler a lista de Minhas Metas.
- **Ficam fora:** a BlueJus I.A. dentro da pasta; todo envio ao cliente pelo Push (e-mail, link público, envio automático); o
  encaminhar pela Central; a Cobrança. O resto do Push que a seção 1.13 lista (marcar lida, monitoramento, buscar agora,
  "Avisar quando chegar", vincular publicação) não foi posto dentro e fica fora.
- **A mesma regra vale para qualquer ação dos quatro módulos que mande algo ao cliente ou a terceiro** (ex.: escolher os
  avisos por e-mail do cliente), **sem exceção para o Push automático**: `atualizar_cliente` recusa gravar o e-mail de
  cliente que já tem o envio automático ligado, e a resposta diz para fazer pela tela (6.6). A **única exceção** é o Google
  Agenda: compromisso gravado vai para a agenda Google de quem criou e dos participantes que ligaram a conta, que são da
  equipe (`gravar_na_agenda`), e a resposta da ferramenta avisa.

**Decididas em 09/11/2026 (eram as duas perguntas em aberto; ficam as opções, para registro):**

**i) Ler a lista de Minhas Metas. Decidido em 09/11/2026: entra.**

`ler_pasta` lê as metas **de uma pasta**. A lista pessoal de Minhas Metas (todas as metas da pessoa, de todas as pastas, com
prazo) é do módulo Tarefas, fora dos quatro módulos, e a decisão h) só pôs dentro criar meta para a pasta. O Chat I.A já
tem essa leitura pronta e sem sessão (`ConsultarPrazosEMetasUseCase`, 4.2).

- Opção 1: fica fora. O assistente acha prazos pela agenda (`ler_agenda`, com prazos) e pela pasta.
- Opção 2: entra só a leitura, como parte `minhas_metas` de `ler_agenda`, pelo UseCase do chat (meio dia no PR 6). Concluir,
  despachar e as demais ações de meta continuam fora.

**Decidido em 09/11/2026: entra** (opção 2, a recomendada). "Acompanhar prazos" estava no pedido original (seção 2), o
UseCase está pronto e testado, e é só leitura. Como fica: ação `minhas_metas` da `ler_agenda` (6.4), sem ferramenta nova
(continuam 17), com a guarda do aceite virando porta; linha na tabela da 6.6 (chave `metas`); teste 12.26; PR 6.

**j) Enviar arquivo para a pasta. Decidido em 09/11/2026: fica fora do piloto.**

Enviar documento (da pasta, do Financeiro, do cliente) é função do módulo Pastas e, pela decisão b), estaria dentro. Mas o
conector teria de receber o arquivo dentro do argumento da ferramenta, em base64, com o teto de 4 MiB do corpo da requisição
(3.1), e não há fonte oficial de que Claude e ChatGPT repassem um anexo da conversa assim.

- Opção 1: fica fora no piloto. A pessoa envia o arquivo pela tela; o conector lê, organiza, move e renomeia depois. Peça
  redigida pelo assistente já entra como documento (`escrever_peca`).
- Opção 2: entra com arquivo em base64, até cerca de 3 MB, pelo `UploadPecaUseCase` (1,5 dia no PR 12), sabendo que pode não
  funcionar nos clientes.

**Decidido em 09/11/2026: fica fora do piloto** (opção 1, a recomendada). O piloto mostra se faz falta; se fizer, a opção 2
entra num PR próprio depois de testada nos dois clientes.
