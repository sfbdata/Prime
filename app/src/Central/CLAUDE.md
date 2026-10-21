# src/Central/ — Central de Comunicação

Specs: `docs/specs/central-comunicacao-a1.md` (A1: conversas 1:1) · `docs/specs/central-comunicacao-a2.md` (A2:
@menção, responder, não lida, menus) · `docs/specs/central-comunicacao-b.md` (B: grupos e preferências por pessoa) ·
proposta das próximas fases:
`docs/specs/central-comunicacao-proposta.md`. Namespace `App\Central` · prefixo de rota `central_` · **sem
permissão de módulo** (D3: usa quem tem vínculo ativo com o escritório).

## Regras inegociáveis

1. **Acesso é posse, não permissão.** `AcessoACentral::exigirVinculoAtivo` (vínculo ativo no escritório da
   sessão) + `AcessoACentral::participacao` (ser participante ATIVO da conversa — `saiu_em IS NULL`). Não use `PermissionChecker` aqui: ele
   tem bypass de super admin e de papel de sistema, e ninguém lê conversa alheia — nem o administrador.
2. **Conversa alheia é 404**, nunca 403 (`ConversaNaoEncontradaException`): não confirma que existe.
3. **Fora da auditoria.** `Conversa`, `Participante` e `Mensagem` não são `Auditavel`: o `audit_log` grava o estado
   inteiro e é lido por quem administra o escritório. Não ponha o texto da mensagem em log, notificação nem audit.
4. **Tenant explícito em toda consulta**, além do `TenantFilter`. A barreira de vínculo da plataforma
   (redireciona para `/escritorio/selecionar`) e o próprio `TenantFilter` escondem a guarda da Central no teste HTTP —
   quem prova a guarda das consultas é `IsolamentoDosRepositoriosDaCentralTest` (filtro DESLIGADO); o
   `AcessoACentralTest` usa stubs e não exercita a consulta.
5. **Texto puro.** Twig escapa; `central.js` monta o DOM só com `textContent`/`createElement`. Nunca `innerHTML`.
6. **Polling, não tempo real (D2).** `GET /central/novidades?desde=` com cursor por id. SEM `desde` (primeira
   chamada) devolve só o cursor; `desde=0` é cursor como outro. Além das novas (`id > desde`, que contam para o teto e
   o `completo`), vêm à parte as dos últimos 30 s abaixo do cursor (janela, B) — a tela descarta as que já viu.
7. **Menção só a participante ativo.** `AcessoACentral::mencionaveis` (participante DESTA conversa, vínculo ativo,
   não o autor) + `MencoesDaCentral` (token `@[Nome](user:ID)`, rótulo reescrito pelo nome do banco, laço até só
   sobrar token canônico). O aviso (`AvisoDeMencaoNaCentral`) leva `mensagem = null`: a `Notificacao` é auditada.
8. **Menu só com ação real (D5).** Conversa: abrir, lida/não lida, fixar, silenciar, arquivar, excluir; grupo: renomear,
   informações. Aviso: abrir, resolver/reabrir, lida/não lida, excluir. Mensagem: responder, copiar, mencionar (a de
   sistema não tem menu). Item novo exige regra e rota antes.
   **"Excluir" é limpar a PRÓPRIA caixa (decisão do dono, 18/10), nunca apagar para os outros.** Aviso: apaga a
   notificação da pessoa (`ExcluirAvisoUseCase`; a meta/pasta/menção de origem não mudam). Conversa: `limpa_ate_id` na
   participação (`LimparConversaUseCase`): histórico, lista e polling não mostram dali para trás a quem limpou; o colega
   segue vendo tudo; mensagem nova traz a conversa de volta. Consulta nova de mensagem para a pessoa respeita esse corte.
   O corte espera a trava da conversa (`ConversaRepository::travar`) e é gravado no banco só para frente; o @aviso de
   menção da conversa vira lido junto. "Excluir conversa" só aparece com mensagem (vazia não tem o que limpar: desvio
   consciente do desenho L5757, aprovado pelo dono). **Comportamento conhecido:** se o colega responder citando uma
   mensagem que eu limpei, o trecho citado reaparece para mim dentro da resposta; não vaza para terceiros e fica assim
   por decisão do dono (18/10).
   Resolvida sai da Caixa 30 min depois da baixa, fixo para todos (o desenho deixava escolher; o dono fixou).
