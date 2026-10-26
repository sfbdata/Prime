# Dashboard, parte 1 (PR1)

Risco BAIXO (Dashboard, leitura). Desenho: `01 - Dashboard 1.2.2.dc.html` (claude-design-2026-10-05)
e o README do handoff (seções "Período padrão", "Troféu de melhor resultado", "Som do campeão",
"Botão direito no colaborador"). Itens do inventário: DASH-001, DASH-003, DASH-011, parte da DASH-019.

## Escopo deste PR

1. **Período padrão "Este mês"**: sem `data_de` e sem `data_ate` na URL, o painel abre de 1º do mês
   até o último dia do mês (desde o PR-A visual, ver "Regras registradas"; antes ia até hoje). "Limpar filtros" só aparece quando o filtro difere disso (outro período, datas manuais,
   responsável, cargo ou busca) e volta para Este mês (não para "sem período").
2. **Coluna extra "Relatório"** (chave `relatorio`, tipo soma, no "Adicionar coluna"): registros por
   AUTOR, criados no período, somando três tabelas do escritório:
   `pasta_mensagem` (Dados da pasta, só `sistema IS NULL`), `pasta_observacao_financeira` e
   `pasta_observacao_detalhes`. Resposta conta (é um registro); edição não conta de novo (a linha é a
   mesma). Pasta excluída (lápide) e pasta oculta pelo cadeado para quem olha ficam fora.
