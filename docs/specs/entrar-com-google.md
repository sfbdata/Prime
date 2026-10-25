# Entrar com o Google (proposta, 21/10/2026)

**Risco: ALTO** (identidade `User`/`Tenant`: segundo autenticador no firewall, coluna nova em `user`,
conta casada por e-mail). Todas as decisões fechadas pelo dono em 21/10/2026 (ver "Decisões do dono" abaixo); a 9b concordou
com o encaixe no login dela (seção 7). Entrega 1 em andamento.

Parte da spec de 31/08 ([`login-com-google.md`](login-com-google.md)), que continua valendo onde não
conflita com esta. Ela ficou desatualizada em três pontos: pedia OAB no cadastro (o desenho 10 tirou),
citava o `ConfirmarCadastroUseCase` (hoje o cadastro é o `CriarContaEEscritorioUseCase`) e é anterior ao
Google Agenda (#78, #182, #183), que já tem cliente OAuth próprio. **Esta substitui aquela** quando aprovada.

## Estado da frente (24/10/2026): fora do STANDBY, entregas 1 e 2 juntas

- 24/10: o dono tirou do STANDBY (opção 3, via coordenador) e mandou fazer junto o "Criar conta" do desenho 10 VS
  24.10.26 (Continuar com Google / Continuar com Facebook). Branch `entrar-com-google` com o `origin/master` trazido;
  migration renumerada para `Version20261020151700` (classe e arquivo, número dado pelo coordenador). O tema
  comemorativo e o placeholder do mesmo desenho vieram da frente `login-24-10-26` (spec `login-desenho-10-24-10.md`).
- **Decisão 9 aplicada** (opção 1(b), ver "Decisões do dono (24/10/2026)"): conta "a confirmar" que entra pelo
  Google é ligada, confirmada e tem a senha antiga trocada por uma inutilizável dentro do
  `GoogleAuthenticator::authenticate()`, antes do `UserChecker`. Conta já confirmada é ligada depois do sucesso, como
  antes, e mantém a senha. Conta inativa não é ligada nem confirmada (o `UserChecker` recusa a entrada).
- **Decisão 10 aplicada:** o casamento por e-mail (e o cadastro pelo Google) só com `@gmail.com` ou com `hd`
  (Workspace). `IdentidadeDoGoogle::emailComGarantia()`; o `hd` vem do `id_token` (`GoogleLoginApi`).
- **Entrega 2 feita** (seção 3, opção (B)), com a tela do desenho 10 VS 24.10.26:
  - volta do Google sem conta → a identidade espera na sessão (`App\Auth\Service\CadastroPeloGoogle`, 15 minutos) e a
    tela abre o "Criar conta" já conectado (`/login?abrir=cadastro`): cartão "nome · e-mail · conectado pelo Google"
    com "Usar outro e-mail", Nome preenchido e editável (como no desenho), sem o campo E-mail;
  - `POST /cadastro/google/criar` (`CadastroPeloGoogleController`): nome, escritório, gênero e aceite vêm do JSON; o
    e-mail e o `sub`, só da sessão. `CriarContaEEscritorioUseCase::executarPeloGoogle`: a mesma criação do cadastro
    (escritório, bootstrap, aceite dos Termos, ciência da Política, `ContaCriadaPeloCadastro`), com a conta já
    confirmada e ligada, sem link nem e-mail de confirmação. Entra na hora (`Security::login` com o
    `GoogleAuthenticator`, véu `/login/entrando`). E-mail que já tem conta: "Já existe uma conta com este e-mail.
    Entre com o Google." (quem chega aqui provou o e-mail, então a resposta neutra do cadastro não se aplica);
  - a janela é curta de propósito (revisão de 24/10): num navegador compartilhado, quem chega depois veria o cartão
    de quem saiu da aba e poderia criar a conta com aquela identidade; a sessão do próprio Google no mesmo navegador
    já permitiria o mesmo por muito mais tempo, então o risco que sobra é o do navegador, não do BlueJus;
  - `POST /cadastro/google/esquecer` ("Usar outro e-mail") tira a identidade da sessão; qualquer login com sucesso
    também tira (`EsquecerContaAConfirmarNoLoginListener`), para o navegador compartilhado;
  - o botão "Continuar com Google" do cadastro é o mesmo `/login/google` do login: com conta, entra; sem conta,
    volta ao cadastro conectado.
- Desvios do desenho que continuam (decididos antes pelo dono): o campo Senha e a régua de força do cadastro por
  e-mail não voltam (D1 de `cadastro-senha-no-link.md`: a senha é criada no link) e o Gênero fica
  (`genero-no-cadastro.md`). O "Entrar com o Google" do cartão de login (decisão do dono de 21/10) continua, embora
  o desenho 24.10.26 não o mostre: o dono decidiu **manter** (24/10, decisão 4 abaixo).

## Decisões do dono (24/10/2026, via coordenador)

1. **(b)** A conta "a confirmar" que entra pelo Google confirma o e-mail e tem a senha antiga apagada no mesmo passo,
   antes do checker.
2. **(a)** O botão do Facebook, sem credencial, mostra no modal "O acesso pelo Facebook ainda não está disponível. Use
   o Google ou o e-mail." (Quando o cliente OAuth do Google não está configurado e o botão do Google não aparece, o
   texto termina em "Use o e-mail.", para não mandar a um botão que não existe.)
