# Conector de I.A. (MCP remoto com OAuth 2.1) para todos os escritórios

**Data:** 11/08/2026 (desenho original). **Revisão:** 08/11/2026, conferida contra o master `a53a77b1c` (v2.42.0).
**Escopo e decisões do dono:** 09/11/2026, conferidos contra o master `940e82dfd`, com o inventário
`bluejus-coordenacao/conector-inventario-tudo.md`. **Correções da revisão do PR #699:** 09/11/2026, no mesmo master.
**Revisão:** 16/11/2026, conferida contra o `origin/master` atual (`6ba739883`). Motivo: revisão de protocolo (MCP, OAuth,
Claude, ChatGPT e bibliotecas) e revisão de código (código, multi-tenant e tabelas), com as decisões do Coordenador na
seção 14 (`bluejus-coordenacao/revisao-spec-mcp-protocolo-1611.md` e `revisao-spec-mcp-codigo-1611.md`).
**Risco:** **ALTO**. Mexe em identidade (`User`/`Tenant`) e abre um caminho novo de acesso aos dados de todos os
escritórios, fora do navegador. Pelo ciclo do projeto: spec (este documento), revisão contra a spec e **re-revisão antes de
seguir** em cada PR de identidade.
**Status:** pronto para o PR 1 de código (09/11/2026, revisto em 16/11/2026); decisões do dono e do Coordenador na seção
14. Não existe código, tabela nem migration desta feature.

**Predecessor:** [MCP de investigação em produção (v1)](../superpowers/specs/2026-08-10-mcp-investigacao-prod-design.md),
em produção; a seção 10 dele previa esta etapa.
**Relacionadas:** `chat-ia-cadastro.md`, `chat-ia-prazos-e-metas.md`, `chat-pagina-inteira.md`, `designer-central-ia.md`
(D9 "token pessoal por usuário" e Fase 4 "conector MCP": esta spec é o caminho delas), `ia-anthropic-ligar.md`,
`inteligencia-provedor-anthropic.md`, `pasta-cadeado.md`, `docs/AUTORIZACAO.md`.

Arquivo:linha citados foram conferidos em 08/11/2026 no commit `a53a77b1c` e, nas seções 6, 13 e 14, em 09/11/2026 no
`940e82dfd`. Páginas da web foram lidas em 07, 08 e 09/11/2026; quando a página mostra data, ela vem ao lado do link.
Em 16/11/2026, as linhas que a revisão de código achou deslocadas foram atualizadas para o `6ba739883`, e as páginas da
revisão de protocolo foram lidas nesse dia.

---

## 0. O que mudou de 11/08 para cá

**Revisão de 16/11/2026 (protocolo e código), em resumo:**

- **Servidor de autorização próprio**, sem `league/oauth2-server-bundle` e sem `league/oauth2-server`: token de acesso e de
  renovação **opacos**, guardados só como SHA-256; sem JWT, sem par de chaves, sem `/.well-known/jwks.json` (3.2, 3.3, 8).
  PKCE só S256 e obrigatório, `resource` obrigatório e conferido, `iss` em toda resposta de autorização,
  `token_endpoint_auth_methods_supported: ["none"]` (3.4, 9.6).
- **`mcp/sdk` 0.10.0** no PR 2 (o lock tem 0.7.1): sem sessão MCP por padrão, as três versões do protocolo no mesmo
  endpoint, `ScopePolicy` para o 403 `insufficient_scope` antes do SDK, `NullLogger` no SDK; a v1 STDIO é ajustada no mesmo
  PR. Saem o PR próprio da 2026-07-28 e o `Psr16SessionStore` (3.1, 3.5, 5.1, 8).
- **Piloto só com CIMD**: o registro do cliente entra no PR 3 e o PR 4 some; DCR fora do piloto; regra dura pela lista
  exata de `redirect_uri`, hosts de `client_id` em configuração (3.4, 13.1).
- **Elicitation fora do piloto**: a confirmação é a proposta mais `confirmar_acao` (6.7). O registro de ferramentas
  compartilhado com o chat fica para depois do piloto (6.8).
- **PR 1**: o portão fixa o escritório no `TenantContext` sem sessão, por um método que só ele chama, e a auditoria sai
  certa sem escopo próprio (4.1, 4.3); o portão não dá o passe do Master; o modo do conector trata o perfil de sistema
  como tendo toda permissão menos `admin.*`, sujeito ao cadeado e às abas (6.3).
- **Tabelas**: sete, com a cadeia (`conector_ia_concessao`) e um token único com `tipo` (acesso ou renovação), janela de
  cerca de 60 s na rotação, `tenant_id` em toda tabela com dado de escritório, FKs em cascata e comando de faxina agendado
  (9.1, 13.2).
- **Telas e ordem**: comando de console do Master para liberar o escritório já no PR 3; CSRF e escritório conferido no
  servidor na tela de consentimento; ponto de smoke do Samuel depois do PR 5 (9.4, 13.1).
- **Sem estimativa em dias** no plano: ficam dependências, risco e o caminho crítico como sequência de PRs (13.1).

| Ponto | Em 11/08/2026 | Em 08 e 09/11/2026 |
|---|---|---|
| Objetivo | ler e operar o que a conta permite | **tudo o que a pessoa faz em Pastas, Agenda, Clientes e Processos, menos funções de administrador**, com três pedidos à frente: pasta e esteira, documentos, cadastro de cliente (seção 2, 14.b) |
| `TenantFilter` | falha aberto | **continua** falhando aberto; há três listeners de tenant no `kernel.request` e nenhuma trava fail-closed (4.1) |
| Tenant explícito sem sessão | não existia | é o padrão do worker da I.A., do Chat I.A e do Agente de Pastas; o Agente foi escrito pensando neste conector (4.2) |
| `PermissionChecker` | 4 métodos | 8 públicos, cache por vínculo e cadeado da pasta; `canActOnResource` saiu em 30/06; `resources.*` está vivo (5) |
| Guardas em listener HTTP | não citadas | cadeado, aba oculta, pasta excluída, recurso desligado pelo Master e barreiras do escritório: o conector pula todas e tem de refazer (5.2) |
| Ferramentas | 5 de leitura e 3 de escrita, desenhadas sem base | **17** (7 de leitura, 9 de gravação, 1 de confirmação) sobre as 187 ações do inventário, com teto próprio contra o bypass do Master e do primário (6, 6.3) |
| Exclusão e cadastro | escrita direta | só com uma confirmação presa à proposta, de validade curta e uso único, conferida de novo no servidor; gravar por cima (substituir um valor que já existe) conta como exclusão, em toda ferramenta; preencher vazio, acrescentar e marcar ou desmarcar são diretos (6.6, 6.7) |
| Documentos | PDF e imagem | todos os tipos da aba Documentos, menos áudio e vídeo (6.5) |
| Cliente e Processo | "legado sem UseCase" | `Processo` está no domínio; Cliente tem 8 UseCases; Tarefa continua legado (7) |
| nginx | "chega ao Symfony sem mudança" | **errado já em agosto**: o `location ~ /\. { deny all; }` do HTTPS barra `/.well-known/oauth-*` (3.3) |
| `mcp/sdk` | 0.7.0 | 0.7.1 instalada; o DCR por delegação existe desde a 0.5.0 (`CHANGELOG.md`, "Add OAuth 2.0 Dynamic Client Registration middleware"), e a versão instalada também tem elicitation do lado do servidor e anotações `readOnlyHint`; continua sem servidor de autorização (3.1). **16/11:** o PR 2 fixa a 0.10.0 (10/11/2026): 2026-07-28 desde a 0.8.0, sem middleware de registro desde a 0.9.0, `ScopePolicy`, sem sessão por padrão (3.1) |
| `symfony/mcp-bundle` como servidor de autorização | proposta aberta | PR fechado sem merge em 12/07/2026; segue o `league` (3.2). **16/11:** servidor de autorização próprio (3.2) |
| `league/oauth2-server-bundle` | "testado até Symfony 8.0" | v2.0.0 (out/2026) pede Symfony `^7.4` ou `^8.1` e PHP `^8.2`: serve (3.2). **16/11:** sai, e a biblioteca `league/oauth2-server` também: o que ela daria são poucas linhas, e quatro padrões dela contrariam a spec (3.2) |
| Especificação MCP | 2025-06-18, DCR recomendado | a atual é **2026-07-28**: CIMD é o preferido, DCR está **deprecated**, protocolo sem estado (3.4, 3.5) |
| Clientes | só o Claude em mente | Claude, ChatGPT e Gemini pesquisados; cada um aceita um jeito de registro (3.4); o piloto atende Claude e ChatGPT (14.d) |
| Auditoria | "usar o domínio Auditoria" | o `AuditLogSubscriber` tira o escritório da sessão: com bearer, grava sem escritório se nada for feito (4.3). **16/11:** resolvido pelo portão, que fixa o escritório no `TenantContext` (4.1) |
| Decisões | 2 tomadas | 12 tomadas (seção 14); **16/11:** mais as do Coordenador sobre a revisão (14, "Decidido em 16/11/2026") |

---

## 1. O que existe hoje, e por que não serve

A v1 está em produção: `app/src/Mcp/` (`Command/McpServerCommand.php`, `Service/ConexaoLeitura.php`,
`Tool/ConsultarSqlTool.php`, `Tool/DescreverEsquemaTool.php`). O comando `mcp:server` (`McpServerCommand.php:30`) fala MCP
por **STDIO**, lançado por `ssh bluejus 'docker exec -i -w /var/www/app jusprime_php_prod php bin/console mcp:server'`, com
duas ferramentas: `consultar_sql` (SQL livre) e `descrever_esquema`. O `mcp/sdk` está fixado em `0.7.1`
(`app/composer.json:32`); o PR 2 sobe para a `0.10.0` e ajusta a v1 às quebras de namespace (3.1).

Ela foi desenhada para **um operador: o dono**. Três propriedades a tornam inadequada para outros usuários, e nenhuma é
acidental:

1. **`consultar_sql` lê o banco inteiro.** Sem filtro de tenant, sem `PermissionChecker`, sem UseCase. Decisão consciente da
   spec da v1. Com um segundo usuário conectado, deixa de ser recurso e vira vazamento de dado de todos os escritórios.
2. **O transporte é SSH como `root` na VPS.** Quem tem a chave é dono da máquina. Não é distribuível.
3. **Não há identidade.** O servidor não sabe quem pergunta, só que alguém alcançou o container.

Duas portas novas surgiram depois de agosto, e também não servem como estão:

- **Agente de Pastas** (`/api/agente/*`, `app/src/Inteligencia/Controller/AgenteDePastasController.php`, 14/10/2026): JSON de
  leitura para o Designer. Autentica por **sessão** (firewall `main`, `app/config/packages/security.yaml:88`), tira o
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
   │ 4. registro: client_id = URL do CIMD (único caminho no piloto; DCR e pré-registrado fora, 3.4)
   │ 5. GET /oauth/authorize (PKCE S256, resource=)  → login + escolha do escritório + consentimento (+ iss na volta)
   │ 6. POST /oauth/token (form-urlencoded)          → token opaco preso ao resource https://bluejus.com.br/mcp
   │ 7. POST /mcp (Authorization: Bearer <token>)    → ferramentas
   ▼
nginx (bluejus.com.br, TLS)                ← precisa de dois ajustes (3.3)
   ▼
Symfony: a mesma aplicação, dois papéis
   ├── Servidor de autorização → nosso, sem biblioteca OAuth (CIMD, PKCE, resource, iss, escritório, token opaco)
   └── Servidor de recurso (MCP) → mcp/sdk 0.10.0 StreamableHttpTransport, sem sessão + validador nosso
   ▼
Portão do conector: usuário, escritório, vínculo e barreiras conferidos; filtro de tenant ligado e conferido (4.1)
   ▼
UseCases e serviços existentes, com tenant e permissão explícitos
```

### 3.1 O que o `mcp/sdk` 0.10.0 dá, e o que falta

Instalado hoje: `mcp/sdk` **0.7.1** (`app/composer.json:32`, `app/composer.lock`), três versões atrás. **O PR 2 fixa a
`0.10.0`** (10/11/2026), versão exata, como hoje. Experimental até a 1.0, segundo os mantenedores. Fontes:
https://repo.packagist.org/p2/mcp/sdk.json e https://raw.githubusercontent.com/modelcontextprotocol/php-sdk/v0.10.0/CHANGELOG.md
(lidas em 16/11/2026).

O que mudou desde a 0.7.1 e pesa aqui:

- 0.8.0: a versão `2026-07-28` do protocolo, com o transporte HTTP sem estado, e as duas eras do protocolo servidas no mesmo
  endpoint.
- 0.9.0: saem `ClientRegistrationMiddleware`, `ClientRegistrarInterface` e `OAuthProxyMiddleware` (o SDK fica só como
  servidor de recurso, ADR 0002); o `AuthorizationMiddleware` ganha a `ScopePolicy`, que responde `403 insufficient_scope`
  por método e por ferramenta antes do despacho (5.1).
- 0.10.0: clientes da era `initialize` são servidos **sem sessão** por padrão ("a scaled-out server needs no shared session
  store"), e `GET` e `DELETE` dão 405; exige PHP 8.2 e quebra namespaces (`Mcp\Server` vira `Mcp\Server\Server`, entre
  outros). A v1 STDIO (`app/src/Mcp/`: `McpServerCommand` e as duas ferramentas) é ajustada no mesmo PR 2.

**O SDK dá:**

- `StreamableHttpTransport`: Streamable HTTP sobre PSR-7/PSR-15, com teto de corpo (`maxBodyBytes`, 4 MiB na 0.7.1;
  conferir o valor na 0.10.0 no PR 2).
- `AuthorizationMiddleware`: responde 401/403 com `WWW-Authenticate`; recebe um validador de token, que será nosso (o token
  é opaco, 3.2), e a `ScopePolicy` (5.1).
- `CorsMiddleware`, `DnsRebindingProtectionMiddleware` e `ProtocolVersionMiddleware`, restritivos por padrão: sem
  `Access-Control-Allow-Origin`, proteção de rebinding pensada para localhost (8).
- `ProtectedResourceMetadata`, que serve só no caminho derivado do recurso (`/.well-known/oauth-protected-resource/mcp`):
  por isso o metadado do recurso sai de um controller nosso, nas duas URLs (3.3).
- `ToolAnnotations` com `readOnlyHint` e `destructiveHint` (o ChatGPT e o Gemini Enterprise usam para pedir confirmação,
  6.7).
- As versões `2025-06-18`, `2025-11-25` e `2026-07-28` no mesmo endpoint (3.5).
- O que o SDK **não** usa no piloto: sessão MCP (nenhum `SessionStore`), elicitation e sampling (sem sessão, os pedidos ao
  cliente lançam `SessionRequiredException`; 6.7) e os validadores de JWT por JWKS (o token é opaco, 3.2).

**Falta, e é trabalho nosso:**

- **O servidor de autorização inteiro** (3.2). O `adr/0001-oauth-authorization-server-out-of-scope.md` (aceito em
  15/06/2026) diz que o SDK não emite, assina, guarda nem renova token, não tem login nem consentimento.
- CIMD (é papel do servidor de autorização).
- A ponte PSR-7 e HttpFoundation: `symfony/psr-http-message-bridge` não está instalado; entra no PR 2. `guzzlehttp/psr7`
  2.13.2 e `php-http/discovery` já estão no lock, com fábricas PSR-17 ligadas em `app/config/packages/http_discovery.yaml`.
- O logger do SDK: na 0.7.1 ele escreve no log a mensagem inteira, os argumentos e o resultado da ferramenta
  (`src/Server/Protocol.php:111`, `:179`, `:263`; `src/Server/Handler/Request/CallToolHandler.php:63`, `:104`). O SDK
  recebe um `NullLogger`; o log `mcp` do conector é escrito só pelo portão (6.2, regra 9).

### 3.2 O servidor de autorização é o próprio BlueJus

**Decisão (16/11/2026, 14): servidor de autorização próprio dentro da aplicação Symfony, sem `league/oauth2-server-bundle`
e sem `league/oauth2-server`.** Substitui a decisão de 08/11, que era o bundle do `league`.

O motivo de ficar dentro do BlueJus continua sendo identidade: `User`, `UserTenant` e `Tenant` já vivem aqui, com senha,
convite, recuperação, vínculo por escritório, suspensão e quarentena. Um IdP externo (Keycloak, Auth0, Entra) exigiria um
segundo diretório de usuários e federação: mais peças, mais estados inconsistentes, e um lugar novo para o vínculo usuário
e escritório divergir.

**Por que sem o `league`** (revisões de 16/11/2026; fontes: `src/Grant/AuthCodeGrant.php` e
`src/Entities/Traits/AccessTokenTrait.php` do `league/oauth2-server` 9.4.1 e `src/DependencyInjection/Configuration.php` do
bundle v2.0.0):

- PKCE: o `AuthCodeGrant` registra sempre o `PlainVerifier`, e `code_challenge_method` ausente vira `plain`. Esta spec exige
  S256 (9.6).
- Audiência: o token sai com `aud` igual ao `client_id`, não `https://bluejus.com.br/mcp`; `resource` (RFC 8707) e `iss`
  na resposta de autorização (RFC 9207) não existem lá.