9. **Participação ativa em TODA consulta (B).** Quem saiu ou foi removido não lê, não escreve, não aparece na lista
   nem nas novidades, não é mencionável. Esquecer `p.saiuEm IS NULL` numa consulta nova reabre o grupo a ex-membro.
10. **Administrador do grupo é coluna, não papel (D3).** `Conversa::isAdministradaPor` — só ele adiciona/remove;
    participante sem permissão = 403 com `codigo: sem_permissao_no_grupo` (a tela não o confunde com sessão
    encerrada); de fora = 404. Quem sai passa a administração ao participante ativo mais antigo.
11. **Preferências são da participação de quem pede** (`fixada`, `silenciada`, `arquivada`): nunca mexer na de
    outro. Silenciada sai do total do cabeçalho (servidor e tela); arquivada volta quando EU envio.

12. **Presença e "Equipe · em aberto" (F2, `docs/specs/central-comunicacao-painel-presenca.md`).** `central_presenca`
    é uma linha por VÍNCULO (tenant × usuário), gravada só pelo batimento (`POST /central/presenca`, upsert, CSRF);
    on-line = batimento há < 60 s (`PresencaRepository::ONLINE_SEGUNDOS`). "Em aberto" de cada colega (decisão do dono:
    visível a todos) = não lidas na Central + não lidas do sino NESTE escritório; só números saem, nunca texto.
    Grave e compare sempre pelo relógio do PHP: `NOW()` do Postgres está em outro fuso. Status efetivo: Não perturbe →
    `ocupado`; sem atividade há o limite do escritório (`ConfiguracaoDePresencaRepository::minutosAusente`, escolhido
    pelo primário entre `Presenca::OPCOES_DE_AUSENCIA`; sem escolha, `Presenca::INATIVO_APOS_MINUTOS`) → `inativo`;
    senão o manual. O batimento responde
    quem está on-line (só ids, status e minutos) para o selo do cabeçalho (`templates/menu_usuario/_presenca.html.twig`,
    `presenca-cabecalho.js`; spec `docs/specs/presenca-e-ponto-no-cabecalho.md`).

13. **Encaminhar via Chat I.A (`docs/specs/encaminhar-via-chat-ia.md`).** O servidor relê o item
    (`ItensEncaminhaveisDaPasta`, id + pasta + tenant explícitos; de fora = 404 `ItemEncaminhadoNaoEncontradoException`)
    e monta título e resumo em texto puro com `@[` desarmado (`@ [`); a instrução não é desarmada (menção nela vale).
    O cadeado da pasta vale para quem RECEBE (`AcessoAPasta::podeVer`): colega que não vê some de "Para quem?" e é
    recusado (422); grupo com algum membro ativo que não vê fica travado com o motivo. A mensagem passa pelo
    `EnviarMensagemUseCase` e sempre leva a linha "Pasta NUP" (os resumos por tipo não a repetem). "Urgente" gera
    `Notificacao` `central_despacho` sem texto, com o link da conversa; a auditoria é uma linha manual
    (`action = 'despacho'`, entidade Pasta) só com tipo, item_id, conversa_id e prioridade, nunca texto. Abrir a 1:1,
    mensagem, avisos e auditoria numa transação só (`wrapInTransaction` do UseCase, flush dele). Pasta excluída = 404.
    Conversa sem ninguém que receba é recusada (`todosVeem([])` é falso). Registro do Financeiro: quem envia e quem
    recebe também passam por `VisibilidadeDoFinanceiroDaPasta::podeVer`, e a prévia (`/central/encaminhar/item`) também.
    O cartão do sistema de pró-bono ou contrato no Registro de Dados vale como registro do Financeiro
    (`ItensEncaminhaveisDaPasta::tipoDoAcesso`): o envio confere por esse tipo e o grava em `despacho_tipo`.
    **Na leitura:** a mensagem guarda a referência (`despacho_pasta_id`, `despacho_tipo`) e TODA saída do texto de
    mensagem (`MensagemOutput`, `RespostaOutput`) nasce em `LeituraDasMensagens`, que confere a pasta para quem lê
    naquela hora (memória só durante a leitura; pasta buscada com o escritório explícito). Não vê, pasta apagada ou
    excluída: "Item de pasta sem acesso", sem a referência (`despacho` nulo, `ocultoPorPermissao`). Consulta nova que
    devolva mensagem à tela passa por ela; nunca monte a saída com `Mensagem::getConteudo()` direto.

