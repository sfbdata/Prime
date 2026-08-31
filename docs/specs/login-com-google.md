# Spec — Entrar com Google (autenticação + cadastro assistido)

**Risco: ALTO.** Mexe em identidade `User`/`Tenant`: acrescenta um segundo autenticador ao
firewall, cria coluna nova em `user`, casa contas por e-mail e abre um caminho de criação de conta
que **não passa pela confirmação por e-mail**. Pela régua do `CLAUDE.md`, exige spec antes do
código e re-revisão ao final.

Escrita em 31/08/2026, **antes** da implementação — nada de código foi escrito até aqui.

## Problema

Hoje só existe login por e-mail + senha ([`UserAuthenticator`](../../app/src/Security/UserAuthenticator.php)).
Três fatos medidos em produção em 31/08/2026 justificam a feature:

- **13 dos 14 usuários são `@gmail.com`.** A cobertura é praticamente total.
- O 14º usuário está cadastrado como **`gmail.comm`** — erro de digitação. É o único com
  `password IS NULL` e `invitation_token` preenchido: o convite, de 16/04/2026, nunca chegou
  porque o endereço não existe. O Google devolve o e-mail canônico e elimina essa classe de erro.
- O e-mail transacional de produção **já ficou mudo por ~2 meses** (senha de app do Gmail
  revogada), e nada avisa quando cai. Todo fluxo que hoje depende de "clique no link que
  enviamos" é frágil por construção. O Google prova o e-mail sem passar pela nossa caixa de saída.