- Exige par de chaves RSA e chave de cifra, com o código e a renovação viajando cifrados, e traz `lcobucci/jwt`,
  `nyholm/psr7` (um segundo PSR-7 ao lado do `guzzlehttp/psr7`), `symfony/password-hasher` e `ext-openssl`.
- O bundle aceita gerenciadores próprios (`persistence.custom`, seis, inclusive `device_code_manager`), mas os modelos dele
  não têm escritório, consentimento, `autorizado_em` nem impressão da senha.

Como a spec já exige conferência no banco a cada requisição (9.2), CIMD, `resource`, `iss`, escritório e consentimento
próprios, o que sobraria da biblioteca é a troca do código e a rotação da renovação: poucas linhas sobre as tabelas da 13.2.

**Como fica:**

1. **Só cliente público** no piloto (`token_endpoint_auth_methods_supported: ["none"]`, 3.4), registrado por **CIMD** (3.4).
2. **Token opaco**: token de acesso e de renovação são 256 bits aleatórios; o banco guarda só o SHA-256
   (`conector_ia_token.identificador_hash`, 13.2). Sem JWT, sem par de chaves, sem `/.well-known/jwks.json`, sem passo de
   chave do Samuel (8). Quem confere o token é o banco, a cada requisição (9.2).
3. **PKCE só S256 e obrigatório**: `code_challenge_method` ausente ou diferente de `S256` é recusado (9.6).
4. **`resource`** (RFC 8707) obrigatório e igual a `https://bluejus.com.br/mcp` no `/oauth/authorize` e no `/oauth/token`;
   no `/oauth/token`, igual também ao gravado na concessão. Ausente ou diferente dá `invalid_target` (9.3).
5. **`iss` em toda resposta de autorização** (RFC 9207), de sucesso e de erro, com o texto exato `https://bluejus.com.br`
   (sem barra no fim), o mesmo do `issuer` e do `authorization_servers` (3.4). O ChatGPT o usa para manter estáveis o
   redirect e o `client_id`.
6. **Escritório e consentimento**: a concessão carrega o `tenant_id` escolhido na tela (seção 4) e nasce de um
   consentimento que a pessoa pode revogar (13.2).

