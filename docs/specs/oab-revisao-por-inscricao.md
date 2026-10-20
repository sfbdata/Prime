# Spec: revisão das OABs pelo Master, por inscrição

## Estado da frente (atualizado em 20/10/2026)

- Branch `oab-revisao-por-inscricao`, **empilhada sobre o #65** (`meu-perfil-oab`, da 7d), criada a partir de
  `origin/meu-perfil-oab` sem tocar na branch da 7d. Merge depois do #65 (e do #60, base dele).
- Risco **ALTO** (subiu de MÉDIO na revisão cruzada do #143: o PR toca `ConfirmarCadastroUseCase`,
  `AceitarConvitePlataformaUseCase` e `CriarEscritorioUseCase`, identidade User/Tenant). **Sem migration.**
- Pedido do coordenador em 20/10: o desenho 07 diz que a suplementar "fica Pendente até o Master validar"
  (L217 a 219), mas **o desenho é omisso** sobre a tela: o 05 só tem a seção "Usuários e OABs" de leitura
  (L183 a 192). Vale a convenção: a **tela que já existe** (`/admin/platform/oab`) passa a listar por inscrição.
- Nenhum PR aberto (inclusive da b5) toca `OabReviewController` ou `templates/admin/platform/oab.html.twig`
  (conferido em 20/10).

## O que muda

- A lista deixa de ser por **usuário** (só a principal) e passa a ser por **inscrição** (`InscricaoOab`,
  principal e suplementares), pelo `InscricaoOabRepository::paraRevisao` e `contarParaRevisao` do #65.
- Cada linha: o nome e o e-mail do usuário (como hoje), número/UF, selo **PRINCIPAL** ou **SUPLEMENTAR**,
  situação e data da verificação.
- **Mesmos filtros** de hoje (Pendentes, Divergentes, Não verificadas, Confirmadas, Todas), mesma busca e
  paginação, mapeados para os `StatusOab`.
- **Mesmas ações** (confirmar, reverter para Não verificada ou Divergente, reverificar), agora pelo **id da
  inscrição**, ligadas aos 3 UseCases do #65 (`ConfirmarInscricaoOabUseCase`, `ReverterInscricaoOabUseCase`,
  `VerificarInscricaoOabUseCase`). A principal continua espelhada no usuário (os UseCases já fazem).
- Sem recusa com motivo (a entidade não tem campo; fica de fora).
- Edição mínima no `OabReviewController` e no template; nomes de rota mantidos quando possível.

