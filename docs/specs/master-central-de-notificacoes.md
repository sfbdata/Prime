# Central de Notificações do Master (desenho 05 Perfis 1.2, VS 21.10.26)

Risco: MÉDIO (dados pessoais de quem escreve ao BlueJus, e-mail de resposta, leitura de todos os escritórios).
Dono da frente: sessão b5. Migration reservada: `Version20261020147500`.

## Estado da frente

- 21/10: spec escrita. Implementação delegada; revisão e re-revisão antes do PR.
- 21/10: implementada na branch `master-notificacoes` (commit local, sem push), aguardando revisão.
  - Código: entidades `App\Master\Entity\CentralMasterEstado` e `CentralMasterResposta`, migration
    `Version20261020147500`; leitores em `App\Master\Notificacoes\` (`LeitorDeSugestoes` pela `VisaoDoMaster`,
    `LeitorDeSuporte`, `LeitorDeAlertasDeIndicacao`, registrados pela tag `app.master.origem_da_central`); UseCases
    `ListarCentralDoMaster`, `MarcarNaCentral`, `AbrirMensagemDaCentral`, `ResponderNaCentral`; controller
    `CentralDeNotificacoesController` (`/master/notificacoes`: GET lista; POST `marcar`, `{origem}/{id}/abrir`,
    `{origem}/{id}/responder`; token `master_notificacoes` no header `X-CSRF-Token`). Tela: `master/_notificacoes.html.twig`,
    `public/js/master/notificacoes.js` e o bloco "Central de Notificações" no fim de `public/css/master.css`.
  - Decisões da implementação: abrir é POST (marca lida); o histórico diz "Você respondeu" para a resposta do próprio
    Master e "<nome> respondeu" para a de outro; responder uma sugestão já atendida reabre e atende de novo pela API da
    `Sugestao` (`alternarAtendimento`), sem mudar a entidade; o aviso ao autor e o envio rodam no escopo de auditoria
    da conta da sugestão (`executarComoConta`); setas de página só com mais de 50 (o desenho mostra só a primeira
    página); "1-50 de N" com hífen e "assunto · texto" (sem travessão); o placeholder sem e-mail diz "Ela chega como
    aviso na Central de quem enviou." (o desenho fala em Chat I.A, mas a D6 manda pela Central).
  - Limite conhecido: cada leitor lê no máximo 5.000 mensagens (as mais antigas ficam de fora), e busca e paginação
    são em memória.
- 21/10, correções da revisão (F5):
  - `TiposDeAvisoDaCentral` tem o tipo `central_master.resposta` ("Resposta do BlueJus"), antes do comentário do
    suporte.
  - A resposta dada pela página do Contribua conta como "Respondida" (`MensagemDaCentral::respondidaNaOrigem`) e
    aparece no histórico ao abrir ("<nome> respondeu pela página do Contribua"). Antes de a Central gravar uma
    resposta nova por cima na `Sugestao`, a antiga entra em `central_master_resposta` (canal `contribua`, com quem e
    quando respondeu, `destino` nulo): a única cópia não se perde.
  - Alerta de disparo alto (decisão do dono no #185, ressalva 2, opção b): aparece na Central como a origem
    `alerta_indicacao`. O texto gravado ("Fulano enviou 35 convites em 24h") é mostrado no padrão "Fulano enviou 35
    indicações nas últimas 24 horas", só na leitura; assunto "Disparo alto de indicações". A janela do alerta tem o
    link "Abrir Indicações" (`/master#aba=painel&sec=ind`), onde o Master pausa a pessoa.
  - Estado: a criação da linha é idempotente (`INSERT … ON CONFLICT DO NOTHING` e leitura), sem erro de chave única
    na corrida. O e-mail do destino é aparado e vai em minúsculas antes de validar. Na janela aberta, a estrela só
    muda depois que o servidor aceita.
- Para o dono: retenção de `mensagem_suporte` (nome, e-mail, IP e navegador ficam para sempre e não entram em
  nenhuma purga); provedor de e-mail (`MAILER_DSN` está `null://null`, então a resposta por e-mail fica na fila
  sem sair); duas respostas simultâneas à mesma mensagem geram dois e-mails (fica assim: o histórico compartilhado
  mostra as duas); estado e respostas de suporte e alerta não têm escritório e ficam órfãos quando a origem some
  (seguem a decisão de retenção acima).

## 1. Fontes

- Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, seção `notif` em `SECS`
  (L565), template L309-322, lógica `nfVals` L724-749. `nfVals` já estava inteiro no trecho lido; o trecho que
  faltava (desenho conferido inteiro em 21/10/2026, 286394 bytes) não tem nada da Central de Notificações.
- No protótipo a caixa junta `bj-contribua.sugs`, `bj-suporte-fila`, `bj-alertas-master` e `bj-pedidos-plano`, e
  guarda o estado em `bj-notif-master` (`lido`, `est`, `arq`, `resp`).

## 2. O que a tela faz (desenho)

- Abas: Recebidas, Não lidas, Com estrela, Respondidas, Arquivadas. Só Recebidas e Não lidas têm contador.
- Barra: selecionar todos, atualizar, Marcar como lida, Não lida, Arquivar ou Desarquivar, contador da seleção,
  busca "Buscar mensagem", "1–50 de N".
- Linha: caixa de seleção, estrela, remetente (negrito se não lida), selo do tipo, "assunto · texto", ícone de
  respondida, data (hora se hoje, senão "dd mmm").
- Abrir marca como lida e mostra a mensagem: Voltar, Arquivar (e fecha), Estrela, data, assunto, selo, iniciais,
  "de · escritório", "para Master do Sistema", texto e o histórico "Você respondeu".