Não é ideia nova: **"Login futuro: Google"** já consta como decisão de produto em
[`docs/refatoracao-identidade-global.md:44`](../refatoracao-identidade-global.md#L44), na mesma
lista que registra "sem 2FA por enquanto".

## Decisões do dono (31/08/2026)

| # | Decisão | Consequência |
|---|---|---|
| 1 | **Escopo: autenticar + preencher o cadastro.** Google entra na tela de login *e* na `/cadastro`. | Duas fatias. Google **nunca** cria conta sozinho — ver decisão 2. |
| 2 | Google **não** dispensa OAB, nome do escritório nem aceite dos Termos. | O botão no cadastro pré-preenche nome e e-mail; o resto o usuário digita. O aceite continua gravado com IP e User-Agent. |
| 3 | **Reusar o app `bluejus-sync`** do Google Cloud. | Só adicionar a URI de redirecionamento nova. Escopos de login (`openid email profile`) não são sensíveis e não alteram a verificação que o app já tem pelo escopo Drive. |

## Contrato

### Fatia 1 — Entrar com Google (conta que já existe)

- `GET /login/google` → guarda `state` na sessão e redireciona ao consentimento.
- `GET /login/google/callback` → autentica ou recusa. **Nunca cria conta.**
- Escopos: `openid email profile`. **Sem** `access_type=offline` e **sem** `consent` — não
  queremos `refresh_token` aqui, só identidade. `prompt=select_account` para o seletor de contas
  aparecer sempre (mesma lição já registrada no OAuth do Drive).
- Casamento, nesta ordem:
  1. por `user.google_sub` (identificador estável do Google);
  2. se ninguém tem esse `sub`, por `lower(email)` — e o `sub` é **gravado** nesse primeiro
     login, vinculando a conta dali em diante;
  3. se não achar ninguém: volta ao `/login` com "Não encontramos conta com esse e-mail."
- Destino pós-login idêntico ao da senha: 1 escritório auto-seleciona, 0 + super-admin vai ao
  painel da plataforma, resto cai na tela de seleção.

### Fatia 2 — Cadastro com Google

- Botão "Continuar com Google" em `/cadastro`.
- Na volta, o formulário exibe **nome e e-mail vindos do Google, travados**, **sem campo de
  senha**, e pede escritório + OAB (número/UF) + aceite dos Termos.
- Ao enviar, a conta nasce **na hora**: sem `CadastroPendente`, sem token, sem e-mail de
  confirmação — o Google já provou o e-mail. `password` fica `NULL`, `google_sub` preenchido.
- O aceite dos Termos continua registrado com IP e User-Agent, como no caminho por senha.

### Configuração

**Corrigido em 31/08/2026, depois de apurar o tipo do cliente OAuth.** A primeira versão desta
spec propunha herdar as credenciais do sync por fallback. **Está errado** e foi removido: o
cliente atual do `bluejus-sync` é do tipo **Desktop app**
([registrado em 07/2026](fase2-tempo-real-sistema-drive.md#L311)), e cliente Desktop **não
aceita** `redirect_uri` de site — só `localhost`. Herdar aquele `client_id` faria todo login
morrer com `redirect_uri_mismatch`.

Portanto, variáveis **próprias e obrigatórias**, sem fallback:

```yaml
google_oauth_client_id:     '%env(string:default::GOOGLE_OAUTH_CLIENT_ID)%'
google_oauth_client_secret: '%env(string:default::GOOGLE_OAUTH_CLIENT_SECRET)%'
```

Faltando qualquer uma, o serviço lança com mensagem explícita — mesmo padrão do
[`GoogleDriveOAuth::clientBase()`](../../app/src/Sync/Service/GoogleDriveOAuth.php). Falha
barulhenta na configuração é melhor que login quebrado na cara do usuário.

Elas recebem uma credencial **"Aplicativo da Web"** criada no **mesmo projeto** `bluejus-sync`
(a decisão 3 do dono se mantém: mesmo projeto, mesma tela de consentimento, mesma conta
administradora — só uma credencial a mais dentro dele). Redirect a registrar:
`https://bluejus.com.br/login/google/callback`.

**Essa mesma credencial Web destrava a pendência do "Conectar meu Drive"**, aberta desde 07/2026
pelo mesmo motivo — lá o redirect é `https://bluejus.com.br/sync/drive/conexao/callback`. Vale
registrar os dois de uma vez. A credencial Desktop atual continua servindo o CLI/rclone.

## Invariantes (o que os testes travam)

Cada item vira teste, e cada teste é **provado por reintrodução do defeito** antes de valer.

1. **`email_verified` falso → recusa.** Sem isso, uma conta Google Workspace de domínio próprio
   apresenta e-mail alheio e sequestra a conta. É o furo mais grave da feature.
2. **`state` divergente ou ausente → recusa.** Molde do
   [`DriveConexaoController:73-78`](../../app/src/Sync/Controller/DriveConexaoController.php#L73-L78).
3. **Usuário inativo não entra pelo Google.** O [`UserChecker`](../../app/src/Security/UserChecker.php)
   é registrado por *firewall*, então já cobre — o teste prova que continua coberto.
4. **E-mail desconhecido não vira conta.** Volta ao login com mensagem; nada é gravado.
5. **Conta só-Google não loga por senha.** `password IS NULL` já é barrado pelo Symfony
   ([`CheckCredentialsListener:62`](../../app/vendor/symfony/security-http/EventListener/CheckCredentialsListener.php#L62));
   o teste trava o comportamento contra regressão futura.
6. **`google_sub` já vinculado a outro usuário → recusa.** Um `sub` pertence a uma conta só.
7. **Usuário com 2+ escritórios cai na tela de seleção** — não pula direto para um deles.
8. **As rotas novas estão nas duas listas brancas de portão** — usuário sem Termos aceitos ou sem
   escritório selecionado não entra em loop de redirect.
9. **Cadastro via Google grava o aceite dos Termos com IP e User-Agent.**
10. **Cadastro via Google não cria `CadastroPendente` nem dispara e-mail.**
11. **E-mail do Google com caixa diferente casa com a conta existente** (`lower(email)`).

## Armadilhas conhecidas (verificadas no código, não de memória)

1. **`entry_point` quebra o boot.** Se o autenticador do Google estender
   `AbstractLoginFormAuthenticator` (que implementa `AuthenticationEntryPointInterface`), o
   container morre na compilação: *"Because you have multiple authenticators…"*
   ([`RegisterEntryPointPass:73`](../../app/vendor/symfony/security-bundle/DependencyInjection/Compiler/RegisterEntryPointPass.php#L73)).
   **Estender `AbstractAuthenticator` puro.** Aí a única mudança no `security.yaml` é
   `custom_authenticator` → `custom_authenticators` em lista.
2. **Duas listas brancas, não uma.** `ROTAS_IGNORADAS` do
   [`TermoAceiteListener`](../../app/src/EventListener/TermoAceiteListener.php#L34) **e** do
   [`TenantContextValidatorListener`](../../app/src/EventListener/TenantContextValidatorListener.php#L20).
   Liberar só uma já custou uma rodada na Política de Privacidade.
3. **O `make:migration` vai propor `DROP INDEX uq_user_email_lower`** — o Doctrine não representa
   índice funcional. Aceitar esse `DROP` não quebra nada visível: deixa entrar conta duplicada que
   só difere na caixa. Fotografar `doctrine:schema:update --dump-sql` **antes** de gerar.
4. **Não duplicar a resolução de escritório.** A lógica de
   [`UserAuthenticator:52-77`](../../app/src/Security/UserAuthenticator.php#L52-L77) precisa ser
   **extraída** para um serviço compartilhado e chamada pelos dois autenticadores. Duas cópias
   divergem, e a que diverge é sempre a que ninguém olha.
5. **Não duplicar a criação de conta.** O miolo transacional de
   [`ConfirmarCadastroUseCase`](../../app/src/Auth/UseCase/ConfirmarCadastroUseCase.php)
   (User + Tenant + `TenantBootstrapService` + aceite dos Termos, tudo em uma transação) deve ser
   extraído para um UseCase único, chamado pelo caminho por senha e pelo caminho Google.
6. **Fora do código, e só o dono consegue fazer:** criar a credencial **"Aplicativo da Web"** no
   projeto `bluejus-sync` e registrar os redirects (ver *Configuração*). **Não** é conta nova —
   é uma credencial a mais no projeto que já existe.

## Duas ressalvas que caíram ao serem apuradas (31/08/2026)

Ambas estavam nesta spec como risco e **não são risco**:

1. **"E se o app estiver em modo Testing?"** Não está. O `bluejus-sync` foi **publicado em
   Produção em 13/07/2026** — justamente para o `refresh_token` do sync parar de expirar em 7
   dias ([sincronizacao-drive-bidirecional.md:620](sincronizacao-drive-bidirecional.md#L620)).
   Não há lista de usuários de teste para manter.
2. **"O usuário vai ver a tela de app não verificado?"** Não, e nem esbarra no limite de 100
   usuários. O Google trata `openid` + `userinfo.email` + `userinfo.profile` como **exceção
   explícita**: pedindo só esses, o usuário não precisa estar em lista de confiança, não vê
   aviso, e a autorização não expira em 7 dias. A tela de aviso e o cap de 100 valem para
   requisições com escopo **sensível ou restrito** — o caso do `drive`, que o login não pede.
   Fonte: [Unverified apps](https://support.google.com/cloud/answer/7454865) (Google Cloud
   Console Help).

   **Consequência prática:** reusar o projeto do sync não contamina o login com o ônus do escopo
   Drive. O que separa os dois é a requisição, não o projeto.

## Comportamento que já funciona e deve ser preservado

Uma conta só-Google consegue **criar** senha pelo "Esqueci minha senha": o
[`SolicitarRedefinicaoSenhaUseCase`](../../app/src/Auth/UseCase/SolicitarRedefinicaoSenhaUseCase.php)
não filtra por senha existente. Isso é a saída de emergência se o Google falhar — é desejado, não
é bug, e vale um teste para não se perder.

## Fora do escopo

- 2FA (decisão de produto já registrada como "por enquanto não").
- Vincular/desvincular a conta Google pela tela de perfil.
- Outros provedores (Microsoft, Apple).
- Criar conta sem escritório, ou escritório sem OAB.