## Revisão de 20/10 (corrigido)
- Paginação estável: desempate pelo id (o backfill do #65 grava o mesmo `criado_em`).
- Toda pessoa com OAB no `User` passa a ter a principal: cadastro público, convite da plataforma e criar
  escritório com OAB confirmada criam/espelham a principal pelo `EspelhoDaOabPrincipal` (antes só o Meu
  Perfil criava sob demanda, e essas pessoas sumiam da fila).
- Registrado: o Painel do Master conta pessoas pelo `User.oab*` e a fila conta inscrições (inclui
  suplementares); números diferentes por desenho, não é defeito. Medir em produção, depois do deploy do #65,
  quantos `user.oab_status IS NOT NULL` ficaram sem `inscricao_oab` (consulta pelo MCP `jusprime-prod`).

## Master trazido em 20/10 (depois do #65)
- O cadastro público mudou no master (`CriarContaEEscritorioUseCase`, desenho 10): a conta nasce **sem OAB**, então
  não há principal a criar ali; a OAB entra depois pelo Meu Perfil (#65), que cria a inscrição. O
  `ConfirmarCadastroUseCase` ficou para os links pendentes de antes, e continua criando a principal (teste
  `testCadastroPendenteCriaPrincipal`, sem o `IniciarCadastroPublicoUseCase`, que saiu do master).
- O convite da plataforma e o confirmar cadastro ganharam o evento do Comercial no master: a principal segue
  criada antes do flush, e o evento sai depois.

## Revisão cruzada do #143 (20/10, corrigido)
- Confirmar e reverter amarram o que o Master viu: o form envia o número e a UF exibidos, e a ação recusa com
  o aviso "A inscrição mudou depois que a fila foi aberta. Confira de novo." se não baterem com o atual
  (`InscricaoOab::alterar` mantém o id). A conferência fica no controller, antes do UseCase; os UseCases do #65
  não mudaram. Re-verificar fica livre (consulta de novo).
- Criar escritório com a principal já existente só acompanha: não duplica, a Confirmada segue Confirmada e as
  suplementares ficam intactas (teste).
- Corrida no `garantirPrincipal` (usuário já gravado, duas abas): a principal nasce por
  `InscricaoOabRepository::criarPrincipalSeFaltar` (INSERT com os valores em memória e ON CONFLICT DO NOTHING),
  sem estourar `uniq_inscricao_oab_principal`. Usuário novo (cadastro, convite) segue por `persist`, sem corrida.
  O teste prova a principal já gravada (com COMMIT) por outra requisição, que o `NOT EXISTS` vê; a corrida com a
  outra linha ainda sem COMMIT não se testa numa conexão só (DAMA), e ali a garantia é do `ON CONFLICT DO NOTHING`
  do Postgres (sem alvo, usa todos os índices únicos como árbitros, inclusive o parcial).
- Recusa (403) testada nas três ações, para usuário comum e para admin de escritório que não é Master.

## Re-revisão (20/10, risco ALTO): riscos conhecidos e aceitos
- **Principal gravada antes do fim do "criar escritório"**: o `criarPrincipalSeFaltar` grava na hora (para
  resolver a corrida). Se o salvamento final do escritório falhar por problema de banco ou infraestrutura
  (erro 500), fica uma principal Não verificada sem OAB no `User`; ela se corrige no envio seguinte (o
  formulário pede a OAB de novo e `alterar` acerta). Nada é confirmado sem o Master e ninguém ganha acesso.
  Pôr o caso de uso inteiro numa transação mexeria no fluxo de identidade; fica para frente própria.
  **Vale enquanto a verificação automática no CNA estiver dormente** (hoje o `ValidadorOab` devolve Não verificada).
  Com ela ligada, o INSERT antecipado pode gravar uma principal **Confirmada** pela verificação mesmo com o
  escritório não salvo, e quem já tinha OAB no `User` a mantém; ao religar o CNA, revisar este risco (mover o
  `garantirPrincipal` para depois do salvamento ou pôr o caso de uso numa transação).
- **Auditoria**: a criação da principal por esse INSERT não aparece no log de auditoria da `InscricaoOab`
  (como o `criarPrincipalDoEspelhoSeFaltar` do #65); a mudança no `User.oab*` continua auditada. As ações do
  Master passam pelo ORM e são auditadas.
- **Janela entre conferir e gravar**: confirmar/reverter conferem número e UF e gravam na mesma requisição;
  uma troca feita pelo titular nos milissegundos entre os dois passa. Já existia; só fecharia com trava ou
  versão.

## Caso conhecido (já era assim antes)
- Usuário só com suplementares e sem principal: o `garantirPrincipal` não cria principal nenhuma (só cria quando o
  usuário não tem inscrição alguma; com suplementares, só acompanha a principal se ela existir), e o `User.oab*` fica sem principal correspondente. Não é mudança
  deste PR; registrado na re-revisão de 20/10.
- O laço de avisos do template da fila era código morto (o `base.html.twig` esvazia a flashbag antes); saiu.

## Segurança
- Só quem já acessa a tela hoje (`ROLE_SUPER_ADMIN`, conferir no controller) e CSRF nas ações, como hoje.
- Id de inscrição inexistente: 404 (o UseCase lança `DomainException`).
- Cada ação fica na auditoria (`InscricaoOab` é `Auditavel`).

## Verificação
- Functional: lista mostra principal e suplementar com o selo; filtros; confirmar/reverter/reverificar uma
  suplementar muda só ela; confirmar a principal espelha no usuário; sem permissão 403 (usuário comum e
  admin de escritório, nas três ações); CSRF errado 403; id inexistente 404; inscrição alterada depois de
  aberta a fila recusa confirmar e reverter.
- Navegador: a tela com uma suplementar Pendente sendo confirmada.