- Responder: não existe para alerta. "Enviar resposta" grava a resposta; com e-mail, manda e-mail; sem e-mail,
  avisa pela Central do sistema.

## 3. Origens nesta frente

| Origem | Tabela | Tipo na tela | Responder |
|---|---|---|---|
| `sugestao` | `contribua_sugestao` (tipo `sug`, `prob`, `falta`: "Sugestão", "Algo atrapalha", "O que falta") | sim | e-mail do autor (User), senão aviso pela Central no escritório da sugestão |
| `suporte` | `mensagem_suporte` (origem `login`: "Suporte"; qualquer outra: "Fale conosco") | sim | e-mail digitado na mensagem |
| `alerta_indicacao` | `indicacao_alerta` ("Alerta", de "Sistema BlueJus") | não | — |

Fora desta frente (registradas para quando existirem): pedido de espaço da lista de espera (não tem tabela; depende
do #95 ou de tabela própria), pedido de exclusão de conta (#125/#131, ainda abertos) e o alerta de consumo (só no
`audit_log`). A arquitetura de leitores por origem deixa entrar cada um depois com um leitor novo.

## 4. Decisões

- D1. As mensagens continuam nas tabelas de origem. A Central guarda só o estado e as respostas, em duas tabelas:
  - `central_master_estado`: uma linha por (Master, origem, id_origem), criada na primeira marcação. Sem linha =
    não lida, sem estrela, na caixa. Colunas: `id`, `master_id` (FK user, ON DELETE CASCADE), `origem`
    varchar(20), `id_origem` int, `tenant_id` int nulo (escritório da origem; nulo para suporte e alerta),
    `lida_em`, `estrela` bool, `arquivada_em`. UNIQUE (`master_id`, `origem`, `id_origem`); índice
    (`origem`, `id_origem`).
  - `central_master_resposta`: o histórico de respostas, COMPARTILHADO entre os Masters (nenhum responde duas vezes
    sem saber). Colunas: `id`, `origem`, `id_origem`, `tenant_id` nulo, `texto` text, `por_id` (User, SET NULL),
    `por_nome`, `em`, `canal` (`email` | `central`), `envio_id` nulo (id em `comercial_envio`), `destino` (e-mail
    usado ou id do usuário avisado).
  - Lida, estrela e arquivada são POR Master; "Respondida" vale para todos (existe resposta na origem).
- D2. Só o Master (`ROLE_SUPER_ADMIN`) acessa: `#[IsGranted('ROLE_SUPER_ADMIN')]` na classe do controller, CSRF em
  todo POST, toda consulta de estado filtrada por `master_id = eu`. O destinatário de suporte não Master continua só
  com `/suporte/mensagens`, como hoje.
- D3. As duas tabelas NÃO são TenantAware (o Master lê todos os escritórios): entram em `FORA_DO_ESCOPO` do
  `TenantAwareCoberturaTest`, com justificativa. Como têm `tenant_id`, entram na `ORDEM_DELECAO` do
  `PurgarEscritorioUseCase` (`tenant_id = :tenant`), para não ficarem órfãs.
- D4. Leitura: um leitor por origem devolve o mesmo DTO (`origem`, `idOrigem`, `tipo`, `rotuloDoTipo`, `deNome`,
  `deEmail`, `escritorio`, `tenantId`, `assunto`, `texto`, `em`, `podeResponder`). Tabelas TenantAware de outros
  escritórios são lidas pelo caminho que o Contribua já usa para o Master (`VisaoDoMaster`), sem desligar filtro à
  mão fora dele. Origem que sumiu (purgada) some da lista sem erro.
- D5. Resposta por e-mail: pelo `Carteiro` (`CarteiroInterface`), `TipoDeEnvio::Transacional`, origem
  `central_master:resposta`, assunto "Re: <assunto>", nome do remetente "BlueJus", com o rodapé de motivo
  (`motivoDoEnvio`: "Você escreveu ao BlueJus"). O e-mail do suporte NÃO é confirmado: o texto da resposta não cita
  a mensagem original. O texto da resposta fica em `central_master_resposta` (o conteúdo do envio é apagado depois
  de enviado).
- D6. Resposta sem e-mail (sugestão de autor sem e-mail): `NotificacaoService` para o autor, no escritório da
  sugestão. Ao responder uma sugestão, atualizar também `Sugestao` (`resposta`, `atendida`, `respondida_por`,
  `respondida_em`), como o desenho faz (`g.resp`), para a página do Contribua ficar coerente.
- D7. Alerta não aceita resposta (o servidor recusa).
- D8. Ao abrir uma mensagem na Central, não mexer nas `Notificacao` do sino (sem vínculo confiável); fica como
  melhoria.
- D9. Paginação: 50 por página, como o desenho ("1–50 de N"); busca em nome, e-mail, assunto e texto.

## 5. Testes obrigatórios

- Master vê as três origens de todos os escritórios; não Master recebe 403 em todas as rotas; CSRF errado, 403.
- Estado por Master: o Master A marca lida, estrela e arquivada sem afetar o Master B; Respondida aparece para os
  dois.
- Responder: sugestão com e-mail (envio no Carteiro, Transacional, origem certa, sem o texto original), sugestão
  sem e-mail (Notificacao ao autor), suporte (e-mail digitado), alerta recusado; `Sugestao` atualizada.
- Purga de escritório apaga estado e respostas do escritório; origem apagada some da lista sem erro.
- TenantAwareCoberturaTest com as duas tabelas no `FORA_DO_ESCOPO`.
- Tela: a seção existe no painel (filho direto), as abas, a barra e a abertura da mensagem.
