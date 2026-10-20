# Excluir a conta em 2 etapas pelo primário (PR 5 do desenho 07)

## Estado da frente (sessão bluejus-0d; atualizado 20/10/2026)

Releia esta seção antes de continuar se a sessão for compactada.

- Origem: frente da 7d (parada desde 19/10, 19h), passada à 0d pelo coordenador. Risco ALTO (identidade Tenant/User):
  spec, revisão read-only, correção, re-revisão, suíte completa com a trava e verificação visual contra o desenho 07.
- Branch `conta-pedido-exclusao`, EMPILHADA sobre `meu-perfil-oab` (#65, que está sobre o #60): o card mora na página
  nova do Meu Perfil. A branch já trouxe o `origin/master` (esqueleto C13 do listener). Ordem de merge: #60 → #65 →
  #69 → este PR. O #69 mexe no mesmo trecho do listener (`barreiraDaSituacao`): quem entrar depois resolve; cada um
  põe o seu método no espaço reservado.
- Contrato com a b5 (19 e 20/10; spec `perfis-1.2-contas.md` C3, C4, C4b, C13 e Q4): nomes abaixo, inalterados. A b5
  empilha o bloco "Pedidos de exclusão de conta" do painel do Master sobre este PR e lê `pendentes()`. Neste PR
  entram as duas chamadas do lado do Master (`marcarReativada` no `ReativarContaUseCase`, que deixa de recusar conta
  ativa com pedido pendente; `marcarExcluida` no `ExcluirContaPeloMasterUseCase`).
- Migration `Version20261020150000`, no fim da fila (coordenador, 20/10). Mapeamento novo `AppTenant` no
  `doctrine.yaml` (arquivo quente).
- 20/10: revisão (4 bloqueantes corrigidos) e re-revisão aprovada; suíte completa verde (10.505); visual conferido
  contra o 07. PR aberto sobre `meu-perfil-oab`; a b5 empilha o bloco do painel do Master.
- Coordenador (20/10): (d1) o link público do Push desliga com escritório suspenso, em quarentena ou suspenso a
  pedido (correção de segurança, vale para a suspensão do Master já em produção). Ao dono: (a) card para o Master
  que não é primário, (b) troca da rota antiga `escritorio_excluir` (ainda NÃO aplicada; aguarda), (d2) DJEN durante
  a suspensão a pedido. `framework.yaml` (arquivo quente): um limitador novo em
  trecho próprio.

## Decisões

Decisor imparcial (feature-review-agent, 20/10), sobre o desenho e o contrato:

- E1. Quem pede e quem desiste: qualquer primário do escritório (`RegraDosPrimarios::ehPrimario`), não só o titular.
  Um pedido pendente por escritório (índice único parcial). A trilha guarda quem pediu e quem desistiu.
- E2. Conta criada por Master (`RegraDosMasters::recusaParaExcluir`): sem card, e o servidor recusa. Master sem
  vínculo de primário: sem card (o desenho mostra ao Master; vai ao dono). O Master não passa pela barreira (b); sem
  escritório na sessão, ele já decide pelo painel.
- E3. "Suspensa a pedido" é estado próprio do pedido; o Tenant segue `isActive=true` (contrato C13). Regra única,
  `BloqueioPorPedidoDeExclusao::bloqueia(UserTenant)` = pedido pendente E não primário. Usada:
  - na barreira (b) do `TenantContextValidatorListener`, entre (a) e (c). Equipe: limpa o escritório da sessão e manda
    para a seleção com "Este escritório está suspenso a pedido do usuário primário e aguarda a decisão do BlueJus.".
    Primário: não limpa; fora das rotas do Meu Perfil (`/perfil…`), de desistir e de sair, vai ao Meu Perfil;
  - no `TenantContext::setCurrentTenant` (recusa o vínculo bloqueado: cobre a seleção por POST e o
    `EscritorioController`);
  - nos que escolhem ou listam escritórios: login (`UserAuthenticator`), seleção automática
    (`TenantSelecaoController`), seletor da barra (`TenantContextExtension`) e `ListarMinhasContasUseCase`. Sem
    isso, a seleção escolhe de novo o escritório ainda ativo e cai em laço. A seleção mostra o escritório bloqueado
    com a mensagem acima (como as contas fora do ar da C9). O `findActiveByUser` em si não muda (Trabalho Externo).
- E4. Código da etapa 2: tabela `codigo_exclusao_conta` (tenant, usuário, `token_hash` = HMAC de
  `tenantId:userId:codigo` com `kernel.secret`, `expira_em` 15 min, `tentativas`, `usado_em`). Vale só para o par
  usuário + escritório. Cada "Continuar" da etapa 1 emite um código novo e invalida os anteriores do par (o desenho
  não tem "Reenviar"). 5 erros queimam o código (UPDATE atômico antes de comparar). Código e EXCLUIR conferidos no
  servidor; o consumo é atômico e na mesma transação que cria o pedido. Limitador `exclusao_conta_codigo` por
  usuário (3 envios a cada 15 min). Falha no envio do e-mail: não passa para a etapa 2. A faixa "Protótipo: o código
  é …" não entra.
- E5. Aviso ao Master: no sino de cada Master ativo, título "Pedido de exclusão de conta", texto "{escritório} pediu
  exclusão. Motivo: {motivo}", gravado no escritório da conta do próprio Master (nunca no do cliente). Sem e-mail.
  Falha no aviso vai ao log e não derruba o pedido.
- E6. Rota antiga `escritorio_excluir` (exclusão imediata pelo admin do escritório): proposta passar a ser só do
  Master, com a "Zona de perigo" levando o primário ao card do Meu Perfil. AGUARDA O DONO (tira uma função do admin);
  por ora a rota fica como está. Já entrou: o `ExcluirEscritorioUseCase` marca o pedido pendente como "excluida".
- E7. Os jobs (DJEN, fotografar, worker) seguem: o escritório continua ativo e desistir devolve tudo inteiro. Ficam
  como pendência (fora deste PR): o link público do Push e os e-mails do DJEN para a equipe bloqueada.
- E8. Purga: `pedido_exclusao_conta` e `codigo_exclusao_conta` entram em `ORDEM_DELECAO` antes de `tenant`; o
  histórico fica no `audit_log` (pedido `Auditavel`; código não). Códigos vencidos saem ao emitir um novo e na purga.
- E9. Master suspende (C2) com pedido pendente: permitido, o pedido continua pendente; a barreira (a) vem antes da
  (b), então o primário também perde o acesso até o Master reativar ou excluir. Desistir recusa conta fora do ar.

- E10 (revisão, 20/10). O pedido pendente é lido por consulta NATIVA (`pendenteDoEscritorio`): o filtro de escritório
  da sessão do Master escondia o pedido do cliente no painel (Reativar recusava, Excluir deixava o pedido
  pendente). A notificação ao Master é gravada no escopo de auditoria da conta do Master. Chamadas de fundo (sem
  `Accept: text/html` ou com XHR) do primário suspenso recebem 403 curto, sem flash. Os motivos do modal são botões
  `aria-pressed`. Pendências registradas: rotas com o escritório na URL (`/tenant/{id}`…) não passam pela barreira
  (b), lacuna que já existe na (a) (vai ao dono junto com a E6); a futura barreira (d) do PR 4 precisa valer também
  no ramo do primário suspenso.

## Textos

Do desenho 07 (L111-161, L258-269), exatos, exceto onde o dono decidiu (sem "definitiva" nem "irreversível"):

- Card (último da página, depois de "Segurança", só para o primário): "Excluir conta do escritório" (`bi-trash3`).
  Sem pedido: "Suspende a conta para toda a equipe e envia o pedido ao Master do BlueJus, que decide a exclusão.
  Exclusivo do usuário primário." e o botão "Excluir conta". Com pedido: "Pedido feito em {dd/mm/aaaa} · motivo:
  {motivo}. A equipe está sem acesso até o Master concluir.", o selo "Suspensa · aguardando o Master"
  (`bi-hourglass-split`) e o botão "Desistir e reativar" (confirmação "Desistir da exclusão e reativar a conta para
  a equipe?"; aviso "Conta reativada. Que bom que você ficou!").
- Modal "Excluir conta · etapa {1|2} de 2". Etapa 1: "Conte em poucas palavras o motivo. Isso nos ajuda a melhorar.",
  os motivos "Não uso mais", "Achei caro", "Faltou um recurso", "Mudei de sistema", "Encerrando o escritório",
  "Outro motivo", o campo "Algo a mais? (opcional)" e o aviso (texto do dono, 19/10): "A conta fica suspensa até o
  BlueJus analisar o pedido. Depois da exclusão, os dados ficam guardados por 365 dias e o suporte do BlueJus pode
  recuperá-la nesse prazo; depois disso, tudo é apagado. Os usuários não são apagados." Etapa 2: "Enviamos um código
  de 6 dígitos para {e-mail}. Digite o código e a palavra EXCLUIR para confirmar.", campos "CÓDIGO" e "DIGITE
  EXCLUIR". Rodapé: "Manter conta" e "Continuar" / "Suspender e pedir exclusão". Erros: "Escolha um motivo.",
  "Código inválido.", "Digite EXCLUIR para confirmar." Aviso final: "Conta suspensa. O pedido de exclusão foi enviado
  ao Master."
- E-mail do código: assunto "Código para excluir a conta", válido por 15 minutos.

## Revisão cruzada da b5 (20/10/2026, corrigida no PR)

- **Índice de um pendente por conta mapeado.** `uniq_pedido_exclusao_conta_pendente` entra no mapeamento da
  entidade (`UniqueConstraint` com `where`, como o `InscricaoOab`). Sem ele, o `schema:update --dump-sql` devolvia
  `DROP INDEX` e o próximo `make:migration` apagaria a única defesa contra dois pedidos pendentes. Agora o dump
  não toca a tabela.
- **Rota antiga `escritorio_excluir` com pedido pendente: só o Master decide** (decisão do coordenador,
  independente da E6). Quem não é Master é recusado (403, flash na tela da conta), mesmo com outro escritório
  na sessão.
- **Decisão concorrente.** Desistência do primário e decisão do Master ao mesmo tempo:
  `PedidoDeExclusaoDeContaRepository::gravarDecisaoSePendente` faz `UPDATE ... WHERE estado = 'pendente'` na
  mesma transação do flush da conta. Quem chega depois recebe **409** ("Este pedido de exclusão já foi decidido.
  Recarregue a página.") e não grava nada.
- **Auditoria da rota antiga** na conta excluída (`executarComoConta`), não no escritório da sessão.
- **Falha no envio do código** vai para o log (antes, o `catch` engolia a causa).
- Anotado, fora deste PR: `existePendente` em toda página (item 7).
- Acompanhamento (decisão do coordenador, 20/10): quando este PR e o #155 estiverem no master, um PR pequeno faz a
  barreira das rotas pelo escritório da URL (`BarreiraDoEscritorioInativoNaRota`, #155) tratar também a conta
  "suspensa a pedido" (Tenant ativo com pedido pendente). Hoje ela só barra o escritório inativo, e o admin com
  outro escritório na sessão ainda age pela URL sobre a conta suspensa a pedido.

## Modelo (contrato com a b5)

- `App\Tenant\Entity\PedidoDeExclusaoDeConta` (`pedido_exclusao_conta`): tenant, pedidoPor (User), motivo ("{motivo}"
  ou "{motivo} · {observação}"), criadoEm, estado, decididoPor (User, nulo), decididoEm (nulo). `Auditavel`.
  Transições `desistir(User)`, `marcarReativada(User)`, `marcarExcluida(User)`, todas só a partir de "pendente".
- `App\Tenant\Enum\EstadoDoPedidoDeExclusao`: pendente | desistida | reativada | excluida.
- `App\Tenant\Repository\PedidoDeExclusaoDeContaRepository`: `pendentes()` (global, para o Master) →
  `App\Tenant\DTO\PedidoDeExclusaoPendenteOutput` (tenantId, escritorio, pedidoPorNome, pedidoPorEmail, criadoEm,
  motivo); `pendenteDoEscritorio(Tenant)`.

## Testes mínimos

Unit: entidade (transições), código (HMAC, expiração, 5 erros, consumo), UseCases (só primário, conta do Master,
pedido duplicado, motivo, EXCLUIR, desistir), regra de bloqueio, Reativar/Excluir do Master com pedido.
Functional: etapas por HTTP (CSRF, limitador, e-mail enviado), barreira (b) para equipe e primário sem laço (login,
seleção, POST de seleção, seletor), desistir, Master reativa e exclui, rota antiga só do Master, purga e coberturas
(TenantAware, Auditavel).
