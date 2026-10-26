# Login: cupom de indicação no cadastro e Continuar com Facebook (desenho 10 VS 25.10.26)

Risco ALTO (identidade User/Tenant). Desenho: `docs/design/redesign-25-10-26/10 - Login - VS 25.10.26.dc.html`.
Base: a tela já segue o desenho 10 VS 24.10.26 (#366). Esta spec cobre só a diferença 24.10 → 25.10 (PR A) e o
Facebook (PR B, seção própria, escrita antes do código dele).

## Decisões (coordenador, 26/10)

- D1. Cupom: aceitar com e sem o prefixo `BJ-`; os códigos existentes (8 caracteres de `CodigoDeIndicacao::ALFABETO`)
  não mudam.
- D2. BJMonitor do desenho (`bj-monitor.js`, `BJMonitor.reg('login', ...)`): fora. Não existe `/master/saude`.
- D3. "· você ganha 5 GB extras no 1º mês": promete um bônus que o sistema não tem. Fica sem o texto até o dono decidir.
- D4. E-mail "Indicação aceita" ao indicador (desenho: `bj-com-fila`, gatilho "Indicação aceita", assunto "<Nome> abriu
  a conta com o seu cupom BJ-X"): **aguarda o dono**. Até lá segue a spec `indicar-bluejus.md` (comportamento 7: sem
  aviso a quem indicou).
- D5. Conferência ao vivo do cupom ("Cupom válido: indicado por <nome>" / "Cupom não encontrado..."): **aguarda o dono**
  (revela se o código existe e o nome de quem indicou; o `/i/{codigo}` foi feito para não revelar). Até lá o cupom é
  validado só ao enviar, em silêncio, como o link curto.

## PR A: tela e cupom (sem migration)

Comportamento:

1. Título do modal centralizado em todos os passos; no "Criar conta e escritório" sem o ícone (`mdTemIc: md !== 'cad'`).
2. Entre "Nome do escritório" e "E-mail", o botão-chave "Tenho um cupom de indicação" (chave 36x20, desligada `#c9d3da`
   com bolinha `#fff` à esquerda; ligada `#f26b55` com bolinha `#12242f` à direita, transições de .2s). Ligada, mostra o
   campo `BJ-XXXXXX` (monoespaçado, negrito 14.5px, `letter-spacing:1px`, caixa alta, foco no campo). Desligar apaga o
   campo. O campo aceita só `A-Z`, `0-9` e `-`, em caixa alta, até 11 caracteres (`BJ-` + 8; o desenho corta em 10
   porque o protótipo usa 6).
3. A tela abre no "Criar conta" com o cupom preenchido (`BJ-` + código, chave ligada) quando a URL traz `?ind=`,
   `?codigo=` ou `?ref=` com um código no formato (`CodigoDeIndicacao::doCupom`: `[A-Z0-9]{4,12}`, sem diferenciar
   maiúsculas, com ou sem o prefixo, D1). O prefixo é `BJ-`; `BJ` sem hífen só é prefixo quando sobra um código inteiro
   de 8, porque o alfabeto tem B e J e `BJXY2345` é um código real. `#cadastro` na URL também abre o "Criar conta" e,
   aberto pela URL, o foco vai para o cupom (`autoFocus` do desenho). Valor fora do formato é ignorado.
4. O link curto `/i/{codigo}` continua guardando o código na sessão (conferido, nulo se não existe) e passa a levar a
   `/cadastro?ind=<CODIGO>`, que leva ao login com `abrir=cadastro&ind=<CODIGO>`. O `ind` é o que veio na URL, em caixa
   alta, se tiver o formato; não depende de o código existir, então a resposta continua sem revelar nada (mesmo destino,
   mesmos cabeçalhos para código válido e inválido).
5. Ao enviar ("Criar minha conta grátis", pelo e-mail ou conectado pelo Google), o JSON leva `indicacao` quando a chave
   está ligada, e leva `indicacao` vazio quando a pessoa desligou um cupom que veio na URL. Com a chave desligada e sem
   cupom na URL, `indicacao` não vai: quem chegou pelo link curto e cria a conta em outra aba não perde a indicação da
   sessão. Quando `indicacao` vem no corpo, o campo é a palavra final e vale no lugar do código da sessão; vazio,
   malformado ou inexistente não liga nada. Quem lê o corpo é o `CadastroPeloLinkListener`, no evento
   `ContaCriadaPeloCadastro` da própria requisição: os controllers do Auth não mudam e o Auth continua sem conhecer a
   Indicação na criação da conta (comportamento 7 da `indicar-bluejus.md`). Cupom inválido nunca derruba o cadastro e
   não mostra erro.
6. "Continuar com Google" no cadastro sai da página: o cupom ligado é guardado no `sessionStorage` só no clique do botão
   e volta ao campo na volta do Google (conectado); depois sai do `sessionStorage`. Cupom da URL desligado antes do
   clique também é guardado (como marca de desligado) e, na volta, o envio leva `indicacao` vazio, como no item 5.
7. Acoplamento aceito: a tela de entrada (`SecurityController`) e o `GET /cadastro` usam `CodigoDeIndicacao::doCupom`
   só para o formato do cupom da URL; nenhuma regra de atribuição sai da Indicação.

Fora do PR A: D2, D3, D4, D5 e o Facebook (PR B).

Testes:

- Funcional do login: `?ind=BJ-QWER2345`, `?codigo=qwer2345` e `?ref=` chegam ao `data-ind` em `BJ-QWER2345`; valor fora
  do formato não chega; a chave e o campo são filhos diretos do passo `cad`, entre o escritório e o e-mail.
- Funcional do cadastro: `indicacao` válido liga a conta nova a quem indicou; com e sem `BJ-`; inválido não liga e não
  derruba; `indicacao` vazio desfaz o código do link curto; sem `indicacao` no corpo, o link curto vale como hoje; o
  mesmo pelo `/cadastro/google/criar`.
- Funcional do link curto: destino `/cadastro?ind=<CODIGO>` igual para código válido e inválido.

## PR B: Continuar com Facebook

Escrita antes do código do PR B. Migration de número 150800 (dado pelo coordenador; a 150700 é de outra frente).

Base: `docs/specs/entrar-com-google.md`, seção "Facebook: ponto de ligação" (itens 1 a 4), e o fluxo do Google
(`GoogleLoginApi`, `GoogleAuthenticator`, `CadastroPeloGoogle`, `CadastroPeloGoogleController`). Credenciais da Meta são
do dono: sem `FACEBOOK_APP_ID` e `FACEBOOK_APP_SECRET` o botão **some** (decisão do coordenador de 26/10; difere do
Google, que mostra o aviso de "não configurado").

### Princípio (o Facebook não prova e-mail)

O `id` do Facebook prova quem é a pessoa **para o app**, nunca o e-mail dela. Logo:

1. **Nunca ligar nem logar conta existente pelo e-mail do Facebook.** Só o `facebook_id` (já ligado) entra.
2. **O e-mail do Facebook é só sugestão** para preencher o campo. Quem cria a conta digita ou confere o e-mail e ele é
   confirmado pelo mesmo caminho do cadastro por e-mail (link de confirmação por e-mail, que também define a senha). Se o
   Facebook não mandar e-mail, o campo vem vazio e a pessoa informa.
3. **Conta nova pelo Facebook nasce não confirmada**, igual ao cadastro por e-mail: não faz login até confirmar.
   Reaproveita `CriarContaEEscritorioUseCase::executar`, que já esconde se o e-mail tem conta (anti-enumeração); o que
   muda é só gravar o `facebook_id` na conta nova. **Não** reaproveita `executarPeloGoogle` (cria confirmada).
4. **Pré-sequestro (achado da revisão):** o e-mail digitado não é do Facebook. Quem cria a conta com o `facebook_id`
   dele e o e-mail de outra pessoa não pode herdar a conta quando essa pessoa confirmar o link e criar a senha. Regra: o
   `facebook_id` só continua valendo depois da confirmação se o link for aberto **no mesmo navegador (mesma sessão)** que
   criou a conta pelo Facebook (o `CadastroPeloFacebookController` marca a sessão com o id da conta nova). Em qualquer
   outro caso (outro navegador, outro aparelho, sessão vencida) a confirmação **apaga o `facebook_id`** da conta: ela
   vira conta só de e-mail e senha, e quem quiser o Facebook liga depois (fora deste PR). Falha para o lado seguro: no
   pior caso a pessoa entra com a senha que acabou de criar.
5. E-mail que já tem conta: nada é ligado. A pessoa recebe o aviso que o cadastro por e-mail já manda e a tela
   responde igual a um cadastro novo.

(Divergência declarada: o coordenador falou em "confirmar por código"; o cadastro por e-mail do sistema confirma por
link, e o código existe só no "Esqueci a senha". Seguimos o mecanismo que existe, com a mesma garantia.)

### Comportamento

1. `FacebookLoginApi` (`App\Auth\Infra`), à mão com `HttpClientInterface`: `ligado()` (app id e secret não vazios),
   `urlDeAutorizacao()` (`state` na sessão, `scope=public_profile,email`, versão em `FACEBOOK_GRAPH_VERSION`),
   `identidade($code)`: troca o `code` em `graph.facebook.com/{v}/oauth/access_token`, depois `GET /me?fields=id,name,email`
   com `appsecret_proof` (HMAC-SHA256 do token com o secret). O token não é guardado. Devolve `IdentidadeDoFacebook`
   (`id`, `nome`, `email` nulo se não vier).
2. Rotas `GET /login/facebook` (`app_login_facebook`) e `GET /login/facebook/callback` (`app_login_facebook_volta`),
   com limiter próprio (`login_facebook`, 20 por 15 min, como o do Google), CSRF `login_facebook` na ida, `state`
   comparado com `hash_equals` e consumido uma vez. `error`, `error_reason` e retorno sem `code` valem como cancelado.
   Sem credencial a rota responde 404 (o botão nem aparece).
3. `FacebookAuthenticator` (como o do Google): `ContaDoFacebook::localizar(id)` só pelo `facebook_id`.
   - Conta ligada, confirmada e ativa: login (mesmo destino, termo de aceite e manter conectado do Google).
   - Conta ligada ainda não confirmada: não loga; volta ao login com o aviso de confirmar o e-mail (a mensagem e o
     reenvio que já existem para o cadastro por e-mail).
   - Conta ligada e inativa: recusa, como o Google.
   - Sem conta: guarda `IdentidadeDoFacebook` na sessão (`CadastroPeloFacebook`, chave `cadastro_pelo_facebook`,
     validade de 15 min, como o `CadastroPeloGoogle`) e volta a `app_login?abrir=cadastro`.
4. Tela: `User.facebook_id` não aparece. No passo `cad`, o botão `#lgCdFacebook` (`<a class="lg-soc__btn">`, SVG e texto
   "Continuar com Facebook" do desenho) logo depois do `#lgCdGoogle`, dentro de `{% if facebookDisponivel %}`. Com a
   identidade na sessão, o cartão conectado `#lgCdSocOk` mostra o provedor ("Conectado pelo Facebook", chave
   `entrada.modal.conectado_pelo` com `{provedor}`) e o nome; **o campo de e-mail continua visível** (diferente do Google),
   preenchido com a sugestão do Facebook se houver. O cartão generaliza `cadastroGoogle` para `cadastroSocial`
   (provedor, nome, e-mail sugerido). "Desconectar" chama o `esquecer` do provedor.
5. `POST /cadastro/facebook/criar` (`auth_cadastro_facebook_criar`): lê a identidade **da sessão, nunca do navegador**;
   CSRF `cadastro_criar`; limiter `cadastroAutoLimiter`; bloqueia logado; identidade ausente ou expirada vira 422
   `facebookExpirou`; o e-mail vem do corpo (campo da tela) e passa pela validação do cadastro por e-mail. Cria pela
   `executar` (conta não confirmada, link por e-mail) e grava o `facebook_id`. `facebook_id` já ligado a outra conta:
   mesma resposta neutra do e-mail existente, sem criar. Depois do POST, a sessão esquece a identidade e a tela mostra
   o mesmo "confirme seu e-mail" do cadastro por e-mail. O cupom (PR A) segue valendo pelo mesmo `CadastroPeloLinkListener`.
6. `POST /cadastro/facebook/esquecer` (`auth_cadastro_facebook_esquecer`), como o do Google. Qualquer outro login
   esquece a identidade pendente (`EsquecerContaAConfirmarNoLoginListener` conhece os dois provedores).
7. `ContaCriadaPeloCadastro` continua despachado só uma vez por conta criada (pelo `executar`).

### Dados

- Migration `Version20261026150800` (número 150800, dado pelo coordenador): `ALTER TABLE "user" ADD facebook_id
  VARCHAR(255) DEFAULT NULL` e `CREATE UNIQUE INDEX UNIQ_8D93D6499BE8FD98 ON "user" (facebook_id)` (nome que o Doctrine calcula, conferido
  com a skill `criar-migration`, como a do `google_sub`). Reversível (`down` apaga índice e coluna).
- `User::getFacebookId()` e `ligarAoFacebook(string $id)` (lança `DomainException` se já ligado a outro id), espelhando
  o Google. O `id` do Facebook é por app: se a Meta trocar de app, os ids mudam (risco operacional anotado, sem
  mitigação no código).
- Env: `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`, `FACEBOOK_GRAPH_VERSION` (default `v21.0`), com default vazio, declaradas
  em `.env.prod.example` como as do Google. Rotas nas listas brancas (`TermoAceiteListener::ROTAS_IGNORADAS`,
  `TenantContextValidatorListener`, `EntradaDoConvidadoListener`).

### Testes (com `FacebookLoginFalso`, como o `GoogleLoginFalso`)

- Ida: redireciona com `state`; sem credencial 404; limitador; sem CSRF recusa.
- Volta: `state` errado, replay, cancelado e `error=access_denied` não logam; `facebook_id` ligado e confirmado loga;
  ligado e não confirmado não loga e avisa; inativo recusa.
- **Anti-tomada de conta:** `/me` devolve o e-mail de uma conta existente com outro `id`: não loga, não liga, não
  confirma nada; vai ao cadastro com a sugestão.
- Cadastro: identidade sem e-mail, e-mail sugerido editado, e-mail de conta existente (resposta neutra, nada ligado,
  `facebook_id` intacto), `facebook_id` já usado, identidade ausente ou expirada, logado, CSRF inválido, outro login
  esquece a identidade; conta nasce não confirmada, com `facebook_id`, e o link confirma.
- Tela: botão some sem credencial; com credencial vem logo depois do Google; cartão conectado mostra o provedor e o
  campo de e-mail segue visível.
- Unit: `ContaDoFacebook`, `CadastroPeloFacebook`, `IdentidadeDoFacebook`, `appsecret_proof` (vetor conhecido).
- Ajustar `CadastroPeloGoogleTest`: o Facebook deixa de ser ausência travada (some só sem credencial).