## Modelo

`central_conversa` (individual: par ordenado `usuario_menor`/`usuario_maior`, único por escritório; grupo: par nulo,
`nome` 1–60 e `administrador_id`) → `central_participante` (cursor `ultima_lida_id`, nunca recua; `marcada_nao_lida`
à parte; `saiu_em`; `fixada`/`silenciada`/`arquivada` por pessoa; `limpa_ate_id` = "excluí até aqui", nunca recua) → `central_mensagem` (despacho do Encaminhar: `despacho_pasta_id` e `despacho_tipo`, conferidos na leitura, regra 13; índices `(conversa_id, id)` e
`(tenant_id, criada_em)` da janela do polling; `resposta_a_id` só da MESMA conversa; `sistema` = linha "Ana adicionou
Bruno", montada pelo servidor). Não lida = mensagem de OUTRA pessoa, não de sistema, acima do cursor. Polling: id acima
do cursor OU criada nos últimos 30 s (a tela descarta as vistas). As três tabelas estão na purga de escritório.

## Tela

`templates/central/_janela.html.twig` é o componente isolado (D6); `/central` o monta em linha (modo `pagina`). Telas
da janela: lista (abas Caixa de entrada/Painel, filtros), conversa, novo grupo, informações do grupo (`central.js`,
`mostrarTela`).

**Lançador flutuante** (`_flutuante.html.twig` + `central-flutuante.js`/`.css`): a MESMA janela em modo `flutuante`,
incluída no `base.html.twig` por uma linha (`central_disponivel()`, nunca lança; fora da `/central`). Regras: com a
janela fechada **nada vira lido** (`marcarLida` exige `janelaAberta`), o polling cai para 30 s e só o contador anda
(lista e contatos carregam na primeira abertura); alerta (salto/som) só quando o total de não lidas do servidor sobe
(silenciadas não contam); preferências de tela no localStorage por usuário. Geometria e animações são cópia do desenho
(linhas citadas no JS/CSS); presença, urgência e "Destacar" ficaram fora por não haver dado real (D5).

**Fidelidade ao Designer (desde a Fase A, 15/10).** O código do protótipo (`bluejus-central.js` e `bj-dica.js` do
pacote de 13/10) é a referência visual e de comportamento: porte o estilo inline como classe 1:1, citando a linha, em vez
de reinterpretar. A prova é o Playwright comparativo `e2e/tests/central-fidelidade.spec.js` (protótipo × sistema no
mesmo estado; geometria, estilo computado, keyframes e tempos), não o PHPUnit, que é cego a aparência. Balão de dica:
`central-dica.js` (só nas raízes `[data-central]`, `[data-central-flutuante]`, `[data-flutuante-janela]`; o resto do
sistema segue com a dica do navegador, por decisão do dono). Demonstração da worktree: `scripts/central-demo.sh <frente>
<porta>` (sem a barra do Symfony e sem o lembrete do ponto, só naquele servidor).