**`symfony/mcp-bundle` como servidor de autorização:** o PR `symfony/ai#2135` foi **fechado sem merge em 12/07/2026**. Em
19/06/2026 o autor disse que vai refazer o trabalho como servidor de recurso por padrão e servidor de autorização opcional
sobre o `league/oauth2-server` (https://github.com/symfony/ai/pull/2135). Nada liberado. Como ele seria sobre o `league`,
traria os mesmos padrões acima; não muda a decisão.

### 3.3 Endpoints

| Endpoint | Norma | Quem serve |
|---|---|---|
| `/.well-known/oauth-protected-resource/mcp` e `/.well-known/oauth-protected-resource` | RFC 9728 | nosso: um controller com o JSON fixo nas duas URLs (o `ProtectedResourceMetadata` do SDK 0.10 serve só a primeira, 3.1) |
| `/.well-known/oauth-authorization-server` | RFC 8414 | nosso |
| `/oauth/authorize` | RFC 6749 + 7636 (PKCE S256) + 8707 + 9207 | nosso, no firewall de sessão (é onde a pessoa faz login) |
| `/oauth/token` | RFC 6749, corpo `application/x-www-form-urlencoded` | nosso |
| `/mcp` | Streamable HTTP | SDK |

Sem `/oauth/register` no piloto (DCR fora, 3.4) e sem `/.well-known/jwks.json` (token opaco, sem chave pública, 3.2).

**Correção do nginx (a afirmação de agosto estava errada).** O server HTTPS de `bluejus.com.br`
(`nginx/conf.d/nginx.prod.conf:33-183`) termina com `location ~ /\. { deny all; }` (`:180-182`, presente desde 20/05/2026).
Location por expressão regular vence o `location /` (`:123`), que não tem `^~`. Hoje, portanto, `/.well-known/oauth-*`
recebe **403 do nginx** sem chegar ao Symfony. O `^~ /.well-known/acme-challenge/` só existe nos servers da porta 80
(`:8`, `:209`). Precisa de um `location ^~ /.well-known/oauth- { try_files $uri /index.php$is_args$args; }` antes do bloco
de `:180`, e de um curl nas duas URLs no primeiro deploy.

Segundo ajuste: o `/mcp` responde em SSE quando o SDK escolhe fluxo. A resposta leva `X-Accel-Buffering: no`, como o chat já
faz (`app/src/ChatIa/Controller/ChatIaMensagensController.php:87`).

O PR leva a mudança no `nginx.prod.conf`; aplicar na VPS é passo do Samuel no deploy.

### 3.4 Registro do cliente: CIMD no piloto

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
| ChatGPT (apps, modo desenvolvedor) | Streamable HTTP e SSE | pré-registrado; CIMD, preferido (`client_id` `https://chatgpt.com/oauth/client.json` se o servidor cumprir a RFC 9207, senão `https://chatgpt.com/oauth/{callback_id}/client.json`; autenticação `none` ou `private_key_jwt`); DCR, um registro por conexão | `https://chatgpt.com/connector_platform_oauth_redirect` (com RFC 9207) ou `https://chatgpt.com/connector/oauth/{callback_id}` | `code_challenge_methods_supported` com `S256`, senão "não suportado"; `issuer` idêntico no metadado e em `authorization_servers`; `iss` em toda resposta de autorização, inclusive erro; `resource` vira `aud`; ferramenta sem `readOnlyHint` é tratada como escrita e pede confirmação; `search`/`fetch` não são mais exigidas; recusa método de autenticação fora da interseção anunciada; mostra os argumentos antes de mandar a chamada; `securitySchemes` por ferramenta e `_meta["mcp/www_authenticate"]` para pedir escopo (5.1); gravação em conector próprio pode depender do plano (fonte secundária, risco na seção 11) | https://developers.openai.com/apps-sdk/build/auth e https://developers.openai.com/api/docs/guides/custom-mcp-server |
| Gemini, app (gemini.google.com, "Custom apps") | não informado | DCR; sem DCR, credenciais manuais em "Advanced features" | não encontrado em fonte oficial | só na web, só em inglês, só nos **EUA**, maiores de 18, conta Google pessoal, "Keep Activity" ligado; escrita sempre com confirmação manual | https://support.google.com/gemini/answer/17209137 (sem data) |
| Gemini Enterprise | só Streamable HTTP (SSE não) | só cliente pré-registrado (client ID e segredo digitados pelo admin; PKCE opcional); DCR e CIMD não citados | `https://vertexaisearch.cloud.google.com/oauth-redirect` | configurado pelo admin no Google Cloud (`roles/discoveryengine.editor`); ações com confirmação, salvo `readOnlyHint`; até 100 ações | https://docs.cloud.google.com/gemini/enterprise/docs/connectors/custom-mcp-server/set-up-custom-mcp-server e https://support.google.com/g/answer/17106276 (15/09/2026; Pre-GA na edição Business) |
| Gemini CLI e Antigravity | `httpUrl` (Streamable HTTP) | Gemini CLI: DCR ou `oauth.clientId`; Antigravity: manual, CIMD (`https://antigravity.google/oauth/client-metadata.json`) ou DCR | loopback; Antigravity manual: `https://antigravity.google/oauth-callback` | ferramentas de desenvolvedor; Antigravity com 180 s por ferramenta; no plano gratuito o Gemini CLI foi trocado pelo Antigravity em 18/06/2026 | https://geminicli.com/docs/tools/mcp-server/ e https://antigravity.google/docs/mcp/ |

**Recomendação (revista em 16/11/2026; substitui a de 08/11, que tinha CIMD e DCR no mesmo PR, e a de agosto, que era
"pré-registrado primeiro, DCR depois"):**

1. **CIMD é o único caminho do piloto**, e entra no PR 3. Cobre Claude, Claude Code e ChatGPT (e o Antigravity, fora do
   piloto) sem tabela de clientes crescendo. Anunciar `client_id_metadata_document_supported: true` **e**
   `token_endpoint_auth_methods_supported: ["none"]` (sem o `none` o Claude cai para DCR, que não existe aqui).
   - **Regra dura: a lista exata de `redirect_uri`**: `https://claude.ai/api/mcp/auth_callback`,
     `https://chatgpt.com/connector_platform_oauth_redirect`, `https://chatgpt.com/connector/oauth/{callback_id}` e loopback
     com porta livre em `127.0.0.1` e `localhost` (Claude Code, RFC 8252). O `redirect_uri` do pedido tem de estar no
     documento CIMD **e** nesta lista.
   - **Hosts de `client_id` em parâmetro de configuração** (muda sem deploy de código), começando com `claude.ai` e
     `chatgpt.com` (14.d). A busca do documento só vai a host da lista, o que já resolve o SSRF (9.5). Toda recusa grava no
     log `mcp` só o host recusado. Motivo: o Claude escolhe CIMD olhando o metadado, **antes** do `/authorize`; se o
     servidor recusa o host do `client_id`, não há volta, e a conexão falha. A documentação do Claude não publica a URL do
     `client_id` do claude.ai (só a do Claude Code, `https://claude.ai/oauth/claude-code-client-metadata`), e um terceiro
     (https://docs.strata.io/guides/ai-identity/connect/claude.md, sem data) cita também
     `https://claude.com/api/mcp/auth_callback`. **Item de conferência do PR 3 e do smoke depois do PR 5:** ver o
     `client_id` e o `redirect_uri` reais que o Claude e o ChatGPT mandam e, se forem outros, ajustar a configuração (hosts)
     ou a lista de `redirect_uri` (esta, por PR).
   - A busca do documento: só https com caminho, nunca IP privado, loopback ou link-local, tempo curto, tamanho máximo,
     cache pelos cabeçalhos; o `client_id` do documento igual à URL; JSON e campos obrigatórios validados.
   - Na tela de consentimento, o app que pede acesso é mostrado pelo **host do `client_id`**, e não pelo `client_name`, que
     é autodeclarado (9.4).
2. **DCR fora do piloto.** Está deprecated na especificação 2026-07-28 ("New implementations should use Client ID Metadata
   Documents"), Claude e ChatGPT preferem CIMD quando o metadado tem `client_id_metadata_document_supported: true` e
   `"none"`, o SDK não traz mais o middleware de registro (3.1), e o DCR é um endpoint aberto a qualquer um. Sem
   `registration_endpoint` no metadado. Se o smoke mostrar um cliente que não usa CIMD, o DCR entra num PR próprio, com as
   regras da revisão de 09/11: limite de taxa por IP, expiração em 24 h do cliente que não autorizou ninguém e
   `redirect_uris` só da lista acima, recusando o resto com `invalid_redirect_uri` (RFC 7591).
3. **Pré-registrado: fora do piloto** (14.d). Só o Gemini Enterprise precisa dele (client ID e segredo digitados pelo
   admin do escritório). Quando entrar: comando de console, sem tela, cliente confidencial, segredo guardado só como hash,
   preso ao escritório que pediu; as colunas dele (segredo e escritório do cliente) entram nesse PR, com migration própria
   (13.2).

**Metadado do servidor de autorização:** `issuer: "https://bluejus.com.br"` (texto exato, sem barra no fim, igual ao
`authorization_servers` e ao `iss`, 3.2), `authorization_endpoint`, `token_endpoint`, `response_types_supported: ["code"]`,
`grant_types_supported` (`authorization_code` e `refresh_token`, decisão 14.c), `code_challenge_methods_supported:
["S256"]`, `token_endpoint_auth_methods_supported: ["none"]` (até entrar o pré-registrado),
`client_id_metadata_document_supported: true`, `authorization_response_iss_parameter_supported: true` (só com o `iss` em todo
redirecionamento, inclusive os de erro) e `scopes_supported: ["mcp:leitura", "mcp:escrita", "offline_access"]` (o Claude
acrescenta `offline_access` quando este metadado o lista, 5.1). Sem `registration_endpoint`.

**Metadado do recurso:** `resource: "https://bluejus.com.br/mcp"` (igual à URL que a pessoa digita),
`authorization_servers: ["https://bluejus.com.br"]` (um só) e `scopes_supported: ["mcp:leitura", "mcp:escrita"]`, **sem**
`offline_access` (a especificação 2026-07-28: "MCP Servers SHOULD NOT include `offline_access` in `WWW-Authenticate` scope
or Protected Resource Metadata `scopes_supported`").

### 3.5 Versão do protocolo

A versão atual é **2026-07-28** (https://modelcontextprotocol.io/specification/versioning): protocolo sem estado (saem
`initialize`, `Mcp-Session-Id` e a retomada por `Last-Event-ID`; entra `server/discover`), versão declarada por requisição. A
documentação do Claude cita as versões 2025-03-26, 2025-06-18 e 2025-11-25
(https://claude.com/docs/connectors/building/index.md), e um terceiro conferido em 31/07/2026 diz que o Claude ainda abre
com `initialize` (https://zuplo.com/learn/mcp/compatibility/clients/claude-connectors.md).

**Recomendação (revista em 16/11/2026):** com o SDK 0.10.0 (3.1), o `/mcp` atende **2025-06-18, 2025-11-25 e 2026-07-28 no
mesmo endpoint, sem sessão MCP**; a spec não precisa escolher. Não há PR próprio para a 2026-07-28 nem
`Psr16SessionStore` ou pool de cache de sessão. A versão do SDK continua fixa.

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
   `TenantContextValidatorListener` o prende ao Meu Perfil (`:185`, pelo `primarioNaContaSuspensaAPedido`, `:239-268`: só
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
     conector grava o instante da autorização **uma vez, na concessão** (`conector_ia_concessao.autorizado_em`, 13.2), de
     que todo token e toda renovação da cadeia dependem; a rotação não copia nem renova nada. Recusa, no `/mcp` e no
     `/oauth/token`, todo token ou renovação cuja concessão tem `autorizado_em` menor ou igual ao corte (o mesmo "no mesmo
     segundo também cai" do listener). O consentimento fica: a pessoa refaz o login e a conexão volta.
   - Troca de senha: não há data de troca no `User`, e os caminhos que gravam senha são vários (`AlterarSenhaUseCase`,
     `RedefinirSenhaUseCase`, `ConfirmarCodigoSenhaUseCase`, `CriarSenhaPeloLinkUseCase`, `LigarContaAoGoogleUseCase`,
     entre outros). No navegador as outras sessões caem porque o `User` não implementa `EquatableInterface` e o Symfony
     compara o hash da senha a cada pedido. O conector faz o mesmo: grava na concessão uma **impressão da senha** (HMAC,
     com o segredo da aplicação, do hash atual) e recusa quando ela não bate com a do hash de agora. Assim nenhum
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
  ou excluída, pedido de exclusão, vínculo e situação na equipe (`:182-199`); e o `TenantUrlScopeListener` (prioridade 4,
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
- **O escritório do token vale no `TenantContext`, não só no filtro** (revisão de 16/11/2026). Cerca de 22 serviços fora de
  controller leem `TenantContext::getCurrentTenant()`, que lê só a sessão (`TenantContext.php:27-35`); com bearer devolvem
  `null` e degradam em silêncio, sem erro: `PendenciasDoCadastro.php:129-134` (reusado em `ler_clientes`, 6.4: o documento
  vinculado some porque `DocumentosVisiveisDoCadastro::de` recebe escritório nulo), `NivelNoCadeado.php:223-229` (sem
  escritório, o Master passa por `ehMaster`), `NotificacaoService.php:59` e `:102`, `ListaDeMetasDaPastaEmJson.php:99-100`,
  `AuditLogSubscriber.php:423`. Por isso o portão chama um **método novo e único** do `TenantContext` (nome sugerido
  `fixarSemSessao(Tenant, UserTenant)`), que guarda o escritório e o vínculo como estado da requisição (`ResetInterface`) e
  que `getCurrentTenant`, `getCurrentUserTenant` e `hasCurrentTenant` respeitam. Teste de arquitetura: só o portão o chama.
  Com isso a auditoria sai certa sem escopo próprio (4.3), e o `TenantFilterListener` deixa de ser o único caminho. A regra
  "UseCase recebe `User` e `Tenant` explícitos e filtra à mão" (4.2) continua como segunda camada.
- As barreiras do `TenantContextValidatorListener` saem para um serviço sem sessão que **o listener e o portão usam**, sem
  mudar o comportamento do navegador (testes do listener antes, mover sem reescrever). Duplicar a lógica no conector é o
  jeito de as duas divergirem.
- **O serviço extraído recebe se o passe do Master vale.** Hoje o listener deixa o Master passar pela barreira do vínculo em
  análise ou suspenso pela equipe ("O Master não passa por esta barreira", `TenantContextValidatorListener.php:283-291`,
  passe em `:285`) e deixa passar o Master sem escritório (`:163-165`). O listener chama o serviço com o passe (o navegador
  não muda); o **portão chama sem o passe** e exige `UserTenant::isActive()` verdadeiro e a situação na equipe aprovada para
  todos, sem exceção (decisão 1 da seção 14; 6.3). Teste em 12.3.
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
à mão mesmo com o filtro ligado. Ferramenta cujo caminho passe por algo que lê `Security` ou a `Request`, ou por controller
(ex.: `TarefaController::minhas`, `PastaController::show`, `PastaController::downloadDocumento`, o trait
`AutorizacaoCobranca`) **não entra** até existir UseCase sem sessão. Serviço que lê `TenantContext` passa a receber o
escritório do token pelo `fixarSemSessao` do portão (4.1); a regra antiga ("o que lê `TenantContext` não entra") não era
verificável, porque a leitura está em serviço de serviço.

### 4.3 Auditoria sem sessão

O `AuditLogSubscriber` (`app/src/Service/Audit/AuditLogSubscriber.php`) tira o ator de `Security::getUser()` (`:414`) e o
escritório de `TenantContext` (`:423`, via `tenantDoRegistro`, `:453-464`). Com bearer e sem nada feito, `getCurrentTenant()`
devolve `null` sem exceção e `tenantDoRegistro` devolve `null`: o registro sairia **sem escritório**. O ator sai certo se o
firewall do `/mcp` autenticar a pessoa do token.

**Resolvido pelo portão** (revisão de 16/11/2026): com o escritório fixado no `TenantContext` pelo `fixarSemSessao` (4.1),
o `tenantDoRegistro` devolve o escritório do token, sem escopo próprio do conector (a proposta antiga, no molde do
`escopoDePlataforma` de `:421`, sai). Teste: uma escrita pelo conector grava `audit_log` com o escritório e a pessoa do
token.

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
   `security.yaml:88`). Uma ferramenta que chame UseCase direto pula essas camadas; a seção 5.2 lista cada uma.

### 5.1 Escopos OAuth e permissões do sistema

São eixos diferentes e **os dois valem**:

- **Escopo** diz o que *aquela conexão* pode fazer: `mcp:leitura` e `mcp:escrita` (renomeados de `mcp:read`/`mcp:write` para
  seguir o idioma do projeto).
- **Permissão** diz o que *aquela pessoa* pode fazer naquele escritório.

A ferramenta só executa se **ambos** permitirem. Escopo nunca amplia permissão. `mcp:escrita` só entra no token se a pessoa
marcar a caixa na tela de consentimento (desmarcada por padrão).

**Pedir escopo a mais (step-up), revisto em 16/11/2026.** No Claude, o 403 só leva a pedir o escopo de novo se vier com
`WWW-Authenticate: Bearer error="insufficient_scope"`, e "a tool handler can't produce that response: once the MCP SDK is
running a tool, whatever the handler returns is wrapped in a 200"
(https://claude.com/docs/connectors/building/lazy-authentication.md). Por isso:

1. O 401 inicial leva `scope="mcp:leitura mcp:escrita"`; a tela mostra `mcp:escrita` desmarcado; a resposta do
   `/oauth/token` sempre traz o `scope` concedido.
2. Ferramenta de gravação e `confirmar_acao` chamadas sem `mcp:escrita` recebem **HTTP 403 antes do SDK**, pela `ScopePolicy`
   do `AuthorizationMiddleware` (3.1), com `WWW-Authenticate: Bearer error="insufficient_scope"`,
   `scope="mcp:leitura mcp:escrita"` e `resource_metadata`. Nunca um 200 com `isError`.
3. Para o ChatGPT, que usa outro canal (https://developers.openai.com/apps-sdk/build/auth): cada ferramenta declara
   `securitySchemes` (`oauth2`, com os escopos que pede) e, quando o escopo falta, o resultado leva também
   `_meta["mcp/www_authenticate"]`.
4. As ferramentas de gravação **aparecem** no `tools/list` de quem só tem leitura; escondidas, o modelo nunca as chamaria e o
   step-up nunca aconteceria.

`offline_access` é anunciado só no metadado do servidor de autorização (3.4, decisão 14.c), porque o Claude o acrescenta
quando ele está lá; nunca no `WWW-Authenticate` nem no metadado do recurso. Ele não amplia nada, só deixa o cliente renovar o
token dentro dos 7 dias.

### 5.2 As guardas que o conector pula, e como refazer cada uma

| Guarda | Onde mora hoje | O que a ferramenta faz |
|---|---|---|
| Barreiras do escritório (conta suspensa ou excluída, pedido de exclusão, vínculo, situação na equipe) | `TenantContextValidatorListener` (`kernel.request`) | o portão, pelo serviço extraído, **sem o passe do Master** (4.1); o primário de escritório suspenso a pedido é **recusado** (regra 1 da seção 4) |
| Termos de uso aceitos na versão vigente | `TermoAceiteListener` (prioridade 7, `app/src/EventListener/TermoAceiteListener.php:90-121`), que usa a sessão (`:111`) | o `/oauth/authorize` passa pelo listener como hoje (a pessoa aceita na tela); no `/mcp`, o portão confere `AceiteTermoRepository::existeAceiteVigente` (`app/src/Termo/Repository/AceiteTermoRepository.php:39`) a cada requisição e recusa com "aceite os Termos de Uso no BlueJus" (8) |
| "Sair de todos os aparelhos" | `SessaoEncerradaEmTodosOsAparelhosListener` (prioridade 7), que só age com sessão | `autorizado_em` da concessão contra `User::getSessoesEncerradasEm()` (regra 3 da seção 4) |
| Troca de senha | o Symfony, ao reler o `User` da sessão (sem `EquatableInterface`, compara o hash da senha) | impressão da senha na concessão (regra 3 da seção 4) |
| Cadeado da pasta | `BarreiraDoCadeadoListener` (`kernel.controller_arguments`, `app/src/Pasta/EventListener/BarreiraDoCadeadoListener.php:39`) | `AcessoAPasta::podeVer`/`podeEditar` (`app/src/Pasta/Service/AcessoAPasta.php:42`, `:47`) no item; `idsOcultosPara` (`:118`) e `idsDosProcessosOcultos` em lista. `podeVer` usa o escritório **da pasta**: conferir antes que a pasta é do escritório do token |
| Aba oculta | `BarreiraDeAbaOcultaListener` (`app/src/Pasta/EventListener/BarreiraDeAbaOcultaListener.php:37`, que lê o escritório da sessão), pelo nome da rota (`MAPA`, `:50-79`) | `VisibilidadeDasAbasDaPasta::abasOcultasPara` (`app/src/Pasta/Service/VisibilidadeDasAbasDaPasta.php:115`), com a aba que a tabela de 6.6 dá para cada ferramenta e ação |
| Pasta excluída é só leitura | `PastaSomenteLeituraListener` | escrita confere `Pasta::estaExcluida()` (`app/src/Pasta/Entity/Pasta.php:450`) |
| Tela ou função desligada pelo Master | `BloqueioDeRecursoDesativado`, por prefixo do nome da rota (`CatalogoDeRecursos::ROTAS`, `app/src/Master/Service/CatalogoDeRecursos.php:91`: `peticionar`, `metas`, `expedientes`, `assistente`...); o Master passa (`BloqueioDeRecursoDesativado.php:50`) | `RecursosDoSistema::estaAtivo(chave)` (`app/src/Master/Service/RecursosDoSistema.php:58`) na ferramenta, com a chave que a tabela de 6.6 dá; no conector a chave desligada vale **também para o Master** (teto, 6.3); chave nova `conector_ia` no catálogo para o Master desligar o conector inteiro (14.a) |
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
| Manda algo ao cliente ou a terceiro | e-mails do cliente para avisos (`pasta_push_cliente_email_*`: `CadastrarEmailDoClienteUseCase`, cujo e-mail "nasce recebendo avisos", `TornarEmailDoClientePrincipalUseCase`, `DefinirAvisosDoEmailDoClienteUseCase`, `RemoverEmailDoClienteUseCase`); todo envio do Push ao cliente (`EnviarAnaliseAoClienteUseCase`, `DefinirEnvioAutomaticoDoClienteUseCase`, links públicos `GerarLinkExternoDoPushUseCase` e renovação); encaminhar pela Central (`EncaminharItemDaPastaUseCase`); todo caminho que estende o envio automático a um cliente que o tem ligado: gravar o e-mail dele, vinculá-lo a uma pasta, torná-lo principal, desvincular o principal quando ele seria promovido, vincular processo a pasta cujo principal o tem ligado, reativar ou restaurar essa pasta (recusam e mandam fazer pela tela; lista e código na abertura da 6.6) | decisão de 09/11/2026 (14.h) |
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
   um teste prova que a requisição seguinte do mesmo processo volta a seguir a preferência (com
   `$client->disableReboot()`: o `KernelBrowser` reinicia o kernel entre requisições e, sem isso, o teste não prova nada;
   no PHP-FPM nada sobrevive entre requisições, e o risco real de vazar é o worker do Messenger, onde nenhum handler roda
   dentro do `/mcp`). Assim:
   - texto de **várias linhas** (anotação, observação, descrição de meta, texto de movimentação, teor de publicação):
     `ProtecaoDoTextoDoChat::comoDado` (`ProtecaoDoTextoDoChat.php:38`), que neutraliza linha a linha e mantém as quebras;
   - valor **de uma linha** (nome de arquivo, nome da pessoa, título de meta): `ProtecaoDoTextoDoChat::rotulo` (`ProtecaoDoTextoDoChat.php:49`), com valor padrão quando
     fica vazio;
   - **texto extraído de documento** (6.5): só neutralizado, linha a linha, direto pelo `NeutralizadorDeConteudo::neutralizar`
     (nunca pelo `comoDado`, que no conector mascara), **sem a máscara**, porque o conteúdo do documento é a exceção da
     regra 4 coberta pelo aceite do conector, e o PDF e a imagem já vão inteiros. Sem isso, o assistente não conseguiria
     cadastrar o cliente a partir de um DOCX (pedido C, 14.b).
7. **Resposta** (revista em 16/11/2026): JSON estruturado (`structuredContent`) **e**, no `content`, o mesmo dado
   serializado (ou um texto que contém todos os campos), nunca só um resumo. Motivo: a especificação pede que "a tool that
   returns structured content SHOULD also return the serialized JSON in a TextContent block"
   (https://modelcontextprotocol.io/specification/2026-07-28/server/tools); o Claude Code entrega ao modelo só o
   `structuredContent` quando ele existe, e no ChatGPT o modelo vê os dois. Sem `outputSchema` no piloto (o SDK 0.10 valida
   e troca resultado divergente por `isError`). O orçamento de tamanho é a soma dos dois campos: teto prático de 50 mil
   caracteres **por campo** (o Claude aceita cerca de 150 mil por resultado; o Claude Code, 25 mil tokens, o que o guia do
   usuário avisa), com paginação (`pagina`, `proxima_pagina`). Tempo-alvo abaixo de 10 s (o Claude espera 240 s).
8. **Anotações MCP** (`ToolAnnotations` do SDK, `app/vendor/mcp/sdk/src/Schema/ToolAnnotations.php:38-42`), todas com
   valor explícito, porque o padrão da especificação para quem não declara é `destructiveHint: true` e `openWorldHint: true`:
   - leitura: `readOnlyHint: true`, `destructiveHint: false`, `openWorldHint: false` (salvo `ler_processos`, que consulta o
     CNJ: `openWorldHint: true`);
   - gravação: `readOnlyHint: false`, `destructiveHint: true` (todas as 9 mudam ou sobrescrevem algo que já existe; pela
     especificação, `false` quer dizer "só acrescenta"), `idempotentHint: false`, `openWorldHint: false` (salvo
     `gravar_na_agenda`, que sincroniza com o Google: `true`);
   - `confirmar_acao`: como a gravação, mais `_meta["anthropic/requiresUserInteraction"]: true` (6.7);
   - `title` em português em toda ferramenta (exigência do diretório da Anthropic);
   - `securitySchemes` em toda ferramenta, com o escopo que ela pede (ChatGPT, 5.1).
   As anotações são **dicas**: a especificação diz que o cliente não deve decidir por elas quando o servidor não é confiável
   (https://modelcontextprotocol.io/specification/2025-06-18/schema#toolannotations). Nenhuma regra de segurança depende delas
   (6.7).
9. **Log** no canal `mcp`, que já existe (`app/config/packages/monolog.yaml`, arquivo `mcp.log`): pessoa, escritório, cliente
   OAuth, ferramenta, ação, ids, contagens, duração e resultado. **Nunca** texto de busca, título, nome, valor de campo ou
   conteúdo (regra 3 da Inteligencia). O log `mcp` do conector é escrito **só pelo portão**: o SDK recebe um `NullLogger`
   (ou um logger que só passa `warning` sem contexto), porque ele escreve no log a mensagem inteira, os argumentos e o
   resultado da ferramenta (3.1), e o canal `mcp` grava `info` em `mcp.log` na produção (`monolog.yaml:96-100`); sem isso,
   o CPF de `atualizar_cliente` e o conteúdo de documento iriam para o arquivo.
   A linha de cada requisição (`RegistroDaChamadaMiddleware`) leva também o `metodo` JSON-RPC (`initialize`, `tools/list`,
   `tools/call`...) e a `versao` do cabeçalho `MCP-Protocol-Version`, só quando batem com o formato esperado (nome de método
   curto, data `AAAA-MM-DD`); nada mais do corpo vai para o log. Veio do smoke de 18/11 em produção: sem o método, não dava
   para saber se o app chegou a chamar alguma ferramenta.
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
    da rota da requisição (`AuditLogSubscriber.php:348` e `:434`): com a rota do `/mcp` com nome próprio (sugestão
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
   no navegador, tem os bypasses de volta, com `$client->disableReboot()` (sem isso o `KernelBrowser` reinicia o kernel e o
   teste não prova nada). O risco real de vazar o modo é o worker do Messenger; nenhum handler roda dentro do `/mcp`.
3. **A pessoa conta pelo perfil do vínculo e pelas regras de item**, como qualquer colega: módulo e recurso pelo perfil;
   cadeado (pasta trancada só para quem está na lista dela) e abas ocultas como para os outros. **Regra fixa do modo para o
   perfil de sistema** (revisão de 16/11/2026): ele responde sim a toda permissão que não começa com `admin.`, e passa pelo
   cadeado (`cabeNoCadeadoDaPasta`) e pelas abas como qualquer colega. Então o primário continua vendo e mexendo nas
   pastas, clientes e processos sem cadeado; o que perde é **toda pasta trancada** e toda função `admin.*`. Master e
   primário nunca estão na lista de uma pasta trancada: a grade do cadeado os exclui
   (`EquipeDoCadeado::doEscritorio`, `app/src/Pasta/Service/EquipeDoCadeado.php:28-33`, porque quem configura nunca é
   restringido). Sem o passe, `CadeadoDaPasta::nivelDe` (`app/src/Pasta/Entity/CadeadoDaPasta.php:130`) devolve `Oculto`
   para eles em toda pasta trancada, no modo "Ocultar para todos" e no modo por pessoa. Não há caminho pelo conector para
   abrir uma delas: `pedir_acesso` não resolve, porque um pedido aprovado nunca fura o cadeado
   (`app/src/Service/PermissionChecker.php:226-228`). Isso é aceito por ser o lado seguro; a pasta continua na tela.
   A regra fixa dispensa a conferência do que está ligado ao perfil de sistema, sem fallback pelo `PermissionFixture`. A
   conferência na produção já foi feita (16/11/2026, pelo MCP `jusprime-prod`, só contagem): 27 perfis de sistema;
   `modules.{agenda,clientes,expediente,pastas,processos,tarefas}.view` e todos os `resources.*` estão nos 27; faltam só
   `modules.inteligencia` (26), `trabalho_externo` (21) e `voto` (1), fora do conector. (A F6 da `docs/AUTORIZACAO.md`, que
   diz faltar `modules.expediente.view` na produção, está desatualizada.)
   O Master conta pelo perfil do vínculo dele naquele escritório (a decisão 1 já exige o vínculo ativo); existem Masters
   secundários com vínculo comum (`docs/AUTORIZACAO.md`, 2a).
4. **Ramos de administrador dentro de UseCase reaproveitado ficam fechados pela ferramenta**, para quem tem `admin.*` num
   perfil comum: `ListarCompromissosUseCase` sempre com `administra = false` (`:47`); editar, mover, mudar status e excluir
   compromisso só de quem o criou, conferido pela ferramenta antes do UseCase, porque `SalvarCompromissoUseCase` (`:70`),
   `MoverCompromissoUseCase` (`:49`), `AlterarStatusDoCompromissoUseCase` (`:41`) e `ExcluirCompromissoUseCase` (`:31`)
   deixam passar quem tem `admin.users.manage`. Para a pessoa do token essa conferência é redundante de propósito: o modo
   (item 2) já faz `canAdminister(..., 'admin.users.manage')` responder falso; ela fica na ferramenta como segunda camada.
   Nos UseCases e serviços de Pasta, Cliente, Processo, Agenda, Expediente,
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
- Saída: nome da pessoa, escritório, escopos e validade da conexão e as ferramentas e ações que ela pode usar (pelos módulos
  que tem). Sem o campo de elicitation (fora do piloto, 6.7).
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
  (`app/src/Pasta/Repository/PastaRepository.php:540`), `BuscarProcessosParaVincularUseCase::executar(Pasta, string, Tenant,
  User)` (`app/src/Pasta/UseCase/BuscarProcessosParaVincularUseCase.php:52`).
- Falta: um UseCase de leitura sem sessão (a regra está no `ProcessoController`); um método paginado de movimentações recentes
  no `MovimentacaoProcessoRepository` (`app/src/Processo/Repository/`; hoje só `ultimasPorProcesso`, que devolve datas, `:46`); a consulta ao CNJ com UseCase
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
e trocar um valor já preenchido no cadastro do cliente.

**Gravar por cima é regra geral e também conta como exclusão** (decisão de 09/11/2026), porque o valor anterior se perde:

- **Proposta:** substituir um valor que já existe, isto é, trocar um texto, um número, um valor em dinheiro ou uma data já
  preenchidos por outro, e tirar um item de uma lista (marcador).
- **Direto:** preencher campo vazio, acrescentar (escrever, responder, criar, adicionar, vincular) e marcar ou desmarcar um
  item (favoritar, acompanhar, destacar, cumprir prazo, marcar item do checklist, quitação, documento assinado, pro bono).
  Também direto, por coerência com o que já foi decidido para a esteira e o arquivar: escolher numa lista fechada
  (situação, prioridade, responsável, carteira, fase, via, destino da peça, contrato pendente ou regular) e mudar a ordem ou o
  lugar (reordenar, mover documento ou subpasta), que não apagam texto de ninguém e voltam pelo mesmo caminho.
- **O que a regra cobre**, ferramenta por ferramenta (o detalhe fica em cada uma, abaixo): `atualizar_pasta`
  (`editar_dados` sobre campo preenchido, `nome_do_cliente`, `cadastro_da_pasta` sobre campo preenchido, `marcador` editar e
  `marcadores_da_pasta` que tira um marcador); `mudar_andamento` (`nomear_etapa` que troca um nome já dado);
  `registrar_na_pasta` (`editar`); `prazos_e_metas_da_pasta` (`salvar_prazo` que edita prazo ou audiência existente);
  `documentos_e_pecas` (`editar_documento`, `editar_peca`, que regrava o HTML na mesma chave, sem versão,
  `app/src/Pasta/UseCase/EditarPecaTextoUseCase.php:19`, `:84-91`, `subpasta` renomear, `salvar_modelo` sobre modelo
  existente, `checklist_item` editar o texto e `checklist_modelo` renomear); `financeiro_da_pasta` (`valor_da_causa` já
  preenchido e corrigir o valor de um pagamento); `atualizar_cliente` (`completar` e `contato` que trocam valor, como já
  estava); `processos_da_pasta` (`dados_do_cartao` sobre campo preenchido, `acao_da_pasta` já preenchida e `nota_tecnica`
  editar); `gravar_na_agenda` (`salvar` que edita compromisso existente e `mover`, que troca a data).
- **Lote** (vários documentos, vários marcadores, vários campos numa chamada) é uma proposta e uma confirmação (6.7,
  item 5). Numa chamada que preenche campos vazios e troca um preenchido, a chamada inteira vira proposta, com o resumo
  dizendo o que é novo e o que é troca, para nada ser gravado pela metade.

**Envio automático ao cliente: nenhum caminho do conector o estende** (decisão de 09/11/2026, 14.h). O envio automático
manda, por pasta viva, ativa e sem cadeado, as publicações dos processos vinculados ao **cliente principal** da pasta, se
ele tem alguma chave ligada (`GruposDoEnvioAutomatico::agrupar`, `app/src/Pasta/Service/GruposDoEnvioAutomatico.php:43`,
`pastaLiberada` `:81`; `PastaRepository::listarVinculosDePastasVivasDosProcessos`, `:1719`, que exige `excluidaEm` nulo;
`EmailsDoClienteDaPasta::cliente`, `app/src/Pasta/Service/EmailsDoClienteDaPasta.php:35-40`, que usa
`Pasta::getClientePrincipal`, `app/src/Pasta/Entity/Pasta.php:755`). "Cliente com envio ligado" é o que tem a linha
`EnvioAutomaticoDoCliente` com qualquer chave ligada (`EnvioAutomaticoDoClienteRepository::doClienteDoTenant`,
`app/src/Cliente/Repository/EnvioAutomaticoDoClienteRepository.php:37`; `EnvioAutomaticoDoCliente::ligada`,
`app/src/Cliente/Entity/EnvioAutomaticoDoCliente.php:74`). Recusam a chamada inteira, sem gravar nada, com a mensagem fixa
"este cliente recebe o envio automático do Push: faça isso pela tela do BlueJus", conferido na proposta e de novo na
confirmação:

- gravar o e-mail de cliente com envio ligado (`atualizar_cliente`, `completar` e `contato`);
- `vincular_a_pasta` de cliente com envio ligado (`Pasta::addCliente`, `:725-736`, o torna principal quando a pasta não
  tem principal; a recusa vale sempre, sem depender disso);
- `principal` para cliente com envio ligado (`DefinirClientePrincipalUseCase::executar`, `:30`);
- desvincular o principal quando o cliente que seria promovido tem envio ligado (`Pasta::removeCliente`, `:815-824`,
  promove o de cadastro mais antigo; `PastaController.php:802-807`);
- `processos_da_pasta` `vincular` (novo, já cadastrado ou apensado; todos criam `PastaProcesso`, `Pasta.php:618` e `:642`)
  em pasta cujo principal tem envio ligado;
- `atualizar_pasta` `situacao` para Ativo e `restaurar` da lixeira, em pasta cujo principal tem envio ligado: a pasta
  volta a entrar no envio (`pastaLiberada` exige Ativo; a consulta exige `excluidaEm` nulo).

Não estendem: `cadastrar` (cliente novo não tem a linha), `criar` pasta (nasce sem processo) e `duplicar` (copia os
clientes, mas não os processos, `app/src/Pasta/UseCase/DuplicarPastaUseCase.php:30-39`).

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
- Gravar por cima, por proposta (abertura da 6.6): `editar_dados` que troca campo preenchido (`EditarPastaUseCase::executar`,
  `app/src/Pasta/UseCase/EditarPastaUseCase.php:31`), `nome_do_cliente`, `cadastro_da_pasta` que troca campo preenchido e
  `marcador` editar (o marcador é do escritório: a troca vale em todas as pastas). Preencher campo vazio é direto.
- Envio automático: `situacao` para Ativo e `restaurar` recusam em pasta cujo cliente principal tem o envio ligado
  (abertura da 6.6).
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
  - `nomear_etapa` com texto vazio remove o nome, e com texto diferente troca um nome já dado: os dois vão por proposta
    (abertura da 6.6); dar nome a uma etapa sem nome é direto.
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
- Por proposta: excluir prazo (`CumprirPrazoDaPastaUseCase` com excluir) e editar prazo ou audiência que já existe
  (`salvar_prazo` com id: data, tipo, responsável ou texto trocados; o resumo mostra antes e depois). Criar prazo e
  cumprir são diretos.
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
- Gravar por cima, por proposta (abertura da 6.6): `editar_documento` (`EditarDocumentoDaPastaUseCase::executar`,
  `app/src/Pasta/UseCase/EditarDocumentoDaPastaUseCase.php:44`), `editar_peca`, `subpasta` renomear, `salvar_modelo`
  sobre modelo existente (`SalvarModeloDePecaUseCase::executar` com `$existente`,
  `app/src/Pasta/UseCase/SalvarModeloDePecaUseCase.php:33`; o modelo é do escritório), `checklist_item` editar o texto
  (`EditarChecklistItemUseCase::executar`, `app/src/Pasta/UseCase/EditarChecklistItemUseCase.php:17`) e `checklist_modelo`
  renomear. Mover, copiar, reordenar, favoritar, marcar item e salvar modelo novo são diretos.
- Exclusões por proposta: documento e vários documentos para a lixeira (`ExcluirItensDaPastaUseCase`), subpasta
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
- Gravar por cima, por proposta (abertura da 6.6): `valor_da_causa` já preenchido e corrigir o valor de um pagamento
  (`CorrigirValorDoPagamentoUseCase`). Registrar, parcelar, marcar recebido, contrato, pro bono e documento assinado são
  diretos.
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
- **Envio automático: recusa, sem exceção** (regra comum na abertura da 6.6, com as referências ao código). O e-mail do
  cadastro aparece como principal na lista de avisos do cliente até a primeira mudança (inventário 0.C); se o escritório
  já ligou o envio automático para aquele cliente, gravar o e-mail faria os avisos do Push irem para ele. Por isso, para
  cliente com o envio ligado, recusam a chamada inteira, sem gravar nada, com a mensagem fixa da regra comum:
  - `completar` e `contato` com o campo `email` (`CamposDoCadastro::ROTULOS`, `AtualizarContatoDoClienteUseCase::CAMPO_EMAIL`);
  - `vincular_a_pasta` desse cliente (pode virar principal, `Pasta::addCliente`);
  - `principal` para esse cliente (`DefinirClientePrincipalUseCase`);
  - desvincular o principal de uma pasta quando o cliente que seria promovido em seu lugar tem o envio ligado
    (`Pasta::removeCliente`).

  A proposta já recusa, e a confirmação confere de novo (6.7, item 4), porque a chave pode ser ligada nesse meio tempo.
  Sem linha, ou com as três chaves desligadas (o padrão, `ChaveDoEnvioAutomatico::PADRAO`), grava como a tela.
  `cadastrar` não é afetado: cliente novo não tem a linha.
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
- Gravar por cima, por proposta (abertura da 6.6): `dados_do_cartao` que troca campo preenchido, `acao_da_pasta` já
  preenchida e `nota_tecnica` editar (`EditarNotaTecnicaUseCase`). Criar nota técnica é direto.
- Envio automático: `vincular` (novo, já cadastrado, `VincularProcessoUseCase::executar`,
  `app/src/Pasta/UseCase/VincularProcessoUseCase.php:67`, ou apensado) recusa em pasta cujo cliente principal tem o envio
  ligado: as publicações do processo passariam a ir para ele (abertura da 6.6).
- Exclusões por proposta: desvincular processo (`DesvincularProcessoUseCase`; o processo continua existindo), excluir nota
  técnica (`ExcluirNotaTecnicaUseCase`) e excluir processo (`processo_delete`, regra no controller: UseCase extraído no PR 14).
- Permissão: recurso `pasta` edit e aba Processo, como a tela (`MAPA`, `:72-78`); nota técnica e excluir processo, recurso
  `processo` edit; chave `expedientes`.

**`gravar_na_agenda`** (PR 13)
- Ações: `salvar` (criar ou editar compromisso: tipo, horário, "Equipe" ou "Só eu", pasta, participantes, lembrete;
  `SalvarCompromissoUseCase::executar(CompromissoInput, ?int, User, Tenant, bool)`, `:45`), `mover` (`MoverCompromissoUseCase`,
  `:29`), `status` (concluir, cancelar, reabrir: `AlterarStatusDoCompromissoUseCase`, `:35`; cancelar avisa os participantes)
  e `avisar` (`AvisarNoChatUseCase`, `AvisarPrazoNoChatUseCase`: avisos do sino a colegas pelo `AvisoDoCompromisso`).
- Por proposta: `ExcluirCompromissoUseCase` (`:27`) e, por gravar por cima (abertura da 6.6), `salvar` que edita
  compromisso existente e `mover` (troca a data). Criar compromisso e mudar o status são diretos.
- Teto (6.3): editar, mover, mudar status e excluir só de compromisso que a pessoa criou, conferido pela ferramenta.
- **Google Agenda:** o `MudancaNaAgendaParaOGoogleListener` (`app/src/Agenda/EventListener/MudancaNaAgendaParaOGoogleListener.php`)
  sincroniza, depois do flush, a agenda Google de quem criou e dos participantes **que conectaram a própria conta**. É efeito
  normal da gravação, também pelo conector, e fica: vai para a conta Google de cada colega, não para o cliente nem para
  terceiro. A resposta diz que o compromisso vai para o Google de quem tem a agenda ligada. `openWorldHint: true`.
- Permissão: módulo `agenda`; com pasta, ver a pasta.

**Aba e chave do Master por ferramenta e ação** (regra 11 da 6.2). A coluna "rota da tela" diz o que a barreira de hoje cobre;
"nenhuma" quer dizer que a tela também não barra. `BarreiraDeAbaOcultaListener::MAPA` está em
`app/src/Pasta/EventListener/BarreiraDeAbaOcultaListener.php:49-80`; `CatalogoDeRecursos::ROTAS`, em
`app/src/Master/Service/CatalogoDeRecursos.php:91`. Toda ferramenta exige também a liberação do conector nos três níveis
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
abertura da 6.6), inclusive **gravar por cima**, que é regra geral: substituir um valor que já existe vai por proposta, em
qualquer ferramenta; preencher campo vazio, acrescentar e marcar ou desmarcar um item são diretos. A lista de ações
cobertas, ferramenta por ferramenta, está na abertura da 6.6. Por regra desta spec, também voltar fase e reativar pasta
(`mudar_andamento`), porque mexem no contrato. Lote, ou uma chamada que mistura campo vazio e troca, é uma proposta e uma
confirmação (item 5).

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

**Já respondido (revisão de 16/11/2026):** o ChatGPT mostra os argumentos antes de mandar a chamada ("Users can review the
tool input before it is sent", https://developers.openai.com/api/docs/guides/custom-mcp-server). No claude.ai, a ferramenta
de conector personalizado começa "Not set" e pergunta; mas a pessoa pode ligar "Skip approvals" no conector inteiro, e o
modo "Auto" da conversa roda sem perguntar (https://claude.com/docs/llms-full.txt). Os dois entram no limite abaixo.

**Não confirmado em fonte oficial:** se claude.ai, Desktop e celular suportam elicitation (a palavra não aparece em
https://claude.com/docs/llms-full.txt; dois terceiros dizem que não: Relativity, atualizada em 06/11/2026,
https://help.relativity.com/RelativityOne/Content/Relativity/MCP/Anthropic_Claude.htm, e Consilio, sem data,
https://mcp-docs.ai.consilio.com/docs/integrations/claude-ai/); se o "Always allow" do Claude vale para ferramenta com
`destructiveHint: true`; se a caixa de aprovação do Claude mostra os argumentos da chamada; se o claude.ai respeita
`anthropic/requiresUserInteraction` (confirmado só no Claude Code). Nenhum deles muda o desenho: elicitation está fora do
piloto (item 2).

**Desenho:**

1. **Proposta.** A ação que exige confirmação nunca executa na primeira chamada. O servidor grava uma proposta
   (`conector_ia_proposta`, 13.2): pessoa, escritório, consentimento e cliente OAuth do token, ferramenta e ação, os dados
   exatos (JSON) e o hash deles, um **resumo** em português do que vai acontecer (ex.: "Excluir 3 documentos da pasta 2024/118:
   procuracao.pdf, rg.jpg, contrato.docx"; ou a lista dos clientes com os valores que o assistente mandou), estado
   `pendente`, validade de **10 minutos** e uso único. O identificador é aleatório e só o hash dele é gravado. A resposta
   leva o id, o resumo e a instrução fixa "mostre o resumo à pessoa e só chame `confirmar_acao` se ela disser sim".
2. **Elicitation: fora do piloto** (revisão de 16/11/2026). Nem o claude.ai nem o ChatGPT documentam o suporte; no SDK
   0.10.0, sem sessão, os pedidos ao cliente (elicitation, sampling, roots) lançam `SessionRequiredException`, e na
   2026-07-28 o `elicit()` vira `input_required` com `requestState` assinado. Na 0.7.1, o `ClientGateway::request` ainda
   prendia um processo PHP-FPM em laço (`StreamableHttpTransport.php:186-224`, `usleep(100000)`) até a resposta chegar por
   outro POST, aceita de qualquer POST com o mesmo `Mcp-Session-Id` sem olhar o token (`Protocol.php:263-270`). Volta como
   reforço (seção 10) quando um dos dois clientes documentar o suporte. O número do item fica para não mudar as
   referências.
3. **A confirmação é a ferramenta separada** (o caminho que todo cliente usa)
   `confirmar_acao(proposta_id, resumo)`, anotada `destructiveHint: true` e com `_meta["anthropic/requiresUserInteraction"]:
   true`, que o Claude e o ChatGPT mostram para aprovação antes de chamar, **salvo** se a pessoa marcou "Permitir sempre"
   ("Always allow", claude.ai), ligou "Skip approvals" no conector ou usa o modo "Auto" da conversa (claude.ai), ou marcou
   "lembrar" (ChatGPT): aí o próprio modelo pode chamar `confirmar_acao` sem pergunta nenhuma. Ela exige o **resumo exato**
   além do id: onde a caixa de aprovação mostra os argumentos (o ChatGPT mostra; no Claude, não confirmado), a pessoa lê o
   que vai acontecer, e não só um número; e o modelo não consegue mudar o resumo sem que a confirmação falhe.
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
   vencidas são varridas ao registrar uma proposta nova, como no chat, e pela faxina agendada (13.2). `dados` e `resumo` nunca vão para log nem auditoria.
7. *(Saiu na revisão de 16/11/2026: não há PR próprio da 2026-07-28, 3.5, e a confirmação por `input_required` só
   voltaria com a elicitation, item 2. Se voltar, o `requestState` é assinado por HMAC, preso à pessoa e à proposta, com
   validade curta e uso único conferido no servidor, como a especificação manda.)*

**Limite, dito com honestidade:** o servidor garante que só executa com uma confirmação presa à proposta (pessoa,
escritório, consentimento, cliente OAuth, uso único, 10 minutos). Ele **não** garante que uma pessoa leu o resumo. Com
"Permitir sempre" ou "Skip approvals" no conector, ou o modo "Auto" da conversa no claude.ai, ou "lembrar" no ChatGPT, o
modelo chama `confirmar_acao` sozinho. É risco residual aceito (11, 14.g); o guia do usuário pede para não usar essas
opções, e a elicitation e o modo URL ficam como reforço futuro (alternativas recusadas, abaixo).

**Alternativas recusadas:** recusar cadastro e exclusão quando o cliente não declara elicitation (na 2026-07-28, erro
`MissingRequiredClientCapabilityError`, -32021) deixaria o pedido sem efeito no claude.ai; a elicitation em formulário
(item 2) fica fora do piloto; o modo URL (uma página do BlueJus onde a pessoa confirma, com conferência da sessão) é a
prova mais forte, mas tira a confirmação do chat, contra a decisão, e o suporte nos clientes não está confirmado. Ficam
como reforço futuro, se o piloto mostrar abuso.

**O guia do usuário** (PR 15) pede para nunca marcar "sempre permitir" na `confirmar_acao`, não ligar "Skip approvals" no
conector nem usar o modo "Auto" com ele, e explica o que é a proposta.

**Injeção indireta:** documento, anotação, publicação ou movimentação pode trazer texto que tenta mandar o assistente gravar
algo. Não controlamos o prompt do cliente; as defesas são o escopo `mcp:escrita` desmarcado por padrão, a proposta com
confirmação para o que apaga, grava por cima ou cadastra, a resposta que diz exatamente o que foi gravado e a marcação de
dado (6.2.6) em toda resposta de leitura.

### 6.8 Reuso com o Chat I.A

Hoje uma ferramenta do chat são quatro peças separadas: a definição (`FerramentaDeLinguagem(nome, descricao, esquemaDaEntrada)`,
`app/src/Inteligencia/DTO/FerramentaDeLinguagem.php`, neutra de fornecedor; só `paraAnthropic()` é acoplado), a guarda
(`GuardaDasPropostas`, `GuardaDaConsultaDePrazos`), o executor e o despacho por nome dentro do
`TransmitirRespostaDoChatUseCase`.

**Recomendação (revista em 16/11/2026): depois do piloto.** Um registro de ferramentas com `nome`, `schema`, `anotacoes`,
`guarda(User, Tenant)` e `executar(User, Tenant, array)`, usado pelo chat e pelo conector, evita que a mesma ferramenta
tenha duas regras; mas mexer no `TransmitirRespostaDoChatUseCase` dentro do primeiro PR de ferramentas soma risco a um PR já
MÉDIO, e a spec já separa as guardas em duas portas (aceite da I.A. e do conector, 6.4). No piloto, o conector tem o
registro dele, e o PR 6 não mexe no despacho do chat. A proposta do conector (6.7) é parecida com a do chat, mas não
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
- **Sem sessão MCP** (SDK 0.10.0, 3.1 e 3.5): nenhum `SessionStore`, nenhum pool de cache de sessão. Se uma versão futura
  ligar sessão, o `Mcp-Session-Id` fica gravado com o `consentimento_id`, e pedido com token de outro consentimento recebe
  404.
- **Logger do SDK**: `NullLogger` (6.2, regra 9).
- **Firewall**: um firewall novo para `^/mcp`, sem sessão (`stateless`), com autenticador de bearer, declarado antes do
  `main`. `/.well-known/oauth-*` e `/oauth/token` com `PUBLIC_ACCESS`, como `^/api/webhooks/` (`security.yaml:72`).
  `/oauth/authorize` fica no `main` (login, "lembrar de mim").
- **Listeners de `kernel.request` que precisam deixar as rotas novas passar** (a lista é por nome de rota; os nomes abaixo
  são sugestão):
  - `TenantContextValidatorListener::ROTAS_IGNORADAS` (`app/src/EventListener/TenantContextValidatorListener.php:31`)
    recebe a rota do `/mcp`, a do `/oauth/authorize`, a do `/oauth/token` e as duas do `/.well-known/oauth-*`. **Por
    quê:** no `/mcp` o firewall de bearer autentica a pessoa, mas não há sessão, então `hasCurrentTenant()` é falso e o
    listener responde com redirecionamento para `tenant_selecionar` (`:163-176`): o
    cliente de I.A. recebe um 302 para uma página HTML em vez da resposta MCP, e a conexão quebra. As barreiras que o
    listener aplicaria são do portão (4.1), que as aplica com o escritório do token. No `/oauth/authorize`, a pessoa ainda
    não tem escritório escolhido para o conector, e é a própria tela que o escolhe. Token e metadados são chamados
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
- **Limites de taxa** (no `framework.yaml`, junto dos limitadores que já existem): `conector_token` (por cliente e IP),
  `conector_chamada` (por pessoa **e** por escritório), `conector_documento` (teto próprio para `ler_documentos_e_modelos`
  `ler`, que pode devolver um PDF de 10 MB por chamada), `conector_confirmacao` (teto próprio para `confirmar_acao`) e
  `conector_cimd` (buscas de documento, por domínio). Sem `conector_registro` (DCR fora do piloto, 3.4).
- **nginx**: os dois ajustes de 3.3.
- **Rede**: o Claude sai de `160.79.104.0/21`; ChatGPT e Google têm faixas publicadas. Não há WAF hoje; se entrar um, liberar
  as faixas, inclusive para os endpoints OAuth.
- **Sem chave de assinatura do token** (revisão de 16/11/2026): o token é opaco e conferido no banco (3.2), então não há par
  de chaves no `.env.prod`, rotação de chave pública nem passo do Samuel para isso. A impressão da senha usa o segredo da
  aplicação que já existe (seção 4, regra 3).
- **Deploy**: dependência nova só existe em produção depois do deploy da imagem.

---

## 9. Segurança

1. **Vida do token: 7 dias. Decidido pelo dono (11/08/2026).** Escolha de comodidade: a pessoa autoriza uma vez por semana,
   não todo dia. Na prática (decisão 14.c, 08/11/2026): token de acesso de 1 hora e renovação com rotação, sem passar de 7
   dias da autorização. Renovação já usada que volta é sinal de roubo: a cadeia inteira é revogada.

   **Como a rotação funciona (revisão de 16/11/2026).** A cadeia é uma linha própria, a concessão
   (`conector_ia_concessao`, 13.2): `autorizado_em`, impressão da senha e o limite absoluto de 7 dias moram nela, e a
   rotação não copia nada. No `/oauth/token`, a renovação é lida com `SELECT ... FOR UPDATE` numa transação. Renovação nunca
   usada: grava `usado_em` e emite um par novo (acesso e renovação) ligado à mesma concessão. Renovação já usada que volta:
   - **dentro da janela de tolerância** (cerca de 60 s depois do `usado_em`) e **pelo mesmo cliente**: emite um par novo e
     revoga o par emitido antes a partir dela. Motivo: o Claude espera até 30 s pela renovação e renova também de forma
     reativa num 401 e até cinco minutos antes de vencer (https://claude.com/docs/connectors/building/authentication); se a
     resposta se perde ou chega tarde, ele repete a renovação antiga, e sem a janela a pessoa teria de reconectar por causa
     do próprio cliente;
   - **fora da janela, ou por outro cliente**: sinal de roubo; a concessão é revogada (um `UPDATE` só, com o motivo) e,
     com ela, todo token e toda renovação da cadeia.

   Teste em 12.7.
2. **Revogação imediata, e ela é o que torna os 7 dias aceitáveis. Decidido.** O token diz "pessoa X, escritório Y" e o
   que foi decidido na autorização **não se atualiza** quando a realidade muda. Por isso, **a cada requisição MCP** (não
   uma vez na conexão), o servidor confere **no banco**, e recusa se qualquer um falhar, mesmo com token dentro da validade:
   - `User::isActive` (`app/src/Entity/Auth/User.php:38`; o `UserChecker` só barra no login);
   - `UserTenant::isActive` e a situação na equipe aprovada (`app/src/Entity/Auth/UserTenant.php:40`, `:44`);
   - `Tenant::isActive` e `excluidoEm` nulo (`app/src/Entity/Tenant/Tenant.php:56`, `:59`);
   - pedido de exclusão (`BloqueioPorPedidoDeExclusao`, `app/src/Tenant/Service/BloqueioPorPedidoDeExclusao.php:43`),
     **inclusive para o primário**: `bloqueia()` (`:66`) deixa o primário passar porque o navegador o prende ao Meu Perfil,
     e o conector, que não tem Meu Perfil, recusa (seção 4, regra 1);
   - termos de uso aceitos na versão vigente (5.2);
   - "Sair de todos os aparelhos": token e renovação cuja concessão tem `autorizado_em` menor ou igual a
     `User::getSessoesEncerradasEm()` são recusados (seção 4, regra 3);
   - troca de senha: impressão da senha gravada na concessão diferente da do hash atual é recusada (seção 4, regra 3);
   - consentimento, concessão e token não revogados, e a concessão dentro dos 7 dias (um `JOIN` token, concessão,
     consentimento);
   - a liberação do conector nos três níveis (chave geral, escritório liberado pelo Master, ligado com o aceite vigente,
     14.a).

   Tudo isso vale também no `/oauth/token` ao trocar a renovação: renovação que falha em qualquer item tem a concessão
   revogada e responde `invalid_grant`, que é o que os clientes esperam para pedir login de novo (3.4).

   São as mesmas barreiras do navegador (4.1); a lista de agosto (só os dois `isActive`) ficou curta. Custa uma leitura dos
   registros que o servidor já carrega para aplicar permissão. **Sem isso, um token de 7 dias significa 7 dias de acesso
   para quem acabou de ser desligado.** Não implemente os 7 dias sem a conferência por requisição: são uma decisão só.
3. **Audiência (RFC 8707)**: o token é opaco e não carrega `aud`; a concessão guarda o `resource` pedido, que tem de ser
   `https://bluejus.com.br/mcp` no `/oauth/authorize` e no `/oauth/token` (ausente ou diferente dá `invalid_target`; no
   `/oauth/token`, igual também ao da concessão). O token do conector não vale em nenhuma outra rota do sistema. O guia do
   usuário diz para colar a URL exata (o Claude exige `resource` igual ao que a pessoa digitou).
4. **Confused deputy e tela de consentimento**: consentimento por cliente; nunca reaproveitar consentimento entre
   `client_id` diferentes. A tela mostra como app que pede acesso o **host do `client_id`** (não o `client_name`, que é
   autodeclarado) e o host do redirect, com aviso quando só há redirect de loopback (Claude Code, CLIs). O formulário
   (revisão de 16/11/2026):
   - leva **token CSRF**, conferido no POST;
   - o escritório escolhido é conferido **no servidor** contra `UserTenantRepository::findActiveByUser` e as barreiras do
     portão, sem o passe do Master (4.1); nunca é aceito só porque veio no campo;
   - os parâmetros OAuth do pedido (`client_id`, `redirect_uri`, `state`, `code_challenge`, `resource`, escopos) ficam na
     sessão entre o GET e o POST, não em campo oculto.
   (`X-Frame-Options: SAMEORIGIN` já vem do nginx, `nginx.prod.conf:48`.)
5. **CIMD**: a busca do documento só vai a host da lista em configuração, o que já resolve o SSRF, além das regras de busca
   segura da 3.4.
6. **Redirect e código**: `redirect_uri` igual, byte a byte, a um do documento CIMD **e** da lista exata da 3.4; loopback
   com porta livre só para `127.0.0.1` e `localhost` (RFC 8252). **PKCE S256 obrigatório** para todo cliente:
   `code_challenge_method` ausente ou diferente de `S256` é recusado. `resource` obrigatório (item 3). Código de uso único,
   válido por até 10 minutos, preso ao `redirect_uri`, ao `code_challenge` e à concessão. `iss` em todo redirecionamento,
   inclusive os de erro (3.2). Renovação com rotação, janela de tolerância e `invalid_grant` (item 1, 14.c).
7. **Auditoria**: escrita registrada pelo `audit_log` com o escritório certo, pelo escritório fixado no `TenantContext` pelo
   portão (4.1, 4.3); chamadas no canal `mcp` sem conteúdo, e o SDK com `NullLogger` (6.2.9). Correção da spec de agosto:
   "registrar os argumentos" vira registrar ids e valores de lista, nunca texto (regra 3 da Inteligencia).
8. **Dado pessoal e provedor do modelo**: decisão 14.a (piloto num escritório, liberação pelo Master, aceite próprio do
   conector) e as regras de saída da 6.2.
9. **Purga, exclusão e faxina**: toda tabela nova com dado de escritório tem `tenant_id` e FKs `ON DELETE CASCADE` para
   `tenant`, `user` e a tabela pai (13.2). A purga do escritório acha as tabelas pela coluna `tenant_id`
   (`PurgarEscritorioUseCase.php:441-450`), e a exclusão da conta (quarentena) precisa das FKs de `user_id`;
   `PurgaCoberturaSchemaTest` verde. Excluir a conta ou o vínculo revoga consentimentos, concessões e tokens. Um comando de
   faxina no Scheduler (PR 3) apaga o que vence (13.2).
10. **Injeção indireta pelo conteúdo**: 6.7.
11. **Teto**: nenhuma função de administrador pelo conector, nem para Master e primário (6.3).
12. **Confirmação**: cadastro de cliente e toda exclusão, inclusive gravar por cima (substituir um valor que já existe,
    regra geral da 6.6; preencher vazio, acrescentar e marcar ou desmarcar são diretos), só com uma confirmação presa à
    proposta, conferida de novo no servidor; o servidor não confia nas anotações para isso, mas não prova que uma pessoa
    leu: com "Permitir sempre", "Skip approvals", o modo "Auto" ou "lembrar", o modelo pode confirmar sozinho (6.7, limite;
    14.g).

---

## 10. O que fica fora desta spec

- `consultar_sql` remoto (6.1); a v1 STDIO/SSH continua, só para o dono.
- Funções de administrador dos quatro módulos (6.1), garantidas pelo teto (6.3).
- Ponto eletrônico, Cobrança, a BlueJus I.A. dentro da pasta, todo envio ao cliente ou a terceiro (inclusive todo caminho
  que estende o envio automático a um cliente que o tem ligado, 6.6), o encaminhar pela Central,
  o Push além de ler as publicações e Minhas Metas além de criar meta para a pasta e de ler a lista (14.h, 14.i).
- Enviar arquivo e baixar arquivo binário (6.1; decisão 14.j).
- Áudio e vídeo (14.f).
- Gemini (app, Enterprise, CLI e Antigravity) e a publicação nos diretórios do Claude e do ChatGPT, no piloto (14.d).
- DCR (`/oauth/register`) e cliente pré-registrado, no piloto (3.4); o DCR entra num PR próprio só se o smoke mostrar um
  cliente que não usa CIMD.
- Elicitation, em formulário ou por `input_required`, e o modo URL dela (6.7, item 2 e alternativas recusadas): voltam
  como reforço quando um dos clientes documentar o suporte ou o piloto mostrar abuso.
- Registro de ferramentas compartilhado com o Chat I.A (6.8), depois do piloto.
- `outputSchema` nas ferramentas (6.2, regra 7).
- Selo "pelo conector" no Registro da pasta (6.2, regra 13).
- Cliente MCP próprio do BlueJus (aqui somos só servidor).

---

## 11. Riscos conhecidos

| Risco | Por que importa |
|---|---|
| **`TenantFilter` falha aberto** | Rota nova que esqueça de amarrar o escritório devolve tudo, sem erro. Item nº 1 do plano (PR 1). |
| **Guardas em listeners HTTP** | Cadeado, aba oculta, pasta excluída, função desligada, termos de uso, "Sair de todos os aparelhos" e barreiras do escritório não rodam no conector; cada uma precisa ser refeita e testada (5.2). E dois desses listeners quebram o `/mcp` se não o deixarem passar: redirecionam para `tenant_selecionar` ou `termo_aceite` (8). |
| **Bypass do Master e do primário** | Sem o teto (6.3), "exceto funções de administrador" não se cumpre: o Master passa por toda checagem e o primário também. O modo do conector mexe em `PermissionChecker` e `RegraDosPrimarios`, código de identidade: se valer para outra pessoa que não a do token, um colega deixa de receber aviso de primário; se vazar para a requisição seguinte do mesmo processo, o Master perde o bypass no navegador. Só para a pessoa do token, zerado no `reset()` e testado com `disableReboot()` (12.19). O perfil de sistema segue uma regra fixa (tudo menos `admin.*`), e a produção já foi conferida em 16/11/2026 (6.3, item 3). |
| **Confirmação depende do cliente** | O servidor garante que nada é excluído, gravado por cima ou cadastrado sem uma confirmação presa à proposta (pessoa, escritório, consentimento, cliente OAuth, uso único, 10 minutos), mas não prova que uma pessoa leu: com "Permitir sempre" ("Always allow") ou "Skip approvals" no conector, o modo "Auto" da conversa no claude.ai, ou "lembrar" no ChatGPT, o próprio modelo pode chamar `confirmar_acao` (6.7, limite; 14.g). Elicitation fora do piloto. Risco residual aceito. |
| **Contrato mexido pela esteira** | Mudar a fase ou arquivar a pasta marca ou reabre o contrato do Financeiro, mesmo para quem não vê o Financeiro (6.6, `mudar_andamento` e `atualizar_pasta`). Voltar e reativar vão por proposta; a resposta só fala do contrato a quem vê o Financeiro. |
| **Cadastro em massa** | Um erro do assistente se repete em até 50 cadastros de uma vez. Limite, proposta com a lista, duplicados tirados antes e resultado por item (6.7, item 5). |
| **UseCases extraídos de controller** | Cerca de 50 ações do escopo têm a regra no controller (inventário, seção 6, item 3). Cada extração muda a tela também; mover sem reescrever, com os testes da tela escritos antes. |
| **Auditoria e serviços sem escritório** | Com bearer, `TenantContext` devolve `null` e cerca de 22 serviços degradam em silêncio, inclusive o `audit_log` com escritório nulo. Resolvido pelo portão, que fixa o escritório no `TenantContext` por um método que só ele chama (4.1, 4.3). |
| **nginx barra `.well-known`** | Falha silenciosa: o cliente só diz "não consegui conectar" (3.3). |
| **`mcp/sdk` experimental (0.10.0)** | API muda entre versões menores (a 0.10.0 quebrou namespaces). Versão fixa, como na v1; o PR 2 sobe a versão e ajusta a v1 junto. O SDK escreve argumento e resultado no log se receber um logger: `NullLogger` (6.2.9). |
| **Servidor de autorização dentro da aplicação, e nosso** | Uma falha nele é uma falha no sistema inteiro, não num serviço isolado; e o código de OAuth é nosso, sem biblioteca. Por isso o fluxo completo é testado ponta a ponta (12.7) e o PR 3 tem re-revisão. |
| **Host do `client_id` fora da lista** | O Claude escolhe CIMD antes do `/authorize` e não volta para outro caminho: host não previsto derruba a conexão. Hosts em configuração, recusa com o host no log `mcp`, conferência no PR 3 e no smoke (3.4). |
| **Rotação derrubada por repetição do cliente** | Se a resposta da renovação se perde, o cliente repete a antiga; sem tolerância, a cadeia cai e a pessoa reconecta. Janela de cerca de 60 s pelo mesmo cliente, com trava de linha (9.1). |
| **Gravação pelo ChatGPT pode depender do plano** | Fontes secundárias de julho de 2026 (https://hjarni.com/blog/how-to-use-mcp-with-chatgpt) dizem que ações de escrita em conector próprio ficam em Business, Enterprise e Edu, e que Plus e Pro só leem; a página oficial não confirmou. É risco, não regra: conferir o plano do escritório piloto antes de testar gravação no ChatGPT, e o guia do usuário (PR 15) diz o plano exigido se confirmado. |
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
   continua chegando ao Meu Perfil (o teste do listener segue verde). Outro caso próprio: **Master com vínculo em análise**
   (ou suspenso pela equipe) é recusado pelo portão, enquanto o navegador continua deixando-o passar (4.1).
4. **Permissão**: pessoa sem o módulo recebe negativa mesmo com escopo `mcp:leitura`.
5. **Escopo**: token só com `mcp:leitura` não executa ferramenta de gravação nem a `confirmar_acao`, mesmo que a pessoa tenha
   a permissão: a resposta é **HTTP 403** com `WWW-Authenticate: Bearer error="insufficient_scope"`,
   `scope="mcp:leitura mcp:escrita"` e `resource_metadata`, nunca um 200 com `isError` (5.1); o resultado leva também
   `_meta["mcp/www_authenticate"]` (ChatGPT); as ferramentas de gravação aparecem no `tools/list` de quem só tem leitura;
   toda ferramenta declara `securitySchemes`.
6. **Revogação**: revogado o consentimento ou desativado o vínculo, o token para de funcionar.
7. **Fluxo OAuth completo** ponta a ponta, com PKCE, contra o servidor de verdade, por CIMD. `code_challenge_method`
   ausente ou `plain` recusado; `resource` ausente ou diferente dá `invalid_target`, no `/oauth/authorize` e no
   `/oauth/token`; `iss` igual a `https://bluejus.com.br` em todo redirecionamento, de sucesso e de erro; o banco guarda
   só o SHA-256 do token e da renovação. Renovação: o token de acesso vence em 1 hora, a renovação gira e nenhuma renovação
   passa dos 7 dias da autorização (14.c). **Tolerância** (9.1): duas renovações seguidas com a mesma renovação, pelo mesmo
   cliente, dentro da janela, dão um par novo e revogam o par emitido antes; fora da janela, ou por outro cliente,
   derrubam a concessão inteira; duas renovações ao mesmo tempo não emitem dois pares válidos (trava de linha).
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
12. **CIMD**: `client_id` diferente da URL, `redirect_uri` fora do documento, `redirect_uri` fora da lista exata da 3.4
    (mesmo que esteja no documento), host de `client_id` fora da configuração e endereço privado são recusados; a recusa de
    host grava no log `mcp` só o host; trocar a configuração muda a lista sem deploy de código; loopback com qualquer porta
    em `127.0.0.1` e `localhost` passa; a tela de consentimento mostra o host do `client_id`, não o `client_name`.
13. **Metadados**: as duas URLs `.well-known` respondem pelo Symfony (funcional) e o `nginx.prod.conf` tem a location
    `^~ /.well-known/oauth-` antes do bloqueio de arquivos ocultos (arquitetura). O metadado do servidor de autorização
    anuncia `refresh_token`, `offline_access` em `scopes_supported`, `token_endpoint_auth_methods_supported: ["none"]`,
    `issuer` exato `https://bluejus.com.br` e nenhum `registration_endpoint`; o metadado do recurso **não** traz
    `offline_access`.
14. **Anotações** (arquitetura): toda ferramenta tem `title` e as quatro dicas com valor explícito; as de leitura têm
    `readOnlyHint: true`; as de gravação e a `confirmar_acao` exigem `mcp:escrita`; a `confirmar_acao` tem
    `_meta["anthropic/requiresUserInteraction"]: true`; nenhuma ferramenta lê e grava.
15. **Log `mcp`** sem conteúdo: nenhuma linha leva texto de busca, título, nome, valor de campo, resumo de proposta ou
    conteúdo. Caso próprio: uma chamada de `atualizar_cliente` com CPF nos argumentos não deixa nenhum dígito dele no
    `mcp.log` (prova o `NullLogger` do SDK, 6.2.9).
16. **"Sair de todos os aparelhos" e troca de senha**: depois de cada um, o token de acesso é recusado no `/mcp` e a
    renovação recebe `invalid_grant` no `/oauth/token`, inclusive a renovação já rodada uma vez depois da autorização (prova
    que a cadeia continua presa ao `autorizado_em` da concessão). Troca de senha testada pelo perfil (`AlterarSenhaUseCase`) e pelo link
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
    conector, no mesmo processo, tem os bypasses de volta (com `$client->disableReboot()`, 6.3, item 2); o perfil de sistema
    no modo responde sim a permissão comum e não a `admin.*`.
20. **Confirmação** (6.7): cada ação marcada como exclusão, o cadastro e voltar ou reativar a fase devolvem proposta e não
    gravam nada (teste que percorre a lista de ações), inclusive **gravar por cima**, com cada ação da lista da abertura da
    6.6 (`editar_dados`, `nome_do_cliente`, `cadastro_da_pasta`, `marcador` editar, `marcadores_da_pasta` que tira um
    marcador, `nomear_etapa` que troca o nome, `registrar_na_pasta` `editar`, `salvar_prazo` que edita, `editar_documento`,
    `editar_peca`, `subpasta` renomear, `salvar_modelo` sobre modelo existente, `checklist_item` editar, `checklist_modelo`
    renomear, `valor_da_causa`, corrigir valor de pagamento, `completar` e `contato` que trocam, `dados_do_cartao`,
    `acao_da_pasta`, `nota_tecnica` editar, `salvar` de compromisso existente e `mover`): antes da confirmação nada muda
    (`editar_peca` não muda o arquivo nem o `sha256`); a mesma ação sobre campo vazio, acrescentar e marcar ou desmarcar
    executam direto; uma chamada que mistura campo vazio e troca vira uma proposta só, e um lote, uma proposta e uma
    confirmação; a proposta é recusada vencida, já usada, de outra pessoa, de outro escritório, de outro cliente OAuth, de
    consentimento revogado e com resumo diferente; duas confirmações ao mesmo tempo gravam uma vez;
    na confirmação, permissão retirada, aba ocultada ou pasta excluída nesse meio tempo recusam; o servidor nunca manda
    `elicitation/create` (fora do piloto, 6.7, item 2); depois de decidida ou vencida, a proposta não guarda dados.
21. **Esteira** (6.6, `mudar_andamento`): fase pelo nome e pelo número, nas duas vias; avançar até Contrato marca o contrato
    e a resposta diz isso só para quem vê o Financeiro; voltar e reativar viram proposta; cadeado em "Visualizar" recusa;
    pasta excluída recusa; nome de etapa com mais de 12 letras recusado e nome vazio vira proposta. Arquivar pela
    `atualizar_pasta` (`situacao`) leva a Finalizada, marca o contrato como assinado e a resposta diz isso só para quem vê o
    Financeiro; para quem tem o Financeiro oculto, nenhuma palavra sobre o contrato.
22. **Cadastro em lote** (6.6, `atualizar_cliente`): 1 a 50 itens, 51 recusado; CPF ou CNPJ já cadastrado, ou repetido
    dentro da própria lista, sai da proposta com o motivo; CPF que vira repetido entre a proposta e a confirmação dá resultado
    parcial, item por item; sem pasta exige `resources.carteira.gerenciar`; `completar` não troca valor existente sem
    proposta; a resposta traz nomes de campo, nunca valores. **Envio automático** (abertura da 6.6), com cliente que tem
    qualquer chave ligada: `completar` e `contato` com `email`, `vincular_a_pasta`, `principal`, desvincular o principal
    quando ele seria o promovido, `processos_da_pasta` `vincular` (novo, já cadastrado e apensado) em pasta cujo principal
    o tem ligado e `atualizar_pasta` `situacao` para Ativo e `restaurar` dessa pasta: cada um recusa a chamada inteira com
    a mensagem fixa e não grava nada; com a linha ausente ou as três chaves desligadas, todos gravam; a chave ligada entre a
    proposta e a confirmação recusa na confirmação; `cadastrar`, `criar` e `duplicar` não são afetados.
23. **Publicações**: ler a lista e o texto não muda o `lida` de nenhuma publicação; publicação de processo de outra pasta é
    recusada; aba Push oculta recusa.
24. **Liberação** (14.a): chave geral desligada, escritório não liberado pelo Master, conector não ligado no escritório ou
    aceite em versão antiga: cada um recusa no `/oauth/authorize` e no `/mcp`; desligar depois de conectar suspende na
    requisição seguinte (403 no `/mcp`, sem revogar; decisão 11 do PR 8); liberar o escritório A não libera o B. Sem registro, a chave geral vale como ligada e o escritório,
    como não liberado (o nível 2 nasce falso). O comando `conector:liberar` (PR 3) liga a liberação, o "ligado" e o aceite
    de um escritório, grava auditoria e não toca outro escritório.
25. **Tela que ganhou UseCase**: cada UseCase extraído de controller (6.4 e 6.6, "Falta") tem os testes funcionais da tela
    escritos antes e verdes depois, sem mudar o comportamento.
26. **Minhas Metas** (`ler_agenda`, `minhas_metas`, 14.i): só as metas da própria pessoa, na aba e no período pedidos, sem
    as de outro escritório e sem as de pasta em segredo (contadas); sem o módulo `tarefas` recusa; com a chave `metas`
    desligada recusa; escritório com o aceite da BlueJus I.A. e sem a liberação do conector recusa (a porta é a do
    conector), e o chat continua exigindo o aceite da I.A.; nada é gravado (nenhuma meta muda).
27. **Tela de consentimento** (9.4): POST sem token CSRF, ou com token errado, recusa; escritório trocado no formulário por
    um em que a pessoa não tem vínculo ativo recusa; parâmetros OAuth alterados entre o GET e o POST não valem (vêm da
    sessão). O pedido continua de uso único, mas o mesmo pedido enviado de novo até 10 minutos depois de **autorizado**
    (segundo clique enquanto a volta ao app carregava, achado do smoke de 18/11) mostra "Conexão feita" em vez do erro e
    não concede nada de novo; depois de **negado**, o reenvio segue recusado. A tela manda o formulário uma vez só.
28. **Tabelas e faxina** (13.2): `PurgaCoberturaSchemaTest` verde com as tabelas novas; purgar o escritório e excluir a conta
    levam as linhas pela cascata; a faxina apaga o que venceu além do prazo e não toca o que está em uso.
29. **Resposta** (6.2, regra 7): o `content` de cada ferramenta traz o mesmo dado do `structuredContent`; nenhuma
    ferramenta declara `outputSchema`; cada campo respeita o teto de 50 mil caracteres, com paginação.

---

## 13. Decomposição

### 13.1 PRs

Cada PR entrega software que funciona e é revisável sozinho. Revisto em 16/11/2026: sem estimativa em dias; o PR 4 entrou
no PR 3, e os números dos outros ficam iguais para não mudar as referências.

| # | Branch sugerida | Entrega | Depende de | Risco |
|---|---|---|---|---|
| 1 | `conector-portao` | Portão sem HTTP: barreiras do escritório extraídas do `TenantContextValidatorListener` para um serviço sem sessão que recebe se o passe do Master vale (o listener passa a usá-lo com o passe, sem mudar comportamento; o portão, sem o passe, 4.1), com o primário de escritório suspenso a pedido recusado no conector (seção 4, regra 1) e a conferência dos termos de uso (5.2); escritório e vínculo do token fixados no `TenantContext` sem sessão por um método que só o portão chama (`fixarSemSessao`, 4.1), o que resolve a auditoria (4.3); filtro ligado e conferido (fail-closed); **modo do conector** (6.3, item 2) com o perfil de sistema por regra fixa (tudo menos `admin.*`, sujeito a cadeado e abas, 6.3, item 3) e o teste de que o navegador não muda, com `disableReboot()`; teste de arquitetura | nada | ALTO, com re-revisão |
| 2 | `conector-tabelas` | `composer`: `mcp/sdk` **0.10.0** (versão exata) e `symfony/psr-http-message-bridge`, sem biblioteca OAuth (3.2); ajuste da v1 STDIO (`app/src/Mcp/`) às quebras de namespace da 0.10.0, com o `mcp:server` testado; entidades e **a única migration** (número dado pelo Coordenador) com as 7 tabelas de 13.2, FKs em cascata e purga do escritório coberta | nada (paralelo ao 1) | ALTO |
| 3 | `conector-autorizacao` | Servidor de autorização próprio: `/oauth/authorize` (login, escolha do escritório conferida no servidor, consentimento com escopos, CSRF e parâmetros na sessão, host do `client_id` na tela, tela pela convenção do sistema, 14.e, 9.4), `/oauth/token` com token opaco de 1 hora e renovação com rotação até 7 dias, janela de tolerância e trava de linha (14.c, 9.1), metadado RFC 8414, PKCE só S256, `resource` obrigatório, `iss` em toda resposta, concessão com `autorizado_em` e impressão da senha (seção 4, regra 3); **registro do cliente por CIMD** (busca segura, hosts de `client_id` em configuração, lista exata de `redirect_uri`, cache, recusa com o host no log; 3.4); liberação nos três níveis conferida na autorização (14.a) e o comando de console do Master `conector:liberar <tenant>` (liga a liberação, o "ligado" e o aceite, auditado), para conectar um cliente real antes do PR 8; comando de faxina no Scheduler (13.2); rotas nas listas dos dois listeners (8); conferência: o `client_id` e o `redirect_uri` reais que Claude e ChatGPT mandam | 1, 2 | ALTO, com re-revisão |
| 4 | (entrou no PR 3) | CIMD e lista de `redirect_uri` estão no PR 3; DCR fora do piloto (3.4) | | |
| 5 | `conector-mcp` | Firewall do `/mcp`, validador do token opaco com o portão a cada requisição (inclusive corte de "Sair de todos os aparelhos", impressão da senha e liberação nos três níveis), metadado RFC 9728 por controller nosso nas duas URLs, `/mcp` nas listas dos dois listeners (8), `StreamableHttpTransport` do SDK 0.10.0 **sem sessão**, `AuthorizationMiddleware` com `ScopePolicy` (403 `insufficient_scope` antes do SDK), `securitySchemes` e `_meta["mcp/www_authenticate"]` para o ChatGPT (5.1), `NullLogger` no SDK, nginx (3.3), limites de taxa (por pessoa, por escritório, documento e confirmação, 8), log `mcp`, rota com nome próprio para a auditoria, chave `conector_ia` no catálogo, `quem_sou_eu`, teste de arquitetura da lista fechada e das anotações | 1, 3 | ALTO, com re-revisão |
| 6 | `conector-leitura-pasta` | Registro de ferramentas próprio do conector (o compartilhado com o chat fica para depois do piloto, 6.8; o PR não mexe no despacho do chat); `buscar_pastas`, `ler_pasta` (menos publicações, timeline e controladoria), `ler_agenda` (com `minhas_metas` e a porta do aceite na guarda da consulta de prazos, 14.i); `LerAndamentoDaPastaUseCase`; segredo nas listas; máscara sempre ligada (6.2.6); tabela de aba e chave (6.6) com o teste que a percorre | 5 | MÉDIO |
| 7 | `conector-leitura-documentos` | `ler_documentos_e_modelos` com PDF e imagem (porta do aceite do conector, barreira da aba Documentos); `ler_pasta` parte `publicacoes`, sem marcar lida; `ler_processos` (buscar, detalhar, para vincular), com UseCase de leitura e movimentações paginadas | 6 | MÉDIO |
| 7b | `conector-leitura-texto` | Peça do editor, DOCX, DOC, ODT, RTF, TXT, CSV, XML e KML como texto (6.5): método novo no leitor, `ExtratorDeTextoDocx` num lugar comum | 7 | MÉDIO |
| 7c | `conector-leitura-formatos` | XLSX, XLS, ODS, PPTX, ODP, EML, ZIP, `.p7s`, TIFF e HEIC (6.5); a extensão de imagem nova é arquivo quente e passo de deploy | 7b | MÉDIO |
| 8 | `conector-telas` | Pela convenção do sistema (14.e): "Conexões de I.A." no Meu Perfil (listar e revogar); liberar por escritório na aba "Configurações da I.A." do Master; ligar e dar o aceite do conector, para o administrador do escritório. O comando `conector:liberar` do PR 3 continua | 2, 3 | MÉDIO |
| 9 | `conector-confirmacao` | Proposta (`conector_ia_proposta`), `confirmar_acao`, `_meta` de interação, escopo `mcp:escrita`, varredura das vencidas (6.7); sem elicitation (6.7, item 2) | 5 | ALTO, com re-revisão |
| 10 | `conector-escrita-pasta` | `atualizar_pasta`, `mudar_andamento`, `registrar_na_pasta`, `prazos_e_metas_da_pasta` (com o `CriarMetaUseCase` extraído do `TarefaController`) | 6, 9 | ALTO |
| 11 | `conector-escrita-clientes` | `ler_clientes` (UseCases de leitura extraídos) e `atualizar_cliente` (cadastro em lote por proposta, completar, contato, vincular extraído, principal, ligar documento) | 6, 9 | ALTO |
| 12 | `conector-escrita-documentos` | `documentos_e_pecas` e `financeiro_da_pasta` | 7, 9 | MÉDIO |
| 13 | `conector-escrita-processos-agenda` | `processos_da_pasta`, `gravar_na_agenda` (com o teto da Agenda) e a consulta ao CNJ com UseCase extraído | 7, 9 | MÉDIO |
| 14 | `conector-legado-restante` | UseCases extraídos de controller e as ações que dependem deles: relatórios do Expediente, timeline e controladoria da pasta, documentos do cliente (lista e exclusão), excluir cliente, excluir processo, renomear documento do Financeiro, lista de modelos de checklist | 11, 12, 13 | MÉDIO |
| 15 | `conector-abertura` | Guia passo a passo do usuário (Claude e ChatGPT, com a orientação sobre "sempre permitir", "Skip approvals" e o modo "Auto", a URL exata e o plano do ChatGPT para gravação, se confirmado), novidades, `mudancas/`, ajuste de limites depois do teste com os clientes reais (smoke do Samuel) | 1 a 13 (sem o 4) | BAIXO |

**Ponto de conferência do Samuel depois do PR 5, antes do PR 6:** o risco maior do piloto é interoperar (CIMD, `iss`,
`resource`, redirect, step-up), e o primeiro teste real só viria no PR 15. Com o PR 5 integrado e o escritório piloto
liberado pelo `conector:liberar` (PR 3), o Samuel conecta e chama `quem_sou_eu` no claude.ai, no Claude Code e no ChatGPT.
O que falhar volta para o PR 3 ou o 5 antes de seguir.

**Ordem:**

- **Caminho crítico até a abertura:** 1 → 3 → 5 → (smoke) → 6 → 7 → 7b → 7c → 15. O PR 2 corre ao lado do 1 e o 3 espera
  os dois; o 8 corre ao lado do 5; o 9 ao lado do 6; os PRs 10 a 13 correm ao lado do 7 ao 7c. **A abertura do piloto sai
  sem o PR 14**, ou seja, sem excluir cliente, excluir processo e os documentos do cliente (lista e exclusão), nem o resto
  da linha 14 da tabela; essas ações entram depois, quando o PR 14 for integrado.
- **Piloto com os três pedidos do Samuel** (A: pasta e esteira; B: documentos; C: cliente), no escritório do Farlei: depois
  dos PRs 1 a 3, 5 a 7b, 8, 9, 10 e 11 (sem o PR 14). B com PDF e imagem sai com o 7; com Word e peça do editor, com o 7b.

Regras do plano:

- **Uma migration só, no PR 2**, com as 7 tabelas de 13.2 (as decisões 14.a, 14.c e 14.g e as de 16/11/2026 já estão
  tomadas). Número dado pelo Coordenador, respeitando a regra de uma migration por vez entre os PRs abertos. Se um PR
  seguinte descobrir que precisa de coluna nova, pede ao Coordenador antes.
- Arquivos quentes (avisar o Coordenador no início de cada PR que os toca): `composer.json`/`composer.lock` (PR 2), a
  migration (PR 2), a configuração do Scheduler para a faxina (PR 3), `security.yaml` (PR 5), `framework.yaml` (PR 5),
  `nginx.prod.conf` (PR 5), o `Dockerfile` da imagem PHP (PR 7c).
- Os PRs 1, 3, 5 e 9 mexem em identidade ou na garantia da confirmação: revisão contra esta spec e re-revisão antes do
  seguinte. Os de gravação (10 a 14) são revisados contra a tabela da 6.6 e a lista de exclusões da 6.7.
- **Liberação no piloto:** a chave geral `conector_ia` já nasce ligada (14.a, nível 1); o Master libera só o escritório do
  Farlei (o nível 2, que nasce falso), pelo `conector:liberar` até o PR 8 e pela tela depois, e o administrador dele liga o
  conector e dá o aceite. Abrir a outro escritório espera o texto dos advogados (14.a).

### 13.2 Tabelas novas

Lista final da revisão de 16/11/2026 (decisão 3 da seção 14), numa migration só, no PR 2. Sem número de migration: o
Coordenador numera. Guardar só o **hash** (SHA-256) de código, token, renovação e id de proposta, nunca o valor.

Regras de todas:

- **`tenant_id` em toda tabela com dado de escritório** (todas menos `conector_ia_cliente`), com FK `ON DELETE CASCADE`
  para `tenant`; `user_id`, quando existe, com FK `ON DELETE CASCADE` para `user`; a FK para a tabela pai também em
  cascata. Exceções, para não apagar o que não é da pessoa: `conector_ia_escritorio.aceito_por_user_id` e
  `conector_ia_token.gerado_por_id` com `ON DELETE SET NULL`. `PurgaCoberturaSchemaTest` verde (9.9).
- **Faxina** (comando no Scheduler, que existe desde a v2.14.0; PR 3): apaga código e token vencidos há mais de 1 dia,
  concessão vencida ou revogada há mais de 30 dias (a cascata leva os códigos e tokens dela) e proposta decidida ou vencida
  (os dados dela já foram zerados, 6.7, item 6).
- Sem `audiencia` (o `resource` é conferido no código, 9.3), sem `ultimo_uso_em` em cliente e token (três `UPDATE` por
  chamada), sem as colunas do pré-registrado (`segredo_hash` e `tenant_id` do cliente, que voltam com o Gemini Enterprise,
  3.4).

| Tabela | Para quê | Colunas principais | Escritório |
|---|---|---|---|
| `conector_ia_cliente` | o app de I.A. registrado por CIMD | `identificador` (único; a URL do documento CIMD), `tipo` (`cimd` no piloto; `dcr` e `pre_registrado` ficam reservados para depois, 3.4), `nome` (o `client_name`, autodeclarado; a tela mostra o host do `identificador`, 9.4), `redirect_uris` (JSON, do documento), `metadados_buscados_em`, `metadados_validos_ate`, `ativo`, `criado_em` | **global por natureza** (o ChatGPT é o mesmo cliente para todos os escritórios): exceção declarada à regra "toda consulta filtra tenant"; nenhum dado de escritório passa por ela |
| `conector_ia_escritorio` | liberação do Master e aceite do escritório (14.a) | `tenant_id` (único), `liberado_pelo_master` (padrão falso), `ligado` (padrão falso), `aceite_em`, `aceite_versao`, `aceito_por_user_id`; `Auditavel` (quem liberou, ligou ou aceitou, e quando, fica no `audit_log`, como na `ConfiguracaoDeInteligencia`) | sim |
| `conector_ia_consentimento` | a "conexão" que a pessoa vê e revoga | `user_id`, `tenant_id`, `cliente_id`, `escopos`, `concedido_em`, `revogado_em`, `ultimo_uso_em` (gravado no máximo uma vez por minuto); uma ativa por pessoa, escritório e cliente; `Auditavel` (conceder, revogar) | sim |
| `conector_ia_concessao` | a cadeia: uma por autorização na tela (9.1) | `consentimento_id`, `tenant_id`, `user_id`, `escopos` (concedidos), `resource`, `autorizado_em` (instante da autorização; é o `criado_em`), `impressao_da_senha` (HMAC do hash da senha), `expira_em` (limite absoluto: 7 dias do `autorizado_em`), `revogada_em`, `motivo` (da revogação, de lista fechada) | sim |
| `conector_ia_codigo` | código de autorização | `identificador_hash` (único), `concessao_id`, `tenant_id`, `redirect_uri`, `code_challenge` (só S256), `criado_em`, `expira_em` (até 10 min), `usado_em` | sim |
| `conector_ia_token` | token de acesso e de renovação, opacos (14.c) | `identificador_hash` (único), `tipo` (`acesso`, de 1 hora, ou `renovacao`, até o `expira_em` da concessão), `concessao_id`, `tenant_id`, `escopos`, `gerado_por_id` (a renovação que gerou este par; nulo no primeiro par), `criado_em`, `expira_em`, `usado_em` (só renovação: o instante da rotação, que abre a janela de cerca de 60 s, 9.1), `revogado_em` | sim |
| `conector_ia_proposta` | a ação que espera confirmação (6.7) | `identificador_hash`, `user_id`, `tenant_id`, `consentimento_id`, `cliente_id`, `ferramenta`, `acao`, `dados` (JSON, zerado ao decidir ou vencer), `dados_hash`, `resumo` (zerado junto), `estado` (`pendente`, `confirmada`, `cancelada`, `expirada`), `resultado` (ids, sem texto), `criada_em`, `expira_em` (10 min), `decidida_em` | sim |

Como as tabelas se usam:

- **A cada requisição** (9.2): um `JOIN` token, concessão, consentimento confere o hash, o tipo `acesso`, a validade e as
  revogações dos três, o `autorizado_em` contra o corte de "Sair de todos os aparelhos", a impressão da senha e o
  escritório.
- **Derrubar a cadeia** (reuso fora da janela, falha na renovação, revogação pela pessoa) é um `UPDATE` na concessão; a
  rotação não copia nada (9.1).
- O consentimento é a conexão que a pessoa vê; cada nova autorização no mesmo consentimento abre uma concessão nova.

A chave geral `conector_ia` não precisa de tabela: entra no `CatalogoDeRecursos` e usa o registro que o `RecursosDoSistema`
já tem.

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
   ligada**: sem registro, `RecursosDoSistema::estado` devolve ativo (`app/src/Master/Service/RecursosDoSistema.php:53`),
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

Como fica: 17 ferramentas (6), o plano de PRs da 13.1 e teto próprio (6.3), que tira pelo conector os dois bypasses, como
o inventário classifica (seção 5, última linha). Para o Farlei, que é Master, isso quer dizer: pelo conector ele vê o que o
perfil dele no escritório vê, **sem nenhuma pasta trancada**. Master e primário nunca estão na lista do cadeado (a grade os
exclui) e o pedido de acesso não fura o cadeado, então pelo conector não há caminho para elas; pela tela, continuam
abertas (6.3, item 3). É aceito por ser o lado seguro.

**A abertura do piloto sai sem o PR 14** (13.1): excluir cliente, excluir processo e os documentos do cliente (lista e
exclusão), além do resto da linha 14 (relatórios do Expediente, timeline e controladoria da pasta, renomear documento do
Financeiro, lista de modelos de checklist), entram depois.

**c) Como os 7 dias funcionam. Decidido em 08/11/2026: opção 2.** Token de acesso de 1 hora, com renovação e rotação até
completar 7 dias da autorização. A pessoa faz login uma vez por semana, e um token vazado vale 1 hora. O metadado anuncia
`refresh_token` e `offline_access` (3.4, 5.1); a renovação entra na migration do PR 2 como `tipo` `renovacao` de
`conector_ia_token`, presa à concessão (13.2; revisão de 16/11/2026, que trocou a tabela `conector_ia_renovacao`).

**d) Clientes atendidos. Decidido em 08/11/2026: opção 1.** Claude e ChatGPT como conector personalizado (a pessoa cola a URL
`https://bluejus.com.br/mcp`). Gemini fica fora no piloto. A lista de hosts do `client_id` do CIMD começa em `claude.ai` e
`chatgpt.com`, em configuração, e a lista exata de `redirect_uri` é a dos dois clientes mais loopback para o Claude Code
(3.4; revisão de 16/11/2026, que tirou o DCR do piloto). Sem cliente pré-registrado e sem diretórios no piloto.

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
pergunta à pessoa; o mesmo com "Skip approvals" no conector ou o modo "Auto" da conversa no claude.ai (revisão de
16/11/2026). A elicitation, que seria a confirmação mais forte, ficou fora do piloto (6.7, item 2). Esse risco residual
fica aceito no piloto, está na seção 11, e o guia do usuário (PR 15) pede para não usar essas opções; a confirmação não é
absoluta.

**h) Partes da pasta que são de outro módulo (inventário, seção 1.13). Decidido em 09/11/2026.**

- **Entram:** criar meta para a pasta (`tarefa_criar_para_pasta` e a análise do prazo, em `prazos_e_metas_da_pasta`) e ler
  as publicações do Diário da pasta (em `ler_pasta`), **sem marcar lida**; e, pela 14.i, ler a lista de Minhas Metas.
- **Ficam fora:** a BlueJus I.A. dentro da pasta; todo envio ao cliente pelo Push (e-mail, link público, envio automático); o
  encaminhar pela Central; a Cobrança. O resto do Push que a seção 1.13 lista (marcar lida, monitoramento, buscar agora,
  "Avisar quando chegar", vincular publicação) não foi posto dentro e fica fora.
- **A mesma regra vale para qualquer ação dos quatro módulos que mande algo ao cliente ou a terceiro** (ex.: escolher os
  avisos por e-mail do cliente), **sem exceção para o Push automático**: todo caminho que estende o envio automático a um
  cliente que já o tem ligado recusa e a resposta diz para fazer pela tela (lista e código na abertura da 6.6): gravar o
  e-mail dele, `vincular_a_pasta`, `principal`, desvincular o principal quando ele seria promovido, `vincular` processo em
  pasta cujo principal o tem ligado, e reativar ou restaurar essa pasta. A **única exceção** é o Google
  Agenda: compromisso gravado vai para a agenda Google de quem criou e dos participantes que ligaram a conta, que são da
  equipe (`gravar_na_agenda`), e a resposta da ferramenta avisa.

**Decididas em 09/11/2026 (eram as duas perguntas em aberto; ficam as opções, para registro):**

**i) Ler a lista de Minhas Metas. Decidido em 09/11/2026: entra.**

`ler_pasta` lê as metas **de uma pasta**. A lista pessoal de Minhas Metas (todas as metas da pessoa, de todas as pastas, com
prazo) é do módulo Tarefas, fora dos quatro módulos, e a decisão h) só pôs dentro criar meta para a pasta. O Chat I.A já
tem essa leitura pronta e sem sessão (`ConsultarPrazosEMetasUseCase`, 4.2).

- Opção 1: fica fora. O assistente acha prazos pela agenda (`ler_agenda`, com prazos) e pela pasta.
- Opção 2: entra só a leitura, como parte `minhas_metas` de `ler_agenda`, pelo UseCase do chat (no PR 6). Concluir,
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
- Opção 2: entra com arquivo em base64, até cerca de 3 MB, pelo `UploadPecaUseCase` (no PR 12), sabendo que pode não
  funcionar nos clientes.

**Decidido em 09/11/2026: fica fora do piloto** (opção 1, a recomendada). O piloto mostra se faz falta; se fizer, a opção 2
entra num PR próprio depois de testada nos dois clientes.

**Decidido em 16/11/2026 (Coordenador, pela revisão de protocolo e pela revisão de código):**

1. **Servidor de autorização próprio** no BlueJus, sem `league/oauth2-server-bundle` e sem `league/oauth2-server`; token de
   acesso e de renovação **opacos**, guardados só como SHA-256; sem JWT e sem par de chaves. PKCE só S256 e obrigatório;
   `resource` (RFC 8707) obrigatório e conferido; `iss` na resposta de autorização;
   `token_endpoint_auth_methods_supported: ["none"]` no piloto (3.2, 3.4, 9.3, 9.6). O conflito entre token opaco e JWT
   que a spec tinha (13.2 com hash, 8 com chaves, 3.3 com JWKS) fica resolvido: opaco.
2. **`mcp/sdk` 0.10.0** (o lock tem 0.7.1): sem sessão por padrão; `ScopePolicy` para o 403 `insufficient_scope` antes do
   SDK; `NullLogger` no SDK. O PR 2 sobe a versão e ajusta a v1 STDIO (`app/src/Mcp/`). Saem o PR próprio da 2026-07-28 e o
   `Psr16SessionStore`, que a 0.10.0 tornou desnecessários (3.1, 3.5, 5.1, 6.2.9, 8).
3. **Tabelas**: sete, com a concessão como cadeia (`autorizado_em`, impressão da senha, validade de 7 dias) e um token
   único com `tipo` acesso ou renovação; as colunas que faltavam (consentimento, escopos, `resource`, cadeia, `criado_em`,
   `usado_em` com janela de cerca de 60 s na rotação); `tenant_id` em toda tabela com dado de escritório, FKs em cascata e
   comando de faxina agendado; sem as colunas que sobravam. Uma migration só, no PR 2 (13.2).
4. **Piloto mais curto**: o registro do cliente entra no PR 3 e o PR 4 some (CIMD com a lista exata de `redirect_uri` e os
   hosts de `client_id` em configuração; DCR fora do piloto, 3.4); elicitation fora do piloto, e o PR 9 fica com a proposta e
   a `confirmar_acao` (6.7); o registro de ferramentas compartilhado com o chat fica para depois (6.8); comando de console
   para liberar o escritório no PR 3 (13.1); CSRF e conferência do escritório no consentimento (9.4); `content` com o dado
   completo junto do `structuredContent` (6.2, regra 7); ChatGPT com `_meta["mcp/www_authenticate"]` e `securitySchemes`
   por ferramenta (5.1); o plano do ChatGPT para gravação fica como risco, não como regra (11).
5. **PR 1**: o escritório do token fixado no `TenantContext` sem sessão, por um método que só o portão chama; sem o passe do
   Master no portão; modo do conector com o perfil de sistema tendo tudo menos `admin.*`, sujeito ao cadeado e às abas, sem
   fallback pelo `PermissionFixture`; teste da requisição seguinte com `disableReboot()`. O escopo de auditoria do conector
   sai do PR 1 (4.1, 4.3, 6.3).
6. **Linhas de arquivo** corrigidas onde a revisão de código as achou deslocadas (cabeçalho).
7. **Sem estimativa em dias** no plano: ficam dependências, risco e o caminho crítico como sequência de PRs (13.1).

**Decidido em 16/11/2026 na implementação dos PRs 1 e 2 (orquestrador da frente, ratificado pelo Coordenador):**

1. **Recusa por vínculo no conector**: vínculo removido, vínculo ativo com situação diferente de "aprovado" ou escritório
   inexistente recebe a mensagem própria do conector (`AcessoDoConectorRecusado::ACESSO_INDISPONIVEL`), sem a frase
   "Selecione outro escritório", que só serve ao navegador. Vínculo em análise ou suspenso pela equipe recebe a mensagem
   da situação, igual à do navegador (ela não manda escolher escritório). O navegador continua com o texto dele.
2. **Renovação apresentada por outro cliente OAuth** derruba a concessão inteira **mesmo na primeira vez** (mais rígido que
   a 9.1, que fala de renovação já usada). A tolerância da janela de cerca de 60 s só vale para o mesmo cliente e enquanto a
   renovação gerada na rotação anterior não foi usada nem revogada; renovação revogada ou vencida que volta também derruba
   a concessão (9.2).
3. **Motivo da revogação da concessão**: lista fechada de quatro valores: `renovacao_reutilizada`, `falha_na_renovacao`,
   `revogada_pela_pessoa` e `conta_ou_vinculo_encerrado`. Valor novo pede ao Coordenador.
4. **Portão aberto uma vez por requisição**: `PortaoDoConector::abrir` chamado de novo na mesma requisição recusa, sem
   mexer no estado da primeira; `conferir` (usado no `/oauth/authorize`) não devolve o contexto que as ferramentas recebem.
   O `/mcp` (PR 5) encerra a requisição em **qualquer** exceção do `abrir()`, inclusive `LogicException`, sem tentar de
   novo; a trava contra reabrir só vale depois de uma abertura que deu certo, então é essa regra do PR 5 que fecha o
   caso de uma abertura que falhou no meio. Teste no PR 5.
5. **Código de autorização reapresentado** derruba a concessão inteira com um quinto motivo, `codigo_reutilizado`
   (aprovado pelo Coordenador em 16/11/2026; a coluna `motivo` é VARCHAR(40) sem CHECK, então não pede migration). A
   RFC 6749 (4.1.2) manda o servidor revogar o que foi emitido a partir de um código usado duas vezes: é sinal de que
   alguém o copiou. A linha do código é lida travada (`FOR UPDATE`) na troca.

**Decidido em 16/11/2026 no planejamento do PR 3 (orquestrador da frente, pela recomendação):**

1. **`security.yaml` e `framework.yaml` entram no PR 3**, e não só no PR 5: `PUBLIC_ACCESS` só para `^/oauth/token$` e
   `^/\.well-known/oauth-authorization-server$` (o `/oauth/authorize` fica no `ROLE_USER`), e os limites de taxa
   `conector_token` (por cliente e IP) e `conector_cimd` (por domínio do `client_id`). Os do `/mcp` ficam no PR 5.
2. **A chave `conector_ia` entra no catálogo no PR 3**, sem rota no mapa de rotas: o guarda de rota não pode
   redirecionar o `/oauth/*` nem o `/mcp`; quem confere a chave é a liberação nos três níveis, no código. A rota do
   Master que liga e desliga uma chave passa a aceitar sublinhado, senão o Master não conseguiria desligar esta.
3. **Depois do login, a pessoa volta ao `/oauth/authorize`** com o pedido inteiro, também quando tem vários
   escritórios (teste de identidade). Se ela ainda precisar aceitar os Termos de Uso, o pedido se perde depois do
   aceite: aceito no piloto, porque é raro e o app de I.A. deixa conectar de novo.
4. **O `/oauth/authorize` roda sem o filtro de escritório da sessão**: a tela escolhe um escritório que pode não ser o
   da sessão e lista os escritórios da pessoa. Toda consulta dela passa o escritório explícito. O `/oauth/token`
   também (achado do teste ponta a ponta): ele não usa sessão, e um cookie que chegue junto não pode prender a troca
   ao escritório da sessão; ali o escritório vem da concessão.
5. **Reautorizar o mesmo app no mesmo escritório atualiza os escopos** do consentimento ativo (sem migration); as
   concessões já abertas guardam os escopos delas.
6. **Código de autorização reapresentado**: a linha fica travada na troca e a concessão cai com
   `codigo_reutilizado` (item 5 do bloco anterior).
7. **`offline_access`**: a renovação é emitida sempre, peça o app ou não esse escopo.
8. **O comando `conector:liberar` exige `--master`** com o e-mail de um Master, conferido como Master; quem aparece
   como autor do aceite é esse Master.
9. **A conferência da concessão** (pessoa, escritório, corte de "Sair de todos os aparelhos", impressão da senha e
   liberação) roda também na troca do código, não só na renovação. Desde o PR 8, a renovação não confere a liberação
   (decisão 11 do PR 8).
10. **Escopo desconhecido no pedido é ignorado**: o servidor emite só os escopos que conhece entre os pedidos, e a
    resposta do `/oauth/token` sempre diz quais foram, por interoperabilidade com os apps.

**Decidido em 16/11/2026 na revisão dos blocos do PR 3 (orquestrador da frente, pela recomendação):**

11. **Documento CIMD gravado quando a renovação falha**: se o cache do cliente já gravado venceu e a nova busca falha
    por rede, status ou limite de buscas, o servidor segue com o documento gravado por até 24 horas depois do
    vencimento (no estilo da RFC 5861) e registra no log `mcp`. Documento inválido recusa sempre. O limite de buscas
    continua por domínio: no piloto aceita-se que uma conta logada consiga atrasar o registro de um app NOVO.
12. **O cache do documento CIMD desconta o cabeçalho `Age`** (RFC 9111, 4.2.3) antes do piso de 5 minutos e do teto
    de 24 horas.
13. **As rotações de uma mesma cadeia saem uma de cada vez**: a renovação trava primeiro a linha da concessão e depois
    a do token, sempre nessa ordem. Sem isso, a renovação mãe reapresentada na janela de 60 s e a filha girando ao
    mesmo tempo deixavam dois pares válidos. O token de acesso grava a validade já limitada ao fim da concessão; campo
    repetido no `/oauth/token` dá `invalid_request` (RFC 6749, 3.2); a proposta decidida sai na faxina só depois de 1 dia.
14. **A volta do login ao `/oauth/authorize` pula a tela de entrada** de quem tem vários escritórios: a tela de
    autorização é quem escolhe o escritório. Qualquer outra página continua passando pela seleção de escritório.
15. **Escritório ou pessoa recusados ao autorizar** voltam ao app com `access_denied` (com `state` e `iss`), em vez de
    uma página de erro que deixaria o app esperando. Autorizar sem escolher o escritório volta à tela com aviso, sem
    gastar o pedido. Duas autorizações ao mesmo tempo da mesma pessoa saem uma de cada vez (a linha da pessoa é
    travada), sem erro na primeira conexão.

**Decidido em 16/11/2026 no planejamento do PR 5 (orquestrador da frente, pela recomendação; o step-up, aceito pelo
Coordenador):**

1. **Resolução dupla mantida**: o validador do token confere a concessão inteira (`ConferenciaDaConcessao::exigir`, que
   já passa pelo `PortaoDoConector::conferir`) e depois o middleware do portão chama `PortaoDoConector::abrir`, que
   resolve a pessoa e o escritório de novo e tranca a requisição. Teste de arquitetura: só o middleware do portão chama
   `abrir`.
2. **Um juiz só**: o validador guarda o resultado da requisição (o autenticador do firewall e o `AuthorizationMiddleware`
   leem o mesmo), e só o SDK formata o 401 e o 403 com o `WWW-Authenticate`.
3. **Recusas**: concessão recusada (`ConcessaoRecusada`) e acesso recusado pelo portão por pessoa, escritório, termos
   ou liberação (`AcessoDoConectorRecusado`) dão 401 `invalid_token` com mensagem fixa (desde o PR 8, a liberação dá
   403 sem desafio: decisão 11 do PR 8); filtro de escritório que não
   ligou, requisição já aberta ou qualquer erro inesperado dão 503 sem desafio.
4. **O portão roda na cadeia PSR-15**, depois do `AuthorizationMiddleware`: a `ScopePolicy` barra o escopo antes de
   qualquer abertura.
5. **`securitySchemes` só no `_meta`** de cada ferramenta (o SDK 0.10 não tem o campo no topo da ferramenta). Item do
   smoke no ChatGPT.
6. **Sem assinatura de eventos** (nenhum barramento de notificações), com `setSubscriptionLifetime(1.0)`. O valor 0
   do planejamento estava errado: no SDK 0.10 o 0 é assinatura sem fim (`StatelessProtocol::listen`). O escolhido é
   1 s, para nunca segurar um processo do PHP-FPM se um dia houver notificações; hoje, sem barramento, o
   `subscriptions/listen` já encerra no reconhecimento.
7. **Os números dos limites** do `/mcp` (`conector_chamada` 120 por minuto por pessoa e escritório,
   `conector_chamada_escritorio` 600, `conector_documento` 20, `conector_confirmacao` 10) são ajustáveis no PR 15,
   depois do smoke com os apps reais.
8. **A rota do `/mcp` é `stateless: true`**, além do firewall sem sessão: o Symfony acusa qualquer uso da sessão nela.
   O `IdiomaDaRequisicaoListener` não toca a sessão em requisição sem sessão (fica no português).
9. **Step-up no ChatGPT**: aceito no piloto o 403 com `_meta["mcp/www_authenticate"]` num corpo JSON-RPC, como sai do
   SDK; conferido no smoke. Plano B, se o ChatGPT não pedir o escopo que falta: a pessoa reconecta marcando a escrita.
10. **nginx**: a `location ^~ /.well-known/oauth-` entra no `nginx/conf.d/nginx.prod.conf`, que é montado por pasta; o
    deploy (`scripts/deploy-prod.sh`) roda `nginx -t` e recarrega o nginx sozinho. Sem passo manual, salvo se o deploy
    avisar "'nginx -t' REPROVOU" (aí `docker exec jusprime_nginx_prod nginx -t` mostra o erro).

**Decidido em 16/11/2026 na revisão dos blocos do PR 5 (orquestrador da frente, pela recomendação):**

11. **A descrição do 401 é ASCII e fixa** no `WWW-Authenticate` (RFC 6750, 3), uma por categoria (token, autorização,
    escritório, termos, liberação), sem nome de escritório nem de pessoa; o motivo detalhado fica só no log `mcp`.
12. **Preflight (`OPTIONS`) recebe 204 sem passar pelo portão**: o CORS do SDK não encerra o preflight e o portão
    responderia 503. Origem de fora continua barrada com 403 pela proteção contra DNS rebinding (CORS fechado, 8).
13. **Rota sem sessão não toca a sessão em nenhum listener**: `ExpiracaoDaSessaoListener`,
    `SessaoEncerradaEmTodosOsAparelhosListener` e a resolução da pasta da rota (`PastaDaRota`) saem cedo quando a rota
    é `_stateless`. Sem isso, um cookie de sessão que chegasse ao `/mcp` abria a sessão (12.18).
14. **Step-up de pedido sem `id`** (notificação ou lote) sai como erro JSON-RPC (`id` nulo, código -32000) com o `_meta`
    em `error.data`; com `id`, como resultado com `_meta`. O 429 também leva um corpo JSON-RPC com mensagem fixa.
15. **Aceitos no piloto**: campo fora do esquema da ferramenta é barrado pelo próprio SDK (erro -32602, em inglês);
    as versões antigas do protocolo que o SDK aceita no handshake ficam aceitas, além das duas da 3.5; o mapa de
    ferramenta para módulo do `quem_sou_eu` é aproximado até o PR 6 trazer a tabela por ação. **Smoke**: conferir se
    algum app de I.A. manda `Origin` de servidor a servidor (a proteção contra DNS rebinding daria 403).
16. **Ressalvas da re-revisão do PR inteiro**, aceitas no piloto:
    - O metadado do recurso (as duas URLs) segue no firewall `main`, sem `stateless`, como o metadado do PR 3. Com
      `stateless`, o `TenantFilterListener` e o `SessaoDeContaAConfirmarListener` usariam a sessão e o Symfony lançaria
      em debug. Os apps chamam sem cookie; com cookie de navegador logado, a sessão pode ser renovada, sem efeito no
      documento.
    - Se o `PortaoDoConector::abrir()` recusar pessoa ou escritório depois do validador aceitar (corrida na mesma
      requisição), a resposta é 503, não 401. Falha fechada: nada é despachado.
    - Lote JSON-RPC: a `ScopePolicy` só vê o escopo padrão, e quem recusa o lote é o SDK (400).
      `McpControllerTest::testLoteNaoChegaAoDespacho` prende isso para os PRs 9 a 13 e para uma troca de SDK.

**Decidido em 16/11/2026 no PR 8 (orquestrador da frente, pela recomendação; a 11 com o Coordenador):**

1. **Meu Perfil** lista as conexões de I.A. de todos os escritórios da pessoa, com o escritório na linha.
2. Conexão de escritório com **vínculo caído** (suspenso ou removido) aparece marcada e pode ser revogada.
3. O administrador **só dá o aceite depois da liberação do Master**: sem o nível 2, botão Ligar desabilitado e aviso de
   aguardando liberação.
4. **Desligar ou bloquear não revoga** as conexões: só suspende (ver 11).
5. O administrador **não vê nem revoga** as conexões da equipe no piloto.
6. O Master vê só a **contagem** de conexões ativas por escritório, nunca as pessoas.
7. **Texto do aceite provisório** (versão 1) até o jurídico; não abrir a outro escritório antes dele.
8. Aceite com **caixa "Li e aceito" obrigatória** e botão Ligar, como na BlueJus IA. Aceite de versão antiga mostra o
   estado de novo aceite (caixa, Ligar e Desligar).
9. **Revogar pede confirmação** (modal do perfil), revoga o consentimento e as concessões dele na mesma transação, com
   `revogada_pela_pessoa`, e trava a conexão para dois cliques não gravarem duas vezes.
10. **Exclusão de conta ou vínculo** (9.9, `conta_ou_vinculo_encerrado`) fica fora do PR 8; o CASCADE cobre a exclusão
    física.
11. **Suspensão sem revogar** (aprovada pelo Coordenador). Liberação desligada (chave geral, nível 2, nível 3 ou aceite
    antigo) é zero acesso na hora e não derruba a conexão:
    - a renovação (`refresh_token`) não confere a liberação (`ConferenciaDaConcessao::exigirNaRenovacao`); continua
      conferindo pessoa, vínculo, senha, "Sair de todos", termos, `resource` e o limite de 7 dias, que corre durante a
      suspensão. A troca do código e o `/oauth/authorize` continuam conferindo a liberação;
    - o `/mcp` responde **403 sem `WWW-Authenticate`**, com erro JSON-RPC -32001 "Conector desligado neste escritório."
      (ASCII escapado), sem despachar nada. Qualquer desafio (`invalid_token` ou `insufficient_scope`) levaria o app a
      renovar e pedir reconexão. Os outros motivos de recusa continuam 401 (decisão 3 do PR 5);
    - o formato sai do `ConectorDesligadoMiddleware`, entre o escopo que falta e o `AuthorizationMiddleware`: a cadeia
      passa a 9 middlewares. O validador continua o único juiz.
    **Smoke**: conferir como o Claude e o ChatGPT mostram esse 403.
12. **Filtro do escritório fora do portão** só pelo `SemFiltroDoEscritorio`, que suspende e devolve e nunca liga (o
    `PortaoDoConectorArquiteturaTest` confere). Usado pelas buscas das telas e pelo `revogarDoConsentimento`, porque o
    `TenantFilter` também entra no WHERE de um UPDATE em DQL.