3. **Troféu** (`bi-trophy-fill`, 11.5px, `#c49a3a`, depois do nome; tooltip do desenho "Mais
   metas concluídas no período") para quem tem **mais metas concluídas** no período = Total metas −
   Metas ativas da pessoa, a mesma régua das colunas do painel (metas criadas no período). Desempate:
   **menos metas vencidas** (hoje), como no script do desenho. Universo: todos os colaboradores ativos
   do escritório, calculado antes de busca, responsável e cargo (o troféu não muda de dono ao filtrar
   a tabela). Ninguém recebe com zero.
4. **Cartão do colaborador** (botão direito no nome, ou toque longo no celular; janela flutuante do
   desenho, classe própria `.db-colab-janela`), com tudo o que o desenho mostra:
   - cabeçalho azul com avatar, nome e "cargo · Nº de N no período", no **período do painel** (o
     mesmo do troféu);
   - **Comentários**: os registros do Relatório no mesmo período;
   - **1º do mês**: seletor de ano (do ano corrente até o primeiro ano com meta no escritório, mais
     Todos); um ano = 12 quadradinhos J…D (troféu dourado no mês ganho, cinza no perdido, só
     contorno no mês que não fechou); Todos = uma faixa por ano com a contagem. Campeão do mês pela
     regra do troféu: concluídas = metas criadas no mês e não ativas hoje; desempate menos vencidas
     (as de hoje, como no script do desenho); empate total, ninguém. Calculado na hora (uma
     consulta agregada), sem a tabela `dashboard_campeao_mes` do README (este PR não tem
     migration): um mês antigo pode mudar de dono se uma meta dele for reaberta ou concluída depois,
     e o universo são os colaboradores ativos de hoje;
   - **Rendimento**: `(concluídas da pessoa − média dos outros) ÷ média dos outros`, os outros sendo
     os demais colaboradores ativos; percentual verde acima, vermelho abaixo, frase e duas barras.
   - **Seletor Período · Mês · Trimestre · Semestre · Ano** (decisão do dono, fora do desenho): a
     alternativa ao período do painel; recorta posição, Comentários e Rendimento. Janelas em
     calendário civil até hoje (Mês = 1º do mês; Trimestre = 1º dia do trimestre civil; Semestre =
     1º/jan ou 1º/jul; Ano = 1º/jan). O recorte e o ano escolhidos valem para os próximos cartões da
     página.
   - Guarda: id que não é colaborador com vínculo ATIVO no escritório corrente dá 404 (IDOR).
5. **Som do campeão**: fanfarra do desenho (Web Audio, sol 392 Hz + dó 523 Hz com terça), no hover do
   troféu do campeão, no máximo 2 vezes por carregamento e 1 a cada 1,4 s. Desliga pela preferência
   que já existe, "Sons" do menu ⋮ (`dashboard.sons`, README: "sons (calendário e campeão)"), e pelo
   "Sons de alerta" do Meu Perfil. Sem chave nova no catálogo.

## Regras registradas (decisão do dono)

- Troféu e ranking: "mais concluídas" (total − ativas), desempate menos vencidas.
- Relatório: por autor, pela data de criação; resposta conta. Tem tendência (seta e pílula contra o
  período anterior equivalente, subir é bom): a data do registro é fato do passado.
- Setas e pílula de percentual comparam com o **período anterior equivalente** (comportamento atual,
  sem mudança neste PR). Desligar as setas em "Exibição" é o PR2 (feito, ver "Fatias seguintes").
- Zerar (PR3): qualquer coluna pode ser zerada; só o Master zera ou desfaz; quem não é Master vê
  "Pedir para zerar", que vai ao primário e à Controladoria.
- Pastas concluídas (PR3) = "Demandas encerradas no período" do desenho. Regra do dono (23/10): a
  pasta conta quando chega à etapa **Finalizada** do andamento (a última fase da trilha, judicial ou
  administrativa, com as extras de reativação), no mês em que chegou; voltar de fase ou reativar tira
  da conta; reativar e finalizar de novo conta na nova data. Arquivar deixou de ser o critério (arquivar
  leva a Finalizada, então continua contando por esse caminho). Pasta excluída não conta. Confirmado
  pelo dono (23/10): pasta **arquivada sem andamento gravado na via atual** conta como finalizada na
  data do arquivamento (backfill pelo Registro e pela auditoria; arquivar uma pasta cuja Finalizada é
  só estimada grava a data do arquivamento). Na troca de via sem andamento na via nova, a arquivada
  mantém a data que tinha e a não arquivada sai da conta.

- Períodos do segmentado (decisão do coordenador, 26/10, PR-A "visual do Painel", desenho
  `01 - Dashboard - VS 25.10.26`, L1707/L1710/L3118-3121/L3140): **Este mês** vai do dia 1º ao
  **último** dia do mês (`PeriodoDoDashboard::esteMes`, padrão sem data na URL e destino do
  "Limpar filtros"); **60 dias** e **90 dias** contam hoje incluído (hoje − 59 e hoje − 89 até
  hoje); **Este ano** segue de 1º/jan até hoje. As janelas do cartão do colaborador (Mês,
  Trimestre, Semestre, Ano) continuam terminando hoje. Consequências: o período anterior
  só conta os dias já corridos quando o período termina no futuro (visto em 26/10, 01/10 a 31/10
  compara com os 26 dias antes de 01/10; `ObterDadosDashboardUseCase::fimJaCorrido`), para a seta
  não apontar queda no começo de todo mês; o que conta por data futura (início de evento na agenda)
  entra até o fim do mês; e a Inteligência passa a ver o período padrão como em andamento ("Dia 26
  de 31", nota de qualidade com "Período em andamento") em vez de encerrado.

## Padrões PROVISÓRIOS (até o dono responder)

- **Empate total** (mesmas concluídas e mesmas vencidas): **ninguém recebe o troféu** e o ranking
  divide a posição (1º, 1º, 3º). O desenho é omisso.
- "Relatório" sem link (decidido no PR4): o destino do desenho (Expediente filtrado por autor do
  registro e pelas abas Dados da pasta, Financeiro e Detalhes) lista PASTAS, e o número conta
  REGISTROS (mensagens, observações financeiras e de detalhes, respostas incluídas). Uma pasta com 3
  registros da pessoa é 1 linha na lista, então os totais só coincidiriam por acaso; a regra B12 só
  deixa o número virar link quando a lista conta a mesma coisa. Filtro de autor/aba no Expediente
  não foi criado (código morto sem link). Fica com a DASH-012 (lista da pessoa, por registro).
- Universo do troféu e do cartão: vínculo ativo no escritório (`UserTenant.isActive`). Usuário com
  `User.isActive = false` e vínculo ainda ativo continua no universo (não mudado aqui). Escritório em
  quarentena de exclusão não abre o painel, então não há universo a filtrar.

## Fora deste PR

Zerar (DASH-014, feito no PR3b), meta de pastas (DASH-016), pastas concluídas (DASH-013, feita no PR3), setas × ontem, a tabela
`dashboard_campeao_mes` que congelaria o "1º do mês", lista por registro e link do Relatório (DASH-012).

## Fatias seguintes

- **PR2 (feito)**: a opção de desligar as setas em "Exibição". Já existia desde o menu ⋮
  (`dashboard.setas`, padrão `ligadas`, classe `db-page--sem-setas` que esconde `.db-seta`,
  `.db-tend` e `.db-total-espaco` e zera o recuo `.db-cel--seta`, como o `semSetas` do desenho,
  dc L666-671 e `tendCor`/`setaWrap`). O PR2 só travou o comportamento em teste
  (`DashboardSetasExibicaoTelaTest`): lugar, rótulo e padrão do desenho, gravação pelo endpoint,
  a carga seguinte respeitando, e as setas e pílulas continuando no HTML contra o período
  anterior (sem mudar o cálculo). Setas × ontem saiu do PR2: a comparação segue contra o período
  anterior equivalente (decisão do orquestrador).
- **PR3 (feito: migration, entidades e Pastas concluídas)**: a migration `Version20261023150900`, a
  única da frente, com:
  - `pasta.finalizada_em`: gravada pelo `PastaAndamento::gravar()` quando a linha é da via atual da
    pasta (todo caminho de fase passa por ele: pílula de fase, arquivar, contrato no Financeiro): a data
    do marco da fase atual se ela é a última da trilha, nula em qualquer outra. Na troca de via
    (`DefinirPastaAdministrativaUseCase`) é recalculada pela linha da via nova. Situação, lápide e
    restaurar da lixeira não mexem. O "Editar dados" que chega com outra situação ao caso de uso sai
    como a pílula (cartão "Situação da pasta" e, ao arquivar, Finalizada); a tela já mandava a situação
    pela pílula. Backfill em duas etapas: (a) andamento da via atual na última fase (as 10 da base mais
    as extras, mais um "Em andamento" por "Reativada" sem ele, como o `AndamentoDaPasta::fases()`):
    a data do marco, no horário local; (b) arquivada, não excluída e sem andamento na via atual: o
    arquivamento mais recente entre o cartão do Registro (`sistema = 'situacao'`, "para "Arquivado"")
    e o `audit_log` (update `situacao → arquivado`; a de criação não serve, porque sai sem o id da
    pasta). Vale o mais recente dos dois porque o "Editar dados" antigo arquivava sem cartão, só com
    auditoria. Sem fonte, fica nula (a contagem sai no log da migration);
  - `dashboard_zeramento` (tenant, coluna, `user_id` nulo = todos, `contar_desde`, `por_id`,
    `criado_em`, `desfeito_em`, `desfeito_por_id`; índice `(tenant_id, coluna)`) e
    `dashboard_meta_pastas` (tenant, `mes` = dia 1º da vigência, valor, `definida_por_id`,
    `criada_em`; vale a de `mes` mais recente até o mês pedido, e no mesmo mês a última gravada).
    Entidades e repositórios em `app/src/Dashboard`; as duas entram na purga do escritório. Os
    casos de uso de zerar e de meta ficam para os PRs seguintes.
  - Coluna extra **Pastas concluídas** (chave `pastas_concluidas`, rótulo e ajuda do desenho
    `EXTRAS.pastasConcluidas`: "Pastas concluídas", "Demandas encerradas no período", soma, entre
    Pastas urgentes e Eventos na agenda): pastas com `finalizada_em` no período, por responsável,
    qualquer situação, sem lápide e sem as ocultas pelo cadeado. Tem tendência (`alta`, subir é bom).
    Link no PR4 (ver abaixo).
- **PR3b (feito: Zerar, DASH-014)**. Sem migration (usa a `dashboard_zeramento` do PR3).
  - **Abrir**: botão direito no título da coluna (equipe toda) ou na célula da pessoa (só ela, e
    "Ver relatório" quando o número tem link), como o `comMenu`/`thMenu` do desenho. No cartão do
    colaborador, "Zerar" nos Comentários (`ctx.comZerar`); no menu ⋮, "Zerar relatório de todos"
    quando a coluna Relatório está na tabela.
  - **Nada se apaga**: zerar grava o corte (`contar_desde` = agora, `por_id`); o início efetivo de
    cada contagem é o mais tardio entre o início do período e o corte ativo da coluna (o da pessoa ou
    o da equipe, vale o mais recente). Desfazer marca `desfeito_em`/`desfeito_por_id` em todos os
    ativos da coluna e a contagem volta. Menu mostra "Contando desde dd/mm hh:mm".
  - **Colunas**: todas as numéricas da tabela, como no desenho (`thMenu` nas fixas e `thExtras` nas
    extras). Nas de estoque, o corte vale pela data de entrada: Metas ativas, Vencidas e Prazos contam
    as metas CRIADAS depois do corte; Demandas ativas, as pastas abertas depois do corte; Pastas
    concluídas, a chegada à Finalizada depois do corte; Eventos, o início do evento. Taxa e Metas concluídas
    saem de Total − Ativas, as duas com o corte da PRÓPRIA coluna (Concluídas ou Taxa; o da pessoa ou
    o da equipe, o mais recente), mínimo 0: zerar só Total metas ou só Metas ativas não muda
    Concluídas, Taxa nem troféu. O troféu e o cartão do colaborador usam o corte de Concluídas.
  - **Na tela**: célula zerada sem link (o destino não conta a partir do corte) e sem seta (o
    anterior não tem corte); no Total, sem pílula. Troféu, card "Pastas criadas" e cartão do
    colaborador (posição, Comentários, Rendimento) seguem o corte; o "1º do mês" não (é histórico).
  - **Permissão**: zerar e desfazer só para o Master ou primário ativo (`RegraDosPrimarios::
    podeGerirPrimarios`), 403 no servidor. Os demais veem "Pedir para zerar": aviso na Central
    (tipo `dashboard_pedido_zerar`) a cada primário ativo e a cada pessoa com `UserTenant.controladoria`
    (ciência, "nada foi alterado"); um pedido igual por dia (o segundo volta "Você já tinha pedido
    isso hoje"). Sem Controladoria no escritório, o texto diz só "usuário primário". Quem já pode zerar
    e chama o pedir recebe 403 (não se aplica). Card "Pastas criadas" sem "ver pastas" com a coluna zerada.
  - **Multi-tenant**: escritório e autor da sessão; pessoa que não é colaboradora ativa do escritório
    dá 404; CSRF no header em todo POST.
  - **Desvios do desenho**: "Você recebe a resposta no Chat I.A." saiu do aviso de sem permissão (não
    há fluxo de resposta); "Ver relatório" navega direto em vez do toast com link; o desfazer confirma
    com `window.confirm`, como o desenho.
- **PR-B (feito: meta de pastas do mês, DASH-016, "PR3c")**. Sem migration (usa a `dashboard_meta_pastas` do PR3).
  Desenho `01 - Dashboard - VS 25.10.26` (`nivelador`, `metaEd`, `salvarMeta`, menu `ctxC`).
  - **Modelo**: `mes` = dia 1º da vigência; vale a linha de `mes` mais recente até o mês pedido e, no mesmo mês,
    a última gravada (`DashboardMetaPastasRepository::buscarVigenteEm`). Definir grava uma linha nova no mês
    corrente (o histórico fica); **Remover** grava valor 0 no mês corrente (encerra a meta a partir dele; os
    meses anteriores seguem); valor 0 vale como "sem meta". Valor: inteiro de 1 a 9999 (o desenho aceita 5
    dígitos; o teto de 9999 é decisão do coordenador). `DefinirMetaDePastasDoMesUseCase` e
    `RemoverMetaDePastasDoMesUseCase`; rotas POST `dashboard_meta_pastas_definir` e `dashboard_meta_pastas_remover`
    (JSON, CSRF no header, escritório e autor da sessão).
  - **Quem edita**: a mesma régua do Zerar (`RegraDosPrimarios::podeGerirPrimarios`: primário ativo; o Master de
    plataforma entra porque a régua o inclui), 403 no servidor. Os demais veem a meta e o status, sem o lápis e
    sem o "Definir"; sem meta, não veem nada. O estado do editor (`data-db-meta-*`) só sai no HTML de quem edita.
  - **Na tela**: dentro do card "Pastas criadas", só no **mês inteiro, sem responsável nem cargo** (`mesLimpo` do
    desenho). Barra (cor `15,111,196` no ritmo, `192,57,47` abaixo), traço do ritmo esperado, "X de Y no mês" com
    lápis, status ("No ritmo", "Abaixo do ritmo", "Meta batida" com ícone) e dica de `title` com previsto, faltam
    e por dia. O botão direito no card, para o primário, abre o menu "Alterar/Definir meta do mês" e "Ver
    histórico" (os demais seguem abrindo o histórico direto). Editor: 280px, só dígitos, Salvar desligado até valer,
    prévia "≈ N por dia útil · M por semana", aviso "Meta herdada de <mês>" quando vem de mês anterior, "Remover
    meta" só com meta, Enter salva, Esc e clique fora fecham. Sem toast (o desenho só fecha o editor); salvar e
    remover recarregam o fragmento pelo XHR do filtro.
  - **Realizado**: o número do próprio card "Pastas criadas" (soma das linhas, antes da busca, já com o "Zerar" da
    coluna), sem regra de contagem nova. DECISÃO A CONFIRMAR: o desenho conta **pastas criadas** ("Quantas pastas
    precisam ser abertas no mês", `totalDe('pastasCriadas')`), não "Pastas concluídas"; o código segue o desenho.
  - **Ritmo em dias úteis (decisão PROVISÓRIA do coordenador, o dono pode rever)**: o desenho usa dias corridos
    (`DIA`/`TOTAL` do protótipo, fixos em 20 e 31). Aqui, `esperado = round(meta x dias úteis decorridos / dias
    úteis do mês)`, com `DiasUteisNacionais` (fim de semana, feriados nacionais, recesso de 20/12 a 20/01); "por
    semana" = por dia útil x 5 (o desenho multiplica por 7). Voltar aos dias corridos é trocar `contarDias` em
    `App\Dashboard\Meta\MontarMetaDePastasDoMes`.
  - **Montagem**: `MontarMetaDePastasDoMes` (entra no `ObterDadosDashboardUseCase` e sai em
    `DashboardOutput::$metaDePastas`); partial `dashboard/_meta_pastas.html.twig`; `dashboard-meta.css` e
    `dashboard-meta.js`. A purga do escritório já apaga `dashboard_meta_pastas` (PurgarEscritorioUseCase).
- **PR4 (feito: links de Pastas concluídas, sem migration)**. O número > 0 da pessoa abre
  `/expediente?painel=acervo-geral&concluida=1&responsavel=<id>&data_de&data_ate`, na mesma aba, com o
  tooltip "Abrir: Pastas concluídas de {nome}" (o "Ver relatório" do menu da célula passa a existir
  também para essa coluna, pois depende do link). O Expediente ganhou o filtro `concluida=1`
  ("Concluídas no período", chip e campo oculto como o `criado_por`): as datas passam a valer sobre
  `finalizada_em` (não a abertura), em qualquer situação, sem lápide, com o cadeado já aplicado pelo
  filtro de atributo (D1). O critério é UM só, `App\Pasta\Criterio\PastaFinalizadaNoPeriodo`, usado
  por `MetricasExtrasDoDashboardRepository::contarFinalizadasPorResponsavel` e por
  `PastaRepository::aplicarFiltrosPasta`; teste de paridade (célula = total da lista, para quem vê
  tudo e para quem tem pasta oculta) em `ExpedientePastasConcluidasLinkTest`.
  - **Zerar**: célula com corte ativo continua sem link (o destino não conta a partir do corte);
    o corte não viaja na URL.
  - **Desvios do desenho**: o desenho usa `status=concluida`; aqui `status` segue sendo a situação da
    pasta (ativo/arquivado), e o filtro novo é `concluida=1`. No Total, o desenho linka; aqui o Total
    segue sem link, como todos os do Total (ele soma só as linhas exibidas, filtradas por cargo ou
    pessoa, e não inclui pasta de quem saiu do escritório, então a lista não contaria o mesmo).
  - **Relatório**: sem link, pela razão acima. Só destrava com uma lista por registro (DASH-012).
- **Contrato com o bluejus-bd**: o comando `app:dashboard:fotografar` é idempotente, `--data` tem
  padrão hoje, nenhuma opção é obrigatória, roda 1x por dia.

## Estado da frente (24/10)

- #334 e #339 no master (migration 150900 com `finalizada_em`).
- Zerar (branch `dashboard-3`): revisado, re-revisado e com o master trazido; PR aberto.
- Meta de pastas do mês (PR-B, antes PR3c): feita, ver acima (hoje só o primário edita; "quem ele autorizar" ficou fora). Próximo: PR4 links do Relatório e de Pastas concluídas com o filtro do Expediente.
