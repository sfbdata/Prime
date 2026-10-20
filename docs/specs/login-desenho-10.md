# Login do desenho 10 ("10 - Login - VS 19.10.26")

**Risco:** ALTO (identidade User/Tenant, autenticação) · **Frente:** `login-desenho-10` (sessão 9b) · **Data:** 19/10/2026

## Estado da frente

- 19/10 noite: spec e contratos escritos; implementação em quatro blocos (A senha, B cadastro, C suporte e ajustes, D tela).
- Migration: UMA para a frente toda, `Version20261020091500` (a 090000 colidia com o #87), na fila logo depois do #56 (coordenador). Renomear de novo para o momento, se preciso, na hora do merge.
- Arquivo quente tocado: `app/config/packages/framework.yaml` (um limitador novo, `suporte_entrada`). Avisado ao coordenador.
  `security.yaml`, `doctrine.yaml` e `base.html.twig` **não** são tocados: as rotas novas moram sob `/login`, `/senha` e
  `/cadastro`, que já são `PUBLIC_ACCESS`; as entidades novas ficam em `Auth/Entity` e `Termo/Entity`, já mapeadas.
- Combinado com a 5d (Comercial): eventos `ContaCriadaPeloCadastro(userId, tenantId)` e `EmailDaContaConfirmado(userId)`,
  despachados depois do commit; o lembrete "e-mail não confirmado" passa a ser sobre o User e fica com esta frente
  (consulta + `ReemitirConfirmacaoDeEmailUseCase`). A 1a da 5d entra antes; esta frente leva os ganchos dela para os pontos novos.

- 19/10 noite: integrados os 5 blocos; revisão do feature-review-agent reprovou com 2 ALTOS, corrigidos: (1) conta com e-mail a confirmar não vê convites em `/convites` nem na seleção de escritório (a lista entregava o token); (2) a tentativa do código é reservada no banco ANTES de comparar e `/senha/codigo/confirmar` consome o limite por IP. Também: régua com classe ASCII explícita (acento conta como símbolo, igual à tela), um véu só depois do cadastro (o cadastro devolve o destino final), limite de senha em bytes, confirmação de e-mail com UPDATE condicional (evento uma vez), lembrete único no banco, filtro http(s) nos links do painel e do aceite.
- 19/10 noite: re-revisão aprovou com ressalvas (duas corrigidas); suíte completa verde (10.726 testes, paralela -p 3); qa.sh limpo. PR aberto como rascunho: falta a verificação visual (navegador depende de liberação do dono).
- 20/10: correções da revisão cruzada.
  (1) Destinatário do suporte: `PaginaDeEntrada::destinatarioDoSuporte()` só devolve a pessoa escolhida enquanto ela estiver em `opcoesDeDestinatario()` (Master ativo ou usuário ativo da equipe da conta do Master); quem sai da equipe (vínculo desativado ou removido) perde na hora a leitura de `/suporte/mensagens` (404), o aviso volta para todos os Masters e a tela do Master deixa de mostrá-la como escolhida.
  (2a) Com o e-mail a confirmar, a conta não manda e-mail a terceiros: criar e reenviar convite de escritório (`CriarConviteEscritorioUseCase`, `ReenviarConviteUseCase` com `ReenviarConviteInput::$reenviadoPor`, obrigatório desde a re-revisão), convidar e reenviar convite do Trabalho Externo (`ConvidarProfissionalUseCase` e `ReenviarConviteUseCase` do módulo, com o mesmo texto do convite de escritório, 422) e gerar o link externo do Push (`GerarLinkExternoDoPushUseCase`) recusam com DomainException ("Confirme o seu e-mail para convidar pessoas. Reenviamos o link de confirmação se você pedir." · "Confirme o seu e-mail para compartilhar o processo por link.").
  (2b) Trocar a senha pelo código do e-mail confirma o e-mail (`ConfirmarCodigoSenhaUseCase`, na mesma transação). Se a conta estava com o e-mail a confirmar, despacha `EmailDaContaConfirmado` depois do commit, uma vez só: o UPDATE condicional (`ConfirmacaoEmailRepository::confirmarEmailDaConta`) decide, e quem já confirmou pelo link não dispara de novo.
  (3) Teto diário por conta + IP de origem no pedido de código: com 10 pedidos da mesma conta vindos do mesmo IP nas últimas 24 h (`RedefinicaoSenhaRepository::contarPedidosDesde`), nada é criado nem enviado, a resposta segue neutra e fica um aviso no log. Por IP, e não só por conta (re-revisão de 20/10): só por conta, um atacante trancaria a recuperação de senha de qualquer pessoa em cerca de 2 h; assim ele só tranca o próprio IP, e o dono, de outro IP, continua recebendo código. Para a conta valer, o pedido novo **encerra** os anteriores (`usado_em`) em vez de apagá-los, e a purga diária preserva as linhas criadas dentro da janela do teto (`RETENCAO_MINIMA` deriva de `RedefinicaoSenhaRepository::JANELA_DO_TETO`, a mesma que o UseCase usa).
  (A2 da re-revisão) Em `/suporte/mensagens`, o e-mail vai codificado para URL no `mailto:` (`url_encode`, o @ vira %40, válido pela RFC 6068): um endereço aceito pelo validador como `cliente%40escritorio.com?cc=atacante@evil.com` não injeta Cc.
  (7/8) E-mail do cadastro e do suporte validado pela constraint Email do Symfony no modo estrito (egulias): "a@b.com?cc=c@d.com" e "a@b.com,c@d.com" são recusados, com os mesmos textos do desenho.

## O pedido

Dono, pelo coordenador: *"amanhã quero a página de login implementada como no desenho, todos os textos como está no desenho"*.
Alvo visual e de texto: `docs/design/redesign-19-10-26/10 - Login - VS 19.10.26.dc.html` (fora do Git; a pasta `uploads/`
tem documentos pessoais e nunca é commitada).

## Decisões do dono (repassadas pelo coordenador, 19/10)

| # | Decisão | Como fica |
|---|---|---|
| a | Cadastro só com os campos do desenho, **sem OAB** | OAB vai para o Meu Perfil depois (#65 da 7d) |
| b | Caixa única "Li e aceito os Termos de uso e a Política de privacidade" | grava `aceite_termo` (versão `TermoVigente`) **e** `ciencia_politica` (versão `PoliticaPrivacidadeVigente`), os dois com data, IP e navegador |
| c | "Criar minha conta" já entra no sistema | conta criada direto e logada; e-mail de confirmação enviado sem travar; `user.email_a_confirmar_desde` fica preenchida até o clique; enquanto isso a conta não vê convites de escritório nas listas |
| d | Termos e Política do rodapé apontam para o destino do desenho | padrões `https://www.bluejus.com.br/termos/termos-de-uso.pdf` e `https://www.bluejus.com.br/politica-de-privacidade`, editáveis pelo Master, com Restaurar |
| e | Textos de segurança ("Criptografia de ponta a ponta", painel "Segurança e privacidade") **exatos**, sem chave e sem versão alternativa | decisão e responsabilidade do P.O. (sessão 7d). Ver memória "Login promete criptografia que não existe" |

## Itens do desenho fora do produto (não entram, com motivo)

- `Protótipo: o código de teste é <b>…</b>`: andaime do protótipo; mostrar o código na tela anula a senha por código.
- O selo verde tem um bloco expansível (`segOn`/`tgSeg`) que nenhum clique do desenho alcança. Prova: `tgSeg` aparece uma única vez no arquivo, na própria definição (`tgSeg: () => set({ seg: !s.seg })`), e nenhum elemento o usa; o selo chama `onClick="{{ segAbrir }}"` (o painel). Código morto do protótipo; segue o clique do desenho.
- `localStorage` `bj-manter` (e-mail lembrado por 30 dias): é como o protótipo simula "Manter conectado". No sistema, "Manter conectado" é o `_remember_me` do Symfony (30 dias, já configurado).
- `?bemvindo=1` no Expediente depois de criar a conta: só o desenho 10 escreve o parâmetro; o desenho 03 (Expediente) nunca o lê (`grep bemvindo` no 03 não acha nada), então não há tela de boas-vindas desenhada. O cadastro leva ao Expediente como o login.

## Contratos

### Rotas (todas sob caminhos já `PUBLIC_ACCESS`, salvo a última)

| Método e caminho | Nome | Quem | Corpo | Resposta |
|---|---|---|---|---|
| GET `/login` | `app_login` | anônimo; Master logado também vê (com as ferramentas SÓ MASTER); outro logado é redirecionado | | página |
| GET `/senha/esqueci` | `auth_senha_esqueci` | anônimo | | a página de login com o passo "Recuperar senha" aberto |
| GET `/cadastro` | `auth_cadastro` | anônimo | | a página de login com "Criar conta e escritório" aberto |
| POST `/senha/codigo` | `auth_senha_codigo_enviar` | anônimo | JSON `{email, _token}` (CSRF `senha_codigo`) | sempre `200 {ok:true}` (resposta neutra RN02); `429` no limite |
| POST `/senha/codigo/confirmar` | `auth_senha_codigo_confirmar` | anônimo | JSON `{email, codigo, senha, confirmacao, _token}` | `200 {ok:true}` · `422 {erro:"<texto do desenho>"}` |
| POST `/cadastro/criar` | `auth_cadastro_criar` | anônimo | JSON `{nome, escritorio, email, senha, aceite, _token}` (CSRF `cadastro_criar`) | `200 {ok:true, destino:"/login/entrando?..."}` já logado · `422 {erro}` · `429` |
| GET `/cadastro/confirmar-email/{token}` | `auth_cadastro_confirmar_email` | qualquer um | | marca o e-mail confirmado e mostra o resultado |
| GET `/login/entrando` | `app_login_entrando` | logado | `?para=<caminho relativo validado>` | véu "Bom dia, Nome! / Abrindo o seu escritório digital…" e segue para `para` em 2,1 s |
| POST `/login/suporte` | `auth_suporte_enviar` | anônimo | JSON `{nome, email, assunto, mensagem, _token}` (CSRF `suporte_entrada`) | `200 {ok:true, email}` · `422 {erro}` · `429` |
| POST `/login/links/{chave}` | `auth_entrada_link_salvar` | Master (`ROLE_SUPER_ADMIN`) | JSON `{acao: salvar\|restaurar\|ocultar\|mostrar, url?, _token}` (CSRF `entrada_ajuste`) | `200 {ok:true, links}` · `422 {erro}` · `403` |
| POST `/login/suporte/destinatario` | `auth_suporte_destinatario` | Master | JSON `{userId, _token}` (CSRF `entrada_ajuste`) | `200 {ok:true, texto}` · `403` |
| GET `/suporte/mensagens` | `auth_suporte_mensagens` | Master ou o destinatário escolhido | | lista das mensagens de suporte (onde o aviso do sino leva) |

`{chave}` ∈ `termos`, `priv`, `linkedin`, `instagram`.

### Textos de erro (exatos do desenho)

- e-mail inválido: `Informe um e-mail válido.` · senha vazia no login: `Informe a senha.`
- código errado, vencido ou inexistente (todos iguais, neutros): `Código incorreto. Confira o e-mail ou reenvie.`
- senha fraca: `Use pelo menos 8 caracteres, com letras e números.` · confirmação diferente: `As senhas não conferem.`
- cadastro: `Informe seu nome completo.` · `Informe o nome do escritório.` · `É preciso aceitar os Termos e a Política de privacidade.`
- suporte: `Informe um e-mail válido para a resposta.` · `Escreva a sua mensagem.`
- link: `Informe um endereço começando com https://`

### Regra de senha (cliente e servidor iguais, `ForcaDaSenha`)

`pontos = (len ≥ 8) + (tem A-Z e a-z) + (tem dígito) + (tem caractere fora de [A-Za-z0-9_])`; aceita se `len ≥ 8 e pontos ≥ 2`.
Rótulos: 0 `''`, 1 `Fraca` `#c0392b` 25%, 2 `Média` `#c76a12` 50%, 3 `Boa` `#0a63c9` 75%, 4 `Forte` `#1e7b45` 100%.

### Modelo de dados (uma migration)

- `"user".email_a_confirmar_desde` `timestamp NULL` (mudou na implementação, combinado com a 5d): nula = e-mail sem pendência. Só o cadastro novo preenche; o clique zera. Sem backfill: as contas existentes já provaram o e-mail (cadastro antigo ou convite). A data da confirmação fica em `confirmacao_email.usado_em`.
- `confirmacao_email`: índice único parcial `uq_confirmacao_email_lembrete (user_id) WHERE lembrete` (um lembrete por conta).
- `confirmacao_email` (Auth): `id`, `user_id` FK CASCADE, `token_hash` char(64) único (SHA-256), `criado_em`, `expira_em` (+7 dias), `usado_em` NULL, `lembrete` bool (o reenvio automático único da 5d).
- `redefinicao_senha.tentativas` int default 0. O código de 6 números é guardado como `hash_hmac('sha256', userId.':'.codigo, kernel.secret)` na coluna `token_hash` já existente; 15 minutos; 5 tentativas erradas queimam o pedido.
- `ciencia_politica` (Termo): `id`, `user_id`, `versao` varchar(40), `ciente_em`, `ip`, `user_agent`; único `(user_id, versao)`.
- `ajuste_entrada` (Auth): `chave` varchar(40) PK, `valor` text NULL, `oculto` bool, `alterado_em`, `alterado_por_id` FK SET NULL. Chaves: `link.termos`, `link.priv`, `link.linkedin`, `link.instagram`, `suporte.destinatario` (valor = id do User).
- `mensagem_suporte` (Auth): `id`, `nome` varchar(120), `email` varchar(180), `assunto` varchar(40) NULL, `mensagem` text, `origem` varchar(20) (`login`), `ip`, `user_agent`, `criada_em`, `destinatario_id` FK SET NULL NULL.

### Eventos (combinados com a 5d)

- `App\Auth\Event\ContaCriadaPeloCadastro(int $userId, int $tenantId)` depois do commit do cadastro.
- `App\Auth\Event\EmailDaContaConfirmado(int $userId)` depois do commit da confirmação.

## Comportamento

1. **Entrar.** Formulário mantém o contrato de `tela-login-redesenho.md` (`email`, `password`, `_csrf_token`, `_remember_me`, botão "Entrar", um `a[href="/senha/esqueci"]`). Caps Lock em `keydown` e `keyup`. Erro de credencial no lugar do `erro` do desenho.
2. **Volta pós-login.** `UserAuthenticator::onAuthenticationSuccess` lê o `_security.main.target_path`; aceita só caminho relativo (começa com `/`, não `//`, sem `\`, sem esquema) ou URL absoluta do próprio host (reduzida a caminho). Destino final: com 1 escritório → o alvo (ou o Expediente); Master sem escritório → painel do Master (ou o alvo); vários escritórios → seleção de escritório (o alvo se perde, como hoje). Tudo passa por `/login/entrando?para=` que revalida.
3. **Senha por código.** Passo 1 envia e-mail "Seu código BlueJus: NNNNNN" (assunto) e vai sempre para o passo 2 (neutro). Pedir de novo derruba os anteriores. Passo 2 confere o código, a regra de senha e a confirmação; grava a senha (troca de hash já derruba as outras sessões e o "lembrar-me", ver `RedefinirSenhaUseCase`). Passo 3: "Senha alterada". O link antigo (`/senha/redefinir/{token}`) continua valendo para pedidos já enviados.
4. **Criar conta.** Valida como o desenho; e-mail já existente → `Já existe uma conta com este e-mail. Faça login para criar o escritório por dentro.` Uma transação: User (ativo, `email_a_confirmar_desde` = agora), Tenant, bootstrap, aceite dos Termos, ciência da Política, `confirmacao_email`. Depois do commit: login programático, e-mail de confirmação (falha só registra em log), evento. O `/cadastro/confirmar/{token}` antigo continua para pendentes já enviados.
5. **Suporte.** Grava a mensagem e avisa no sino o destinatário escolhido pelo Master (sem escolha: todos os Masters). Mensagem mostrada só na lista `/suporte/mensagens`. Honeypot e limite `suporte_entrada` (5 por hora por IP).
6. **SÓ MASTER.** Botão direito nos links do rodapé abre "Editar link · <Nome>" com Salvar, Abrir, Restaurar e Ocultar/Mostrar no rodapé. O ⋮ do suporte mostra "QUEM RECEBE AS MENSAGENS DE SUPORTE · SÓ MASTER VÊ" com os Masters e a equipe do escritório atual do Master. Visitante comum não vê link oculto nem link vazio (LinkedIn e Instagram nascem vazios).

## Verificação

Suíte completa da frente, `scripts/qa.sh`, revisão e re-revisão do `feature-review-agent`, e lista ✓/✗ do desenho item a item. Verificação visual no navegador só com liberação do dono.

## Para o dono (confirmar)

1. **Quem recebe o suporte (interpretação do desenho, coordenador 19/10):** "o Master e os usuários da conta" = os Masters
   e os usuários ativos das contas do Master (`RegraDosMasters::ehContaDoMaster`, a regra do Painel do Master). Nunca a
   equipe do escritório em que o Master estiver navegando: assim um cliente jamais lê o suporte de outros visitantes.
2. **"recebe as mensagens no Chat I.A."**: no desenho é o `bjSuporteFila` do `bluejus-central.js` (L6643), que vira um aviso
   tipo "mensagem" na Central ("Suporte · Nome", "💬 Nome (e-mail) pela página de login: ...", ação "Responder por
   e-mail"). Implementado assim: aviso no grupo Mensagens da Central, só numa conta do Master (a Notificacao é auditada),
   e a lista `/suporte/mensagens` com "Responder por e-mail".
3. **Caixa "Protótipo: o código de teste é"** não entrou: mostrar o código anularia a senha por código.
