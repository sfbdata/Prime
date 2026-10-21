# Entrar com o Google (proposta, 21/10/2026)

**Risco: ALTO** (identidade `User`/`Tenant`: segundo autenticador no firewall, coluna nova em `user`,
conta casada por e-mail). Todas as decisões fechadas pelo dono em 21/10/2026 (ver "Decisões do dono" abaixo); a 9b concordou
com o encaixe no login dela (seção 7). Entrega 1 em andamento.

Parte da spec de 31/08 ([`login-com-google.md`](login-com-google.md)), que continua valendo onde não
conflita com esta. Ela ficou desatualizada em três pontos: pedia OAB no cadastro (o desenho 10 tirou),
citava o `ConfirmarCadastroUseCase` (hoje o cadastro é o `CriarContaEEscritorioUseCase`) e é anterior ao
Google Agenda (#78, #182, #183), que já tem cliente OAuth próprio. **Esta substitui aquela** quando aprovada.

## Decisões do dono (21/10/2026)

| Ponto | Decisão |
|---|---|
| Conta nova pelo Google (seção 3) | (B), em duas entregas: 1) login de quem já tem conta, sem conta age como (A); 2) criar conta e escritório |
| Master pelo Google (seção 5) | Sim, com o risco registrado |
| Botão (seção 6) | Abaixo do "Entrar", linha "ou", botão de contorno "Entrar com o Google" com o G; cadastro: "Continuar com o Google" |
| E-mail "a confirmar" (seção 2) | Entrar pelo Google confirma |
| "Manter conectado" (seção 4) | Vale também para o Google |

Migration da entrega 1: `Version20261020148100` (coluna `user.google_sub`). Sem a credencial e as variáveis, o
botão fica escondido; o dono cria a credencial depois.

## 1. Escopos e cliente OAuth

- Login pede só `openid email profile`. Sem `access_type=offline`, sem `consent`, sem `refresh_token`:
  só identidade. `prompt=select_account` (o seletor de contas aparece sempre).
- **Cliente OAuth do login separado do da Agenda.** A Agenda continua pedindo `calendar.app.created` só na
  hora de conectar a agenda, com o cliente dela (`GOOGLE_CALENDAR_OAUTH_CLIENT_ID`), e já manda
  `include_granted_scopes=false` (#183): o login não herda a agenda e a agenda não herda o login.
  Revogar a autorização de um não derruba o outro.
- Variáveis próprias: `GOOGLE_LOGIN_OAUTH_CLIENT_ID` e `GOOGLE_LOGIN_OAUTH_CLIENT_SECRET` (credencial
  "Aplicativo da Web" no mesmo projeto do Google Cloud). Faltando uma, o botão não aparece (como o painel
  do Google na Agenda); nada quebra.
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
  conta e escritório." e abre o cadastro com nome e e-mail já preenchidos.
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
6. **Proteção do callback:** o `login_throttling` só vale para o formulário; o callback se protege por `state`,
   `nonce`, PKCE e o limitador próprio (seção 5).
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
`aud`, `iss` e `exp` errados recusam; Master recusado pelo Google; e-mail "a confirmar" vira confirmado;
convidado do Trabalho Externo vai ao lugar certo; sem as variáveis, o botão some.
