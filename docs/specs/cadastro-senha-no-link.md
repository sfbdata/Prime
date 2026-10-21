# Cadastro: a senha é criada no link de confirmação

Risco ALTO (identidade User). Decisão do dono (21/10/2026, via coordenador): opção (a) para a brecha que sobrou do
#201 (`cadastro-exige-confirmacao.md`). Frente da 9b, `cadastro-senha-no-link`. Sem migration.

## Estado da frente

- 21/10: spec; implementação começando. Pendentes do dono (via coordenador): itens D1 e D2.
- 21/10: implementada na branch `cadastro-senha-no-link` (commit local, sem push), sem D1 nem D2. O cadastro grava o
  hash de 32 bytes aleatórios e não valida mais a senha digitada. O GET do link só olha
  (`LinkDeConfirmacaoDeEmail`, as regras do token extraídas do antigo `ConfirmarEmailDaContaUseCase`, que saiu por
  não ter mais quem o chame); o POST (`auth_cadastro_criar_senha`, mesma URL, CSRF `cadastro_criar_senha`) chama o
  `CriarSenhaPeloLinkUseCase`, e a senha vai no mesmo UPDATE condicional que confirma
  (`ConfirmacaoEmailRepository::criarSenhaEConfirmarComLink`, no lugar do `confirmarComLink`). A página leva
  `Referrer-Policy: no-referrer` e `Cache-Control: no-store`. A régua da página é um JS próprio
  (`public/js/auth/criar-senha.js`) sobre a barra do Bootstrap. `SessaoDeContaAConfirmarListener` libera a rota nova.
  Testes direcionados e de arquitetura verdes; falta o smoke do dono na tela.

## A brecha

Um terceiro cadastra o e-mail de outra pessoa com uma senha que ele escolhe. A dona do e-mail recebe o link e
clica ("parece meu"): o e-mail fica confirmado e o terceiro entra com a senha dele numa conta que agora parece
legítima.

## Regra nova

Quem clica no link de confirmação **cria a senha naquela hora**. A senha digitada no cadastro, se vier, é descartada.
Vale sempre, inclusive quando quem clica é quem se cadastrou (pede a senha de novo, por simplicidade; decisão do
coordenador).

## Comportamento

1. **Cadastro** (`POST /cadastro/criar`): a conta nasce com uma senha inutilizável (hash de 32 bytes aleatórios,
   jogados fora). Nada mais muda no cadastro (escritório, Termos, Política, indicação, link de 7 dias, sem login,
   estado "Confirme seu e-mail" do modal). Se o formulário ainda mandar `senha`, ela é ignorada (ver D1).
2. **Link** (`GET /cadastro/confirmar-email/{token}`):
   - token válido e e-mail a confirmar: a página "Crie sua senha" com "Nova senha" e "Confirmar nova senha" (a régua
     de força e os textos da troca de senha por código), botão "Criar senha e confirmar". O GET **não confirma
     nada** (de quebra, o robô de antivírus que abre o link não confirma mais a conta);
   - e-mail já confirmado: "E-mail confirmado." com Entrar, como hoje (idempotente; sem formulário);
   - inválido ou vencido: "Não foi possível confirmar", como hoje.
3. **Criar a senha** (`POST` na mesma URL, CSRF próprio): confere o token de novo (as mesmas regras do
   `ConfirmarEmailDaContaUseCase`), a força (`ForcaDaSenha`, o mesmo máximo do cadastro) e a igualdade das duas. Na
   mesma transação: grava a senha, confirma o e-mail e marca o link como usado. `EmailDaContaConfirmado` depois do
   commit, uma vez só. Quem perde a corrida (o e-mail já foi confirmado por outro clique) recebe "E-mail confirmado."
   **sem** trocar a senha. Erros de senha voltam ao formulário com a mensagem, sem consumir o link. Não faz login
   sozinho: "E-mail confirmado. Sua senha foi criada." com Entrar.
4. **Sessões e lembrar-me:** a conta a confirmar não tem sessão aberta (o #201 já recusa); a troca do hash invalida
   qualquer REMEMBERME antigo (a assinatura leva a senha).
5. **Entrar antes de confirmar:** como a conta não tem senha utilizável, o login responde o erro genérico de
   credencial. A recusa "Confirme o seu e-mail" do `UserChecker` continua como defesa (contas antigas com senha).
6. **Sem o link:** o modal "Confirme seu e-mail" continua com o reenvio (sessão `conta_a_confirmar`, 30 min). Depois
   disso, o caminho é "Esqueceu a senha?" (o link da tela de login): o código chega ao e-mail, confirma o e-mail e cria a senha (já é assim). O
   texto `SEM_CONTA_NA_SESSAO` passa a apontar esse caminho: "Para receber um novo acesso, use "Esqueceu a senha?":
   enviamos um código para o seu e-mail."
7. **Textos que mudam** (o resto fica):
   - modal depois do cadastro: "Clique nele para criar a sua senha e ativar sua conta e seu escritório. O link
     expira em 7 dias.";
   - e-mail de confirmação (também corrige o "já pode usar o seu escritório", errado desde o #201): "Sua conta no
     BlueJus foi criada. Para ativá-la, confirme que este e-mail é seu e crie a sua senha. O link vale por 7 dias.";
     botão "Confirmar e criar minha senha"; o resto igual.
8. **Contas a confirmar que já existem** (as 2 de produção do #201 e as do lembrete do Comercial): recebem a regra
   nova. O link delas pede a senha e substitui a antiga. Sem tratamento especial.
9. **Convites e Google:** nada muda (as contas de convite nascem confirmadas; o Google está em standby).

## Decisões em aberto (dono, via coordenador)

- **D1. O campo Senha no formulário de cadastro.** Recomendação da 9b: **tirar** o campo e a régua de força do
  passo "Criar conta" (desvio do desenho 10). Pedir uma senha que vai ser jogada fora engana quem se cadastra e a
  faz digitar duas vezes. Até a resposta, o campo fica e o servidor o ignora; se aprovado, sai num commit separado.
- **D2. Termos e Política.** O aceite gravado no cadastro foi dado por quem cadastrou, que pode não ser a dona do
  e-mail. Recomendação: a página "Crie sua senha" diz "Ao criar a senha, você aceita os Termos de Uso e declara ciência
  da Política de Privacidade" (com os links) e grava um aceite novo no momento da criação da senha. Até a resposta,
  nada disso entra e o aceite do cadastro continua valendo.

## Testes

- Unit do UseCase novo: token válido cria a senha e confirma; vencido e inválido recusam; já confirmado não troca a
  senha; senha fraca ou diferente recusa sem consumir o link; evento uma vez só.
- Functional: o GET não confirma; o POST com CSRF; sem CSRF não muda nada; a senha do cadastro não entra (login com
  ela falha antes e depois de confirmar); depois de criar a senha, entra com a nova; o REMEMBERME antigo cai; a
  corrida (dois POSTs) só grava uma senha; textos do modal e do e-mail.

## Fora

- O caminho antigo `/cadastro/confirmar/{token}` (`ConfirmarCadastroUseCase`, `CadastroPendente`) ainda grava a senha
  escolhida no cadastro, mas está morto: nada cria `CadastroPendente` desde o desenho 10, e a tabela
  `cadastro_pendente` está vazia em produção (consulta de 21/10). Aposentar a rota fica para uma limpeza à parte.