3. Confirmados os quatro pontos do "Criar conta" pelo Google: o Nome vem do Google e continua editável; E-mail e Senha
   somem; a conta pelo Google fica sem senha e com o e-mail confirmado; "Entrar com o Google" sem conta abre o
   "Criar conta" já conectado.
4. **Manter** o "Entrar com o Google" no cartão de login, embora o desenho 24.10.26 não o mostre.

## Ajustes do dono (24/10/2026, depois do merge do #366)

1. **O botão "Continuar com Facebook" saiu** do "Criar conta" até o app na Meta existir (o cartão de login nunca
   teve). Saíram junto o ouvinte do clique em `public/js/auth/entrada.js` e as chaves `entrada.modal.continuar_facebook`,
   `entrada.js.facebook_indisponivel` e `entrada.js.facebook_indisponivel_sem_google`. A decisão 2(a) acima fica sem
   efeito. Para religar, ver "Facebook: ponto de ligação" logo abaixo.
2. **Os botões do Google aparecem sempre**, com ou sem `GOOGLE_LOGIN_OAUTH_CLIENT_ID`/`_SECRET` (dev igual a produção):
   o `googleLigado` saiu do `SecurityController` e do template. O aviso é **no servidor**, para valer também a quem
   abre a URL direto: sem o cliente OAuth, `GET /login/google` não chama o Google, grava na sessão
   `EntrarComGoogleController::AVISO_SESSAO` (`login` ou `cadastro`) e volta a `/login` (ou a `/login?abrir=cadastro`
   quando a ida veio do "Continuar com Google", que leva `origem=cadastro`; qualquer outro valor vale `login`). A tela
   lê uma vez e apaga (`EntrarComGoogleController::avisoNaoConfigurado`) e mostra "O acesso pelo Google ainda não está
   configurado neste ambiente. Use o e-mail." (`entrada.login.google_nao_configurado`): no cartão, no `#lgErro`, sem
   marcar os campos como inválidos; no cadastro, no erro do modal (`#lgMdErro`), via `data-aviso-cadastro` do
   `#lgRaiz`. Com a credencial, o fluxo é o de antes (o `origem` é ignorado).

## Facebook: ponto de ligação (24/10/2026)

O botão "Continuar com Facebook" do desenho **saiu** da tela (ajuste 1 acima): não há app na Meta nem credencial.
A coluna `user.facebook_id` **não** entra agora. Não há rota, controller nem variável de ambiente do Facebook no
código. Para a frente do Facebook (guia de 24/10 do coordenador):

