# MCP remoto com OAuth 2.1 — todos os escritórios

**Data:** 2026-08-11
**Risco:** **ALTO** — mexe em identidade (`User`/`Tenant`) e cria um caminho novo de acesso aos
dados de todos os escritórios. Pelo ciclo do projeto, exige spec (este documento), revisão
contra a spec e **re-revisão antes de seguir**.
**Status:** desenho para ser planejado e implementado por outra sessão. **Não implementado.**

**Predecessor:** [MCP de investigação em produção (v1)](../superpowers/specs/2026-08-10-mcp-investigacao-prod-design.md)
— já em produção, e a seção 10 daquele documento previa esta etapa.

---

## 1. O que existe hoje, e por que não serve

A v1 está em produção: um comando `mcp:server` falando MCP por **STDIO**, lançado por
`ssh bluejus 'docker exec -i -w /var/www/app jusprime_php_prod php bin/console mcp:server'`.
Duas ferramentas: `consultar_sql` (SQL livre) e `descrever_esquema`.

Ela foi desenhada para **um operador: o dono**. Três propriedades a tornam inadequada para
outros usuários, e nenhuma delas é acidental:

1. **`consultar_sql` lê o banco inteiro.** Sem filtro de tenant, sem `PermissionChecker`, sem
   UseCase. Foi decisão consciente registrada na spec da v1. Com um segundo usuário conectado,
   isso deixa de ser recurso e vira vazamento de dado de todos os escritórios.
2. **O transporte é SSH como `root` na VPS.** Quem tem a chave é dono da máquina; a tranca de
   leitura no banco vira irrelevante. Não é distribuível.
3. **Não há identidade.** O servidor não sabe quem está perguntando — só que alguém alcançou o
   container.

**Consequência de projeto, e é a decisão mais importante deste documento:** a versão remota
**não é a v1 com transporte novo**. É outro conjunto de ferramentas. `consultar_sql` **não vai
para o servidor remoto** (ver seção 6).

---

## 2. Objetivo

Permitir que qualquer usuário de qualquer escritório do BlueJus conecte o Claude (ou outro
cliente MCP) ao sistema, autenticando-se **como ele mesmo**, e enxergue e opere **exatamente o
que a conta dele já permite** na interface web — nem mais, nem menos.

Critério de aceitação central: **um usuário do escritório A não consegue, por nenhum caminho,
ler ou alterar dado do escritório B.** Provado por teste cross-tenant, não por inspeção.

---

## 3. Arquitetura

```
Cliente MCP (Claude)
   │  1. descobre metadados     GET /.well-known/oauth-protected-resource
   │  2. login + consentimento  GET /oauth/authorize   (PKCE)
   │  3. troca código por token POST /oauth/token
   │  4. chamadas MCP           POST /mcp   (Authorization: Bearer <JWT>)
   ▼
nginx (bluejus.com.br, TLS)
   ▼
Symfony — mesma aplicação, dois papéis:
   ├── Authorization Server  → emite os tokens (identidade já mora aqui)
   └── Resource Server (MCP) → valida o token e executa as ferramentas
   ▼
UseCases / Repositories existentes, com tenant e permissão aplicados
```

### 3.1 O SDK resolve metade, e é explícito sobre qual metade

O `mcp/sdk` documenta que o servidor MCP é um **Resource Server**: valida o bearer token,
serve Protected Resource Metadata (RFC 9728) e emite `WWW-Authenticate`. Ele oferece
`AuthorizationMiddleware`, `JwtTokenValidator`, `JwksProvider`, `OidcDiscovery` e
`ProtectedResourceMetadata`, além do `StreamableHttpTransport` (PSR-7/PSR-15).

**Ele não emite token e declara isso como fora de escopo** (há ADR no repositório do SDK:
`adr/0001-oauth-authorization-server-out-of-scope.md`). Portanto o Authorization Server é
trabalho nosso.

### 3.2 O Authorization Server é o próprio BlueJus

**Decisão: `league/oauth2-server-bundle` dentro da aplicação Symfony.**

