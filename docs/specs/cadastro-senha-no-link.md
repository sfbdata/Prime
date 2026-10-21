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
- 21/10: #246 no master (sem D1 e D2). D1 e D2 decididos pelo dono; implementação na frente `cadastro-sem-senha-e-aceite`.
- 21/10: D1 feito na frente `cadastro-sem-senha-e-aceite` (commit "Tira o campo Senha do cadastro"): o passo `cad` do
  modal perdeu o `#lgCdSenha` e a régua; o `entrada.js` não exige nem envia a senha. O D2 espera a migration: os
  UNIQUE `(user_id, versao)` de `aceite_termo` e `ciencia_politica` impedem gravar um aceite novo da mesma versão sem
  apagar o do cadastro.
- 21/10: D2 feito na mesma frente (commit "Grava o aceite dos Termos no link de confirmação"), com a migration
  `Version20261020149400` liberada pelo coordenador: coluna `origem` (enum `App\Termo\Enum\OrigemDoAceite`:
  `cadastro`, `link_confirmacao`, `reaceite`; DEFAULT `cadastro` no banco e no mapeamento) nas duas tabelas e o
  UNIQUE trocado por (user_id, versao, origem). `existeAceiteVigente` continua olhando só (user, versao). Ordem do
  POST: CSRF, token, já confirmado, aceite, força, igualdade. O `CriarSenhaPeloLinkUseCase` monta o `AceiteTermo` e a
  `CienciaPolitica` (escritório: o mais antigo com `criadoPor` = a conta, ou nulo) e o
  `criarSenhaEConfirmarComLink` os grava dentro da transação, só quando ganha. Deploy da migration MANUAL.
- 21/10: D2b feito na mesma frente (commit "Ratifica no gate o aceite de conta confirmada pelo código"); ver o item D2b
  nas decisões.

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

## Decisões do dono (21/10/2026, via coordenador), PR seguinte ao #246

- **D1 = tirar o campo Senha do cadastro** (desvio do desenho 10, aprovado pelo dono). O passo "Criar conta" do modal
  perde o campo "Senha" e a régua de força; o JS não exige nem envia senha; o servidor continua ignorando `senha` se
  vier (cliente antigo em cache).
- **D2 = pedir e gravar um aceite novo na página "Crie sua senha".** A página ganha a mesma caixa do cadastro ("Li e
  aceito os Termos de uso e a Política de privacidade", com os links), obrigatória. Sem ela, o POST recusa com a
  mensagem do cadastro e não consome o link. Com ela, na mesma transação da senha, grava-se um aceite novo dos Termos e
  a ciência da Política, pelo mesmo mecanismo do cadastro (versão vigente, momento, IP e user agent). Os aceites do
  cadastro continuam no histórico, sem apagar.

- **D2b = a conta confirmada pelo código do "Esqueceu a senha?" ratifica o aceite no gate dos Termos** (decisão do
  coordenador, achado da revisão; sem migration nova). Só quando o UPDATE condicional do `ConfirmarCodigoSenhaUseCase`
  de fato confirma uma conta a confirmar, na mesma transação, o aceite dos Termos e a ciência da Política com origem
  `cadastro` passam a `cadastro_nao_ratificado` (a linha fica; só o rótulo muda). `existeAceiteVigente` ignora essa
  origem, então na primeira entrada o gate dos Termos para a pessoa e grava `reaceite`. O gate não cobria a Política:
  para quem tem só a ciência `cadastro_nao_ratificado` da versão vigente, a caixa do gate diz "Li e aceito os Termos e
  Condições Gerais de Uso do BlueJus e a Política de privacidade." (com o link) e o aceite grava também a ciência
  `reaceite` (`RatificarCienciaPoliticaUseCase`). Para os outros, o gate não muda. Nada muda no link nem na troca de
  senha de conta já confirmada. A coluna `origem` foi para VARCHAR(30) na própria `Version20261020149400` (ainda não
  publicada), porque `cadastro_nao_ratificado` tem 23 caracteres.

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