1. Variáveis `FACEBOOK_APP_ID` e `FACEBOOK_APP_SECRET` (e `FACEBOOK_GRAPH_VERSION`), lidas como as do Google, com
   default vazio: um `App\Auth\Infra\FacebookLoginApi::ligado()` decide o botão.
2. Rotas `GET /login/facebook` (`app_login_facebook`, ida: `state` na sessão, `scope=public_profile,email`) e
   `GET /login/facebook/callback` (`app_login_facebook_volta`, um `FacebookAuthenticator` que estende
   `AbstractAuthenticator`, como o do Google), à mão com `HttpClientInterface`: troca do `code` e `GET /me` com
   `appsecret_proof`, sem guardar token. As duas rotas nas listas brancas (`TermoAceiteListener::ROTAS_IGNORADAS`,
   `TenantContextValidatorListener`) e no `EntradaDoConvidadoListener`; `/login/*` já é `PUBLIC_ACCESS`.
3. Na tela: em `templates/security/login.html.twig`, bloco `<div class="lg-soc" id="lgCdSoc">` do passo `cad`, pôr
   um `<a class="lg-soc__btn" id="lgCdFacebook">` para `/login/facebook` logo depois do `#lgCdGoogle` (o desenho
   24.10.26 tem o SVG e o texto "Continuar com Facebook"; a chave `entrada.modal.continuar_facebook` volta ao catálogo
   pt_BR). Sem credencial, seguir o padrão do Google: o botão aparece e a ida volta com o aviso de "não configurado"
   (sessão + `data-aviso-cadastro`). O cartão conectado já recebe o provedor no texto (`entrada.modal.conectado_pelo`,
   `{provedor}`); a identidade pendente pode seguir o mesmo formato do `CadastroPeloGoogle`. Os testes do cadastro
   (`CadastroPeloGoogleTest`) hoje travam a ausência do Facebook: ajustar junto.
4. **O Facebook não garante e-mail verificado: nunca ligar conta só porque o e-mail bate.** Sem conta pelo
   `facebook_id`, o e-mail do Facebook não casa com conta existente; o cadastro pelo Facebook exige confirmar o
   e-mail pelo link, como o cadastro por e-mail.

## Material do dono (24/10/2026)

O segredo nunca passa pela conversa. No Google Cloud, projeto do BlueJus: credencial OAuth "Aplicativo da Web"
própria do login (separada da do Google Agenda), com o URI de redirecionamento autorizado
`https://bluejus.com.br/login/google/callback`. No `.env.prod` da VPS: `GOOGLE_LOGIN_OAUTH_CLIENT_ID` e
`GOOGLE_LOGIN_OAUTH_CLIENT_SECRET`. Sem as duas, os botões do Google aparecem e o clique mostra o aviso de "não
configurado" (ajuste 2 de 24/10); nada quebra.
Migration manual no deploy: `Version20261020151700` (coluna `user.google_sub`).

### Pendências do dono depois do merge do #366 (24/10/2026)

O #366 entrou no master em 24/10; a migration `Version20261020151700` já foi aplicada no 8080 (saas_ux). Falta:

1. **Credencial do Google** (o segredo nunca passa pela conversa): no Google Cloud, projeto do BlueJus, criar a
   credencial OAuth "Aplicativo da Web" só do login (separada da do Google Agenda), cadastrar o URI de
   redirecionamento `https://bluejus.com.br/login/google/callback` e gravar `GOOGLE_LOGIN_OAUTH_CLIENT_ID` e
   `GOOGLE_LOGIN_OAUTH_CLIENT_SECRET` no `.env.prod` da VPS. Para o smoke no 8080, a mesma coisa no `.env.local`
   com o URI `http://localhost:8080/login/google/callback` cadastrado na credencial.