O motivo é identidade: `User`, `UserTenant` e `Tenant` já vivem no BlueJus, com senha, convite,
recuperação e vínculo por escritório. Um IdP externo (Keycloak, Auth0, Entra) exigiria um
segundo diretório de usuários e um mecanismo de federação — mais peças, mais estados
inconsistentes, e um lugar novo para o vínculo usuário↔escritório divergir.

O bundle é testado contra Symfony 6.4, 7.3, 7.4 e 8.0 — compatível com o 7.4 daqui.

**Alternativa a reavaliar na hora de implementar:** o `symfony/mcp-bundle` tem uma proposta
aberta para atuar como Authorization Server (`symfony/ai` issue #2134, PR rascunho #2135), com
os endpoints `/.well-known/oauth-authorization-server`, `/.well-known/jwks.json`,
`/oauth/authorize`, `/oauth/token` e `/oauth/register`, e com a decisão de projeto exatamente
alinhada ao que precisamos: *"o usuário autenticado do firewall vira o subject OAuth"*.
**Não estava liberado em 11/08/2026.** Se tiver saído, compare antes de escrever código — pode
eliminar boa parte desta spec. Se não tiver, siga com o `league`.

### 3.3 Endpoints

| Endpoint | RFC | Quem serve |
|---|---|---|
| `/.well-known/oauth-protected-resource` | 9728 | SDK (`ProtectedResourceMetadataMiddleware`) |
| `/.well-known/oauth-authorization-server` | 8414 | Nosso AS |
| `/.well-known/jwks.json` | 7517 | Nosso AS |
| `/oauth/authorize` (com PKCE obrigatório) | 6749 + 7636 | Nosso AS |
| `/oauth/token` | 6749 | Nosso AS |
| `/oauth/register` | 7591 | Ver 3.4 |
| `/mcp` | — | SDK (`StreamableHttpTransport`) |

**Verificado:** o `nginx.prod.conf` roteia `location / { try_files $uri /index.php... }`, e o
bloco `^~ /.well-known/acme-challenge/` do Let's Encrypt é escopado só no `acme-challenge`.
Os endpoints de metadados chegam ao Symfony **sem mudança de nginx**. Confirme mesmo assim no
primeiro deploy: `.well-known` capturado por regra estática é o modo de falha clássico dessa
integração.

### 3.4 Registro dinâmico de cliente (DCR) — decidir cedo

A especificação de autorização do MCP prevê que o cliente se registre sozinho via RFC 7591.
O `league/oauth2-server-bundle` **não traz DCR pronto**.

Duas saídas, e a escolha muda a experiência do usuário:

- **(a) Implementar `/oauth/register`** — endpoint público que cria um cliente OAuth por
  demanda. É o que dá o "conectar com um clique" em qualquer cliente MCP. Exige cuidado com
  abuso (rate limit, expiração de clientes não usados).
- **(b) Cliente pré-registrado** — o BlueJus cadastra um `client_id` fixo para o Claude, e o
  usuário só faz login. Mais simples e mais fechado; quebra se o cliente MCP exigir DCR.

**Recomendação: (b) na primeira fatia, (a) quando houver demanda de outro cliente.** Começar
por (b) permite provar o fluxo inteiro sem construir superfície pública nova.

---

## 4. O problema do escritório (tenant) — o coração desta spec

Um `User` pertence a **N** escritórios via `UserTenant`. Um token OAuth identifica **um
usuário**. Portanto o token, sozinho, não diz de qual escritório a pergunta está falando.

Três opções foram consideradas:

| Opção | Como | Veredito |
|---|---|---|
| Tenant como **parâmetro de cada ferramenta** | O modelo informa o escritório na chamada | ❌ **Rejeitada.** Uma alucinação do modelo troca de escritório sozinha. |
| Tenant **escolhido no consentimento**, virando claim do token | Tela de autorização pergunta "qual escritório?"; o `tenant_id` entra no token | ✅ **Escolhida.** |
| Tenant **inferido** (o único, ou o último usado) | Implícito | ❌ Falha silenciosa para quem tem mais de um. |

**Decisão: uma conexão MCP = um escritório**, fixado no momento da autorização e carregado como
claim no token. Para operar outro escritório, o usuário autoriza outra conexão.

**Regras que sustentam isso:**

1. Na emissão do token, validar que existe `UserTenant` **ativo** entre o usuário e o tenant
   pedido, e que o `Tenant` está ativo — a mesma checagem de `TenantContext::setCurrentTenant()`.
2. A cada requisição MCP, resolver `User` + `Tenant` a partir do token e **ligar o filtro
   Doctrine explicitamente**.
3. Revogação: se o vínculo `UserTenant` for desativado, ou o usuário for desativado, os tokens
   dele param de funcionar — ver seção 9.

### 4.1 🔴 O `TenantFilter` falha ABERTO e isso precisa mudar neste caminho

`app/src/Shared/Doctrine/Filter/TenantFilter.php` documenta no próprio corpo:

> *"Sem o parâmetro `tenant` setado, o filtro é inerte (não restringe nada)"*

Ele é ligado por `TenantFilterListener` no `kernel.request`, lendo `TenantContext`, que lê a
**sessão HTTP**. Uma requisição autenticada por bearer token **não tem sessão**. Se nada for
feito, o filtro fica desligado e **toda consulta devolve dados de todos os escritórios, sem
erro nenhum**.

**Este é o defeito mais perigoso da feature inteira**, porque falha em silêncio e com aparência
de sucesso.

**Não mexa no `TenantFilter`.** O comportamento inerte é intencional e as importações da
contábil em CLI dependem dele. A correção é **trancar a porta nova**:

- Um listener/middleware próprio do caminho MCP que resolve o tenant do token e liga o filtro.
- Se o tenant não puder ser resolvido, **a requisição é recusada** — nunca segue com filtro
  desligado.
- Um teste que prove a recusa, e um teste cross-tenant que prove que A não vê B.

Recomendação forte: uma trava do tipo *fail-closed* explícita (verificar, depois de resolver o
contexto, que o filtro está habilitado **e** com parâmetro), no espírito da invariante que a v1
já usa em `ConexaoLeitura` para recusar um usuário de banco capaz de escrever.

---

## 5. Autorização: reusar o que existe, sem contornar

`app/src/Service/PermissionChecker.php` recebe `User $user, ?Tenant $tenant` como **parâmetros
explícitos** — não depende de sessão. É reutilizável inteiro. Os quatro métodos públicos:
`canAccessModule`, `canAdminister`, `canAccessResource`, `hasPermission`.

**Toda ferramenta MCP passa pelo `PermissionChecker` com o usuário e o escritório do token.**

Três coisas que o implementador precisa saber, todas documentadas em `docs/AUTORIZACAO.md`:

1. **Existem dois bypasses**: `ROLE_SUPER_ADMIN` (global) e `TenantRole.isSystem()` (por
   tenant). Eles valem aqui também — e isso é correto, mas precisa estar consciente: um
   super-admin conectando o MCP enxerga tudo do escritório escolhido.
2. **A autorização fina hoje mora nos controllers**, não no `security.yaml` (que só exige
   `ROLE_USER` a partir de `^/`). Uma ferramenta MCP que chame UseCase direto **pula essa
   camada** — a checagem tem que ser feita explicitamente na ferramenta.
3. `resources.{x}.{y}` (recursos-tipo) é **código morto** e `canActOnResource` também. Não
   construa em cima deles.

### 5.1 Escopos OAuth × permissões do sistema

São eixos diferentes e **os dois valem**:

- **Escopo** diz o que *aquela conexão* pode fazer: `mcp:read`, `mcp:write`.
- **Permissão** diz o que *aquele usuário* pode fazer naquele escritório.

Regra: a ferramenta só executa se **ambos** permitirem. Escopo nunca amplia permissão. Um
usuário sem `admin.x` não ganha `admin.x` por ter token com `mcp:write`.

---

## 6. Superfície de ferramentas

### 6.1 O que NÃO entra

**`consultar_sql` não vai para o servidor remoto.** Não há como oferecer SQL livre e garantir
isolamento entre escritórios: filtrar SQL arbitrário por texto é exatamente a abordagem que a
v1 rejeitou por ser burlável.

A v1 continua existindo, por STDIO/SSH, **só para o dono**. As duas coexistem: uma é ferramenta
de investigação do operador, a outra é funcionalidade de produto.

### 6.2 Leitura (primeira fatia)

Todas com tenant e permissão aplicados, devolvendo JSON estruturado:

| Ferramenta | O que faz |
|---|---|
| `buscar_cliente` | por nome, CPF ou CNPJ; devolve dados do cliente e vínculos |
| `detalhar_pasta` | seções, checklist, documentos, processos vinculados |
| `buscar_processo` | por número CNJ; com movimentações recentes |
| `situacao_cobranca` | objeto de cobrança, obrigações em aberto, acordos, pagamentos |
| `minhas_demandas` | o que está na mão do usuário autenticado |

O domínio **Pasta** já tem UseCases (`ListarMinhasDemandasUseCase` e outros ~30) e é o caminho
mais curto. **Cliente e Processo são legado** — só `Controller/ Entity/ Form/ Repository`, sem
`UseCase/`. Ler é viável pelos Repositories; ver a seção 7 antes de escrever.

### 6.3 Escrita (segunda fatia)

Só ações **reversíveis**, cada uma com **confirmação explícita** e **auditoria**:

| Ferramenta | UseCase | Observação |
|---|---|---|
| `criar_pasta` | `CriarPastaUseCase` (existe) | caminho mais barato |
| `anotar_em_pasta` | `EnviarObservacaoDetalhesUseCase` (existe) | reversível |
| `criar_tarefa` | avaliar o domínio Tarefa | |

**Fora desta spec:** qualquer escrita em **ponto eletrônico** (risco ALTO por definição no
projeto), exclusões, e cadastro de cliente a partir de documento — este último exige extrair um
`CriarClienteUseCase` do `ClienteController` (557 linhas) e resolver os campos obrigatórios que
um RG não traz (`email`, `cep`, `endereco`), conforme já registrado na spec da v1.

**Confirmação não é opcional.** Uma escrita disparada por interpretação errada do modelo tem
que exigir um passo em que o humano vê o que vai acontecer. Como fazer isso no protocolo (uso
de *elicitation* do MCP, ou ferramenta em dois passos "preparar"/"confirmar") é decisão do
plano — o SDK tem exemplo de `elicitation`.

---

## 7. Legado que a feature encosta

- **Cliente e Processo não têm camada `UseCase/`.** A regra mora nos controllers. Para
  **leitura** dá para usar os Repositories direto, com o filtro de tenant ligado. Não refatore
  o legado por causa desta feature — mas também não replique regra de negócio dentro da
  ferramenta MCP; se precisar de regra, extraia UseCase e diga isso no plano.
- **`Cliente` usa herança JOINED** (`ClientePF`/`ClientePJ`, `DiscriminatorMap`). Consultas
  precisam considerar as duas.

---

## 8. Transporte e infraestrutura

- **`StreamableHttpTransport`** do SDK, PSR-7/PSR-15. O SDK auto-descobre fábricas PSR-17;
  `guzzlehttp/psr7` já está no vendor.
- **O middleware padrão do transporte é restritivo por padrão**: CORS sem
  `Access-Control-Allow-Origin`, proteção contra DNS rebinding limitada a localhost, e
  validação de `MCP-Protocol-Version`. Para um servidor público isso **precisa ser
  reconfigurado conscientemente** — não copie o exemplo de servidor local.
- **Sessão MCP**: o SDK tem `InMemorySessionStore` (padrão), `FileSessionStore` e
  `Psr16SessionStore`. Em produção com múltiplos workers, in-memory não serve. Decidir no
  plano.
- **`/mcp` fica fora do firewall de sessão.** O `security.yaml` hoje exige `ROLE_USER` a partir
  de `^/`; o caminho MCP autentica por bearer, não por cookie. Precisa de firewall próprio ou
  `access_control` explícito — e o `/oauth/authorize` precisa do firewall de sessão (é lá que a
  pessoa faz login).
- **Limite de taxa** em `/oauth/token` e `/mcp`. O projeto já usa `login_throttling`; seguir o
  mesmo espírito.

---

## 9. Segurança

1. **Vida do token: 7 dias — decidido pelo dono (11/08/2026).** Escolha de comodidade: a pessoa
   autoriza uma vez por semana, não todo dia.
2. **Revogação imediata, e ela é o que torna os 7 dias aceitáveis — decidido.**
   O token é um papel assinado: diz "usuário X, escritório Y" e **não se atualiza** quando a
   realidade muda. Desativar alguém não altera o token que ele já tem na mão.
   Por isso, **a cada requisição MCP** (não uma vez na conexão), o servidor confere **no banco**
   que o `UserTenant` está ativo e que o `User` está ativo — e recusa se não estiver, mesmo com
   token dentro da validade.
   Custa praticamente nada: o servidor já precisa carregar `User` + `Tenant` em toda requisição
   para aplicar permissão; a conferência é ler um campo do registro que já veio.
   **Sem isso, um token de 7 dias significa 7 dias de acesso para quem acabou de ser
   desligado** — é a diferença entre uma decisão de conforto e um buraco de segurança.
   Não implemente o token de 7 dias sem a checagem por requisição; as duas coisas são uma só
   decisão.
3. **Audience binding (RFC 8707)**: o token emitido para o MCP não pode valer para outra coisa.
4. **Confused deputy**: com DCR e clientes dinâmicos, exigir consentimento por cliente; não
   reaproveitar consentimento entre `client_id` diferentes.
5. **Auditoria**: toda chamada de ferramenta registra usuário, escritório, ferramenta e
   argumentos. O projeto já tem domínio `Auditoria` e canal Monolog `audit` — usar, não
   inventar.
6. **PII**: as respostas carregam dado pessoal de cliente e trafegam para o provedor do modelo.
   Isso já é verdade no uso web? **Não** — é caminho novo. Merece uma linha explícita nos termos
   de uso do BlueJus e decisão do dono antes de abrir para os escritórios.

---

## 10. O que fica fora desta spec

- `consultar_sql` remoto (seção 6.1)
- Escrita em ponto eletrônico
- Cadastro de cliente por leitura de documento de identificação
- Substituir a v1 STDIO/SSH — ela continua, para o dono
- Cliente MCP próprio do BlueJus (só somos servidor)

---

## 11. Riscos conhecidos

| Risco | Por que importa |
|---|---|
| **`TenantFilter` fail-open** | Rota nova que esqueça de amarrar o tenant devolve tudo, sem erro. É o item nº 1 do plano. |
| **`mcp/sdk` é experimental (0.7.0)** | API pode mudar entre versões menores; manter versão fixa, como a v1 faz. |
| **AS dentro da aplicação** | Uma falha no servidor de autorização é uma falha no sistema inteiro, não num serviço isolado. |
| **`symfony/mcp-bundle` pode tornar metade disto obsoleto** | Reavaliar antes de escrever código (seção 3.2). |
| **Autorização fina mora nos controllers** | O caminho MCP pula os controllers; toda checagem precisa ser explícita e testada. |
| **PII saindo para o provedor do modelo** | Decisão de negócio e de termos de uso, não só técnica. |

---

## 12. Testes que sustentam a feature

Escritos **antes** da implementação, e cada um provado reintroduzindo o defeito — disciplina
que na v1 pegou seis premissas erradas do plano, todas invisíveis à leitura:

1. **Cross-tenant**: usuário do escritório A pede um recurso do escritório B, por **cada**
   ferramenta, e recebe negativa — não lista vazia por acaso, negativa. Este é o teste que
   sustenta a spec.
2. **Sem tenant resolvível**, a requisição é recusada; o filtro nunca fica desligado.
3. **Token de usuário com `UserTenant` inativo** é recusado.
4. **Permissão**: usuário sem o módulo recebe negativa mesmo com escopo `mcp:read`.
5. **Escopo**: token só com `mcp:read` não executa ferramenta de escrita, mesmo que o usuário
   tenha a permissão.
6. **Revogação**: desativado o vínculo, o token para de funcionar dentro da janela decidida.
7. **Fluxo OAuth completo** ponta a ponta, com PKCE, contra o servidor de verdade.
8. **Escrita** exige confirmação e gera registro de auditoria.

---

## 13. Decomposição sugerida

Cada fatia entrega software que funciona e é revisável sozinha:

| # | Fatia | Entrega |
|---|---|---|
| 1 | **Authorization Server** | Login existente vira consentimento OAuth; emite token com `tenant_id` validado contra `UserTenant` ativo. Sem MCP ainda. |
| 2 | **Resource Server + contexto** | `/mcp` de pé, token validado, `User`+`Tenant` resolvidos, filtro Doctrine ligado **fail-closed**, uma ferramenta trivial (`quem_sou_eu`) para provar a cadeia. |
| 3 | **Ferramentas de leitura** | As cinco da seção 6.2, com permissão aplicada e teste cross-tenant em cada. |
| 4 | **Escrita segura** | `criar_pasta` e `anotar_em_pasta`, com confirmação e auditoria. |
| 5 | **Abertura** | DCR se necessário, limite de taxa, documentação para o usuário final, termos de uso. |

A fatia 2 é onde mora o risco. Ela merece revisão dedicada e, por ser risco ALTO, re-revisão
antes de seguir para a 3.

---

## 14. Decisões do dono e o que ainda falta

**Decidido em 11/08/2026:**

1. **Quem pode conectar:** **todo usuário que tem conta no sistema.** O controle de acesso do
   sistema continua valendo integralmente — é preciso conta e `UserTenant` **ativo** com o
   escritório, igual ao navegador. O que a decisão diz é que **o MCP não acrescenta um filtro
   próprio por cima disso**: não existe "só sócio usa MCP" nem "só quem tem a permissão X pode
   conectar". Quem usa o sistema usa o MCP, e enxerga o que a permissão dele já permite.

   **Consequência para o plano:** não sobra nenhuma camada de "só gente de confiança chega
   aqui" para servir de rede. Todo o controle mora nas checagens por requisição — escritório
   certo (seção 4) e permissão certa (seção 5). Se uma falhar, não há um segundo muro atrás.
   É por isso que o teste cross-tenant (seção 12, item 1) é **o** teste que sustenta a feature,
   e não um item entre outros.
2. **Vida do token: 7 dias, com conferência do vínculo a cada requisição** (seção 9, itens 1 e
   2). As duas metades são inseparáveis.

**Ainda em aberto:**

3. **Dado pessoal saindo para o provedor do modelo** — antes da fatia 5.
   Hoje, no uso pelo navegador, nome, CPF, endereço e valores de dívida **não saem da
   infraestrutura do BlueJus**. Pelo MCP, a resposta de cada pergunta é enviada ao provedor do
   modelo para ser processada. É caminho novo, sobre dado de clientes dos escritórios, sob
   LGPD. Para o escritório do próprio dono é decisão dele; **para os demais escritórios,
   precisa estar no contrato ou nos termos de uso antes de abrir**. Decisão de negócio, não
   técnica — o plano não deve inventá-la.
4. **Quais escritas entram na fatia 4** — antes da fatia 4.
   A spec propôs `criar_pasta` e `anotar_em_pasta` por serem baratas (UseCase já existe) e
   reversíveis. O dono ainda não confirmou se são as que mais poupam trabalho no dia a dia, ou
   se outra ação (lançar tarefa, registrar andamento, marcar prazo) resolveria mais. **Escolher
   pelo uso real, não pelo custo de implementar.**