2. **Migration em produção** no deploy da versão que levar o #366: `Version20261020151700`.
3. **Smoke na tela** (8080, depois em produção):
   - tema comemorativo ligado: Login e véu "Abrindo o seu escritório digital" com as cores na tarja, a fita, os
     ícones e o brilho do logo; conferir se o tema pisca ao abrir;
   - modal "Criar conta": "Continuar com Google" (sem o Facebook, ajuste 1 de 24/10), o divisor "ou com outro e-mail" e o
     placeholder "Ex.: BlueJus Advogados Associados";
   - "Continuar com Google" com um Gmail sem conta: o modal abre conectado (cartão com as iniciais, nome, e-mail
     "conectado pelo Google"), sem o campo E-mail e com o Nome preenchido; "Criar minha conta" entra direto;
   - "Usar outro e-mail" volta aos botões e ao campo E-mail;
   - "Entrar com o Google" no cartão de login com uma conta que já existe: entra pelo véu;
   - sem a credencial: "Entrar com o Google" e "Continuar com Google" aparecem, e o clique mostra "O acesso pelo
     Google ainda não está configurado neste ambiente. Use o e-mail." (no cartão e no modal, respectivamente).

## Estado da frente (21/10/2026)

- **Entrega 1** (login de quem já tem conta): implementada na branch `entrar-com-google`, migration
  `Version20261020151700`. Peças: `App\Shared\Google\IdTokenDoGoogle` (conferente compartilhado com a Agenda),
  `App\Auth\Infra\GoogleLoginApi`, `App\Auth\Service\ContaDoGoogle`, `App\Auth\UseCase\LigarContaAoGoogleUseCase`,
  `App\Security\GoogleAuthenticator`, `App\Security\EntradaDoUsuario` (destino extraído do `UserAuthenticator`),
  `App\Auth\Controller\EntrarComGoogleController` (ida, com limitador `login_google` em
  `config/packages/login_google.yaml`). Rotas `app_login_google` (`/login/google`) e `app_login_google_volta`
  (`/login/google/callback`).
- **Entrega 2** (cadastro pelo Google): depois do merge da 1.

## STANDBY (21/10/2026, ordem do dono)

Parado até o "Conectar Google Agenda" estar em produção funcionando. Sem PR; a branch `entrar-com-google` fica no
GitHub como está (entrega 1 implementada, revisada, com os acertos da revisão; falta aplicar as decisões 9 e 10 e a
re-revisão). Ao voltar: trazer o master, aplicar 9 e 10, re-revisar, PR.

- **Decisão 9 (achado da revisão: conta "a confirmar" com dois donos).** O dono corrige pela raiz: conta criada pelo
  cadastro com e-mail só usa o sistema depois de confirmar pelo link do e-mail, como era antes (a 9b faz isso no
  cadastro). Com isso, a conta "a confirmar" não é usada por ninguém antes da prova do e-mail.
  **Combinado com a 9b (21/10):** o `UserChecker::checkPostAuth` dela barra toda conta "a confirmar", sem exceção para
  o Google. **Cuidado:** o Google só confirmar o e-mail reabre o furo (a senha de quem cadastrou o e-mail alheio
  passaria a valer). Na volta, o `GoogleAuthenticator::authenticate` trata a conta não confirmada antes do checker, de
  um destes jeitos, **a decidir com o dono**: (a) recusar com "Confirme seu e-mail pelo link que enviamos"; ou
  (b) confirmar e apagar a senha antiga no mesmo passo (como o confirmar pelo código da senha). Hoje o
  `LigarContaAoGoogleUseCase` confirma no `onAuthenticationSuccess` (depois do checker): mudar.
- **Decisão 10 (primeiro casamento por e-mail).** Segue o padrão do Google
  (developers.google.com/identity/account-linking/oauth-with-sign-in-linking): só e-mail `@gmail.com`, ou
  `email_verified` com `hd` (conta Workspace); os outros recebem "Entre com e-mail e senha." Depois de ligada, a
  conta é achada sempre pelo `sub`.

## Decisões do dono (21/10/2026)

| Ponto | Decisão |
|---|---|
| Conta nova pelo Google (seção 3) | (B), em duas entregas: 1) login de quem já tem conta, sem conta age como (A); 2) criar conta e escritório |
| Master pelo Google (seção 5) | Sim, com o risco registrado |
| Botão (seção 6) | Abaixo do "Entrar", linha "ou", botão de contorno "Entrar com o Google" com o G; cadastro: "Continuar com o Google" |
| E-mail "a confirmar" (seção 2) | Entrar pelo Google confirma |
| "Manter conectado" (seção 4) | Vale também para o Google |

Migration da entrega 1: `Version20261020151700` (coluna `user.google_sub`). Sem a credencial e as variáveis, o
botão aparece e o clique mostra o aviso de "não configurado" (ajuste 2 de 24/10); o dono cria a credencial depois.

## 1. Escopos e cliente OAuth

- Login pede só `openid email profile`. Sem `access_type=offline`, sem `consent`, sem `refresh_token`:
  só identidade. `prompt=select_account` (o seletor de contas aparece sempre).
- **Cliente OAuth do login separado do da Agenda.** A Agenda continua pedindo `calendar.app.created` só na
  hora de conectar a agenda, com o cliente dela (`GOOGLE_CALENDAR_OAUTH_CLIENT_ID`), e já manda
  `include_granted_scopes=false` (#183): o login não herda a agenda e a agenda não herda o login.
  Revogar a autorização de um não derruba o outro.
- Variáveis próprias: `GOOGLE_LOGIN_OAUTH_CLIENT_ID` e `GOOGLE_LOGIN_OAUTH_CLIENT_SECRET` (credencial
  "Aplicativo da Web" no mesmo projeto do Google Cloud). Faltando uma, o botão aparece do mesmo jeito e a
  ida volta com o aviso de "não configurado" (ajuste 2 de 24/10); nada quebra.
- `openid email profile` não é escopo sensível: sem tela de "app não verificado" e sem limite de 100
  usuários (apurado na spec de 31/08).

## 2. Ligar à conta existente

Ordem de casamento, na volta do Google:

1. por `user.google_sub` (id estável do Google, coluna nova, única);
2. sem ninguém com esse `sub`: por `lower(email)`, **só se `email_verified` for verdadeiro**; o `sub` é
   gravado nesse primeiro login e prende a conta dali em diante;
3. `sub` já ligado a outra pessoa: recusa.

- E-mail ainda "a confirmar" no BlueJus (cadastro do #101 sem clicar no link): o Google provou o e-mail,
  então o login pelo Google **marca o e-mail como confirmado** (dispara `EmailDaContaConfirmado`, que a 5d
  já ouve). Decidido pelo dono.
- Conta só-Google pode criar senha pelo "Esqueceu a senha?" (já funciona; vira teste).
- Usuário inativo, conta suspensa ou em quarentena de exclusão: mesmas barreiras do login por senha
  (`UserChecker` do firewall e `TenantContextValidatorListener`).

## 3. Conta nova pelo Google

**Decidido pelo dono (21/10/2026): (B), em duas entregas.** Entrega 1: login de quem já tem conta; quem não
tem conta age como (A). Entrega 2: criação de conta e escritório pelo Google.

E-mail do Google que não tem conta no BlueJus. Duas opções:

- **(A) Só entra quem já tem conta.** Volta ao login com "Não encontramos conta com este e-mail. Crie sua
  conta e escritório." e o e-mail do Google no campo. (Abrir o cadastro já preenchido com nome e e-mail fica para a
  entrega 2, que mexe no modal de cadastro.)
- **(B) Cria a conta e o escritório como o cadastro do #101**, com nome e e-mail vindos do Google
  (travados), **sem senha**, pedindo o nome do escritório e o aceite dos Termos e da Política (gravado com IP
  e User-Agent, como hoje). E-mail já nasce confirmado. Mesmo `CriarContaEEscritorioUseCase`, sem
  duplicar a criação.

**Recomendação: (B), em duas entregas:** primeiro o login de quem já tem conta (seções 2 e 4, que valem nas
duas opções); depois o cadastro pelo Google. O cadastro continua pedindo o que o desenho 10 pede (escritório
e aceite); o Google só tira a senha e a digitação do e-mail, que é onde nascem os erros ("gmail.comm" na
spec de 31/08). Na primeira entrega, o caso "sem conta" já se comporta como (A).

## 4. Depois do login

O mesmo caminho do login por senha, sem cópia:

- a lógica de destino do `UserAuthenticator` (`destinoDoUsuario`: 1 escritório entra direto, Master sem
  escritório vai ao painel, vários vão à seleção) é **extraída para um serviço** e usada pelos dois
  autenticadores;
- tudo passa pelo véu `/login/entrando?para=` (destino revalidado pelo `DestinoPosLogin`), como na 9b;
- aceite de Termos pendente: o `TermoAceiteListener` leva ao aceite, como hoje;
- convidado do Trabalho Externo sem escritório: o `EntradaDoConvidadoListener` passa a reconhecer também o
  autenticador do Google (hoje só olha o `UserAuthenticator`);
- "Manter conectado": o botão do Google respeita a caixa marcada no formulário (vai como parâmetro na ida e
  volta pela sessão). Decidido pelo dono.
- As rotas novas entram nas duas listas brancas (`TermoAceiteListener::ROTAS_IGNORADAS` e
  `TenantContextValidatorListener`), senão há laço de redirecionamento.

## 5. Segurança

- `state` aleatório na sessão, conferido com `hash_equals`; **PKCE S256**; **`nonce`** na ida, conferido no
  `id_token` da volta.
- `id_token` vindo direto do endpoint de token (TLS, OIDC Core 3.1.3.7): confere `aud` (o cliente do login),
  `iss` (`accounts.google.com`), `exp`, `nonce` e `email_verified`. Mesmo conferente do #183, sem cópia.
- Autenticador `AbstractAuthenticator` puro (com dois `AbstractLoginFormAuthenticator` o container não sobe:
  armadilha 1 da spec de 31/08).
- Limitador próprio em `/login/google` por IP (bloco em arquivo próprio, como o `agenda_google`).
- **Master pelo Google: SIM (decisão do dono, 21/10/2026).** O Master entra pelo Google como qualquer conta,
  com as mesmas conferências. **Risco registrado:** o BlueJus não tem 2FA, então a segurança do Master passa a
  depender também da conta Google dele (uma conta Google comprometida vira acesso à plataforma inteira). A
  recomendação anterior era recusar o Master pelo Google; o dono decidiu aceitar. Sugestão para o Master: ligar
  a verificação em duas etapas na própria conta Google.
- Nada do Google é guardado além do `sub` (sem token, sem foto).

## 6. Onde aparece o botão (o desenho 10 não tem)

**Decidido pelo dono (21/10/2026): a proposta abaixo, como está.**

O desenho 10 não mostra o Google; por isso a posição precisa de aprovação antes de entrar (regra do desenho).
Proposta, a mais discreta:

- **No cartão de login**, logo abaixo do botão "Entrar" e acima do selo de segurança: uma linha fina com "ou"
  e um botão secundário de contorno, da largura do "Entrar", com o "G" do Google e o texto **"Entrar com o
  Google"** (seguindo as regras de marca do Google para o botão). O "Entrar" continua sendo o principal.
- **No modal de cadastro** (só na entrega do cadastro, se (B)): o mesmo botão, "Continuar com o Google", acima
  dos campos, com "ou preencha abaixo".
- Nada muda no rodapé nem nas ferramentas "SÓ MASTER".

## 7. Combinado com a 9b (dona do login do desenho 10)

Toca: `security.yaml` (lista de autenticadores), `UserAuthenticator` (extração do destino),
`templates/security/login.html.twig` e o CSS do login, `CadastroController` (entrega 2). O contrato do
formulário de senha (`email`, `password`, `_csrf_token`, `_remember_me`, um `a[href="/senha/esqueci"]`) não
muda: o botão do Google é um link para `/login/google`, fora dos campos do formulário.

**Contratos combinados com a 9b (21/10/2026; ela não tem PR aberto nesses arquivos):**

1. **Forma do login** (`tests/Auth/Functional/LoginTelaTest.php`, por filho direto): seguem exatamente 1
   `a[href="/senha/esqueci"]`, 1 `a[href="/cadastro"]`, 1 `button#lgEntrar > i.bi-box-arrow-in-right` e 1
   `button.lg-selo` filho direto de `form#lgForm > .lg-cartao__corpo`. O Google entra como `<a>` (nunca um
   segundo `button` com o ícone de entrar) entre o `#lgEntrar` e o selo, com teste próprio de posição por filho
   direto.
2. **Destino:** o serviço extraído leva o que o #184 acrescentou (vários vínculos vão à seleção; o
   `EntradaController` manda à seleção se faltar escritório na sessão), senão o 403 do Master com vários
   escritórios volta pelo Google. O autenticador do Google lê e limpa o `target_path` como o `lerAlvo` do
   `UserAuthenticator` e passa sempre por `/login/entrando?para=` com o `DestinoPosLogin`.
3. **Rotas:** `/login/google` e o callback públicos no `access_control`, nas `ROTAS_IGNORADAS` do
   `TenantContextValidatorListener` (como `app_login`) e fora do portão dos Termos; o callback **não** entra
   nas páginas aceitas como destino (`PAGINAS` do `DestinoPosLogin`).
4. **E-mail a confirmar:** se confirmar pelo Google (seção 2), pelo mesmo caminho do
   `ConfirmarCodigoSenhaUseCase`: o UPDATE condicional `ConfirmacaoEmailRepository::confirmarEmailDaConta` (não
   confirma duas vezes) e o `EmailDaContaConfirmado` despachado uma vez, depois do commit (a 5d escuta).
5. **Entrega 2 (cadastro pelo Google):** dispara o mesmo `ContaCriadaPeloCadastro` (o link de indicação
   `/i/{codigo}` do #176 atribui a conta por ele), grava o aceite dos Termos e a ciência da Política
   (`CienciaPolitica`) como a caixa do #101, e pede o nome do escritório (o Google não dá).
6. **Proteção do callback:** se protege por `state` (de uso único), `nonce`, PKCE e o limitador próprio da ida
   (seção 5). O `login_throttling` do firewall conta também as falhas do Google (cancelar, state errado) no mesmo
   limite por IP da senha; aceito, porque o limite é de 5 em 15 minutos e só pesa em quem erra.
7. **"Manter conectado":** se o Google não respeitar a caixa, o comportamento muda em relação à senha; vai ao
   dono junto com a recomendação da seção 4.

## 8. Material do dono

1. Decisões: todas fechadas em 21/10 (tabela no topo).
2. Criar no Google Cloud a credencial "Aplicativo da Web" do login, com o redirecionamento
   `https://<domínio>/login/google/callback`, e pôr as duas variáveis no `.env.prod`. Passo a passo vai junto
   com o PR.

## 9. Testes (quando implementar)

Cada invariante da spec de 31/08 continua (e-mail não verificado recusa; `state` errado recusa; inativo não
entra; e-mail desconhecido não vira conta na entrega 1; conta só-Google não entra por senha; `sub` de outro
recusa; 2+ escritórios vão à seleção; rotas nas duas listas brancas; caixa do e-mail). Mais: `nonce`,
`aud`, `iss` e `exp` errados recusam; Master entra pelo Google (decisão do dono); e-mail "a confirmar" vira confirmado;
convidado do Trabalho Externo vai ao lugar certo; sem as variáveis, o botão fica e a ida volta com o aviso (ajuste 2 de 24/10).
