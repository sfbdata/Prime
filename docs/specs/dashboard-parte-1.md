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
- "Relatório" e o modal (PR-D, destravado): o destino do desenho (Expediente filtrado por autor do
  registro e pelas abas) listava PASTAS e o número conta REGISTROS, então a regra B12 só deixa o
  número ser clicável quando a lista conta a mesma coisa. Agora o clique no número (ou "Ver relatório de X"
  do botão direito) abre o modal do desenho (`comVer`) com a lista por REGISTRO: ver "PR-D" abaixo.
- Universo do troféu e do cartão: vínculo ativo no escritório (`UserTenant.isActive`). Usuário com
  `User.isActive = false` e vínculo ainda ativo continua no universo (não mudado aqui). Escritório em
  quarentena de exclusão não abre o painel, então não há universo a filtrar.

## Fora deste PR

Zerar (DASH-014, feito no PR3b), meta de pastas (DASH-016), pastas concluídas (DASH-013, feita no PR3), setas × ontem, a tabela
`dashboard_campeao_mes` que congelaria o "1º do mês". A lista por registro e o modal do Relatório (DASH-012) saíram no PR-D.

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
    pasta (ativo/arquivado), e o filtro novo é `concluida=1`. No Total, o desenho linka, e agora o Total
    também linka (PR-D, abaixo).
- **PR-D (feito: Total de Pastas concluídas e modal do Relatório, sem migration)**.
  - **Total de Pastas concluídas** vira link: `/expediente?painel=acervo-geral&concluida=1&responsavel=equipe`
    (`equipe:<cargo>` com cargo filtrado; `equipe:__sem__` para "sem cargo") e as datas do painel. O Total soma
    só as linhas exibidas (colaboradores ATIVOS); `PastaRepository::aplicarFiltrosPasta` aceita
    `responsavel=equipe[:cargo]` com a mesma lógica do `criado_por=equipe` (EXISTS em `UserTenant` ativo do
    escritório da consulta + cargo), então a lista conta o mesmo número (paridade em
    `ExpedienteTotalPastasConcluidasLinkTest`: equipe, cargo, sem cargo, cadeado D1, quem saiu e pasta sem
    responsável, fora dos dois lados). **Decisão do coordenador**: pasta de responsável que saiu do escritório
    fica fora do Total e do link. Sem link quando há busca (filtra as linhas), "Zerar" na coluna (o
    Expediente não aplica o corte) ou Total 0; com uma pessoa só na tabela usa o destino da pessoa. Tooltip
    "Abrir: Pastas concluídas da equipe filtrada" (ou "...da equipe do cargo X"; o dc diz "de a equipe
    filtrada"). O select de responsável do Expediente ganha uma opção `data-equipe` (fora do menu) com o rótulo
    "Equipe" / "Equipe · cargo" para o valor sobreviver à paginação e ao "Limpar".
  - **Modal "Ver relatório de X"** (dc `comVer`, L1500-1527): `GET /dashboard/relatorio/{userId}`
    (`dashboard_relatorio`, JSON, módulo `bi`; pessoa de outro escritório ou vínculo inativo = 404),
    `ObterRelatorioDeRegistrosDoColaboradorUseCase`, `RelatorioDoColaboradorOutput`. Os registros (pasta, data e
    hora) vêm agrupados por pasta, do mais novo ao mais antigo, com trecho em texto puro (sem HTML, 120
    caracteres, `textContent` no JS) e link para `/pasta/{id}#dados`. Total no cabeçalho e no rodapé = número da
    célula. **Mesmo critério da contagem**: `App\Pasta\Criterio\RegistroDePastaNoPeriodo` (escritório, lápide,
    cartão do sistema fora, cadeado D1, período por `criadaEm`, corte do Zerar) usado por
    `contarRegistrosPorAutor` (GROUP BY) e por `listarRegistrosDoAutor` (lista). Limite de 200 linhas
    (`LIMITE_DE_LINHAS`, as mais novas): o resto vira "Mostrando os N mais recentes. Os outros M também entram no
    total", e o total continua exato. Com Zerar ativo na célula o modal aplica o mesmo corte (o do Relatório da
    equipe ou da pessoa, o mais recente). **Decisão do coordenador**: a CONTAGEM inclui as três abas; o TEXTO só
    aparece onde a pessoa logada vê a aba da pasta (Financeiro: `VisibilidadeDoFinanceiroDaPasta::podeVer`; Dados e
    Detalhes: `VisibilidadeDasAbasDaPasta::ocultaPara`; decisão uma vez por pasta e aba). Onde não vê, a linha e o total
    ficam (paridade com a célula) e o trecho vira "Conteúdo de uma aba que você não pode ver". O link do grupo usa a
    âncora da aba (`#financeiro`, `#detalhes`, `#dados`) quando todos os itens são da mesma aba visível; senão `#dados`. O clique no número (botão `[data-db-relatorio-url]`) e o "Ver relatório" do menu da
    célula (dashboard-zerar.js) abrem o modal; o menu também o oferece com 0 registros (modal vazio), como o dc.
    Arquivos: `dashboard-relatorio.js/.css`, chaves em `dashboard.relatorio` (catálogo `dashboard`).
  - **Desvios do desenho**: rodapé com o total e o "e mais N" (o dc tem rodapé só para o texto de referência
    local); sem a "base de referência" do protótipo.
- **PR-E (feito: setas de tendência nas colunas)**. Regra única do desenho (`tendencia()`, dc
  L1819+; `tendCor`/`setaWrap`): delta relativo em %, limiar de 2% para "estável", "novo" quando o
  anterior é 0, cor pelo sentido (subir é bom; `sobeRuim`: subir é ruim; `neutra`: sempre cinza). O
  desenho usa % relativo em todas as colunas (nem pontos percentuais nem dias): seguido como está.
  Macro única: `_tendencia.html.twig`. Zerar (coluna com corte) e o interruptor `db-page--sem-setas`
  seguem como antes.
  - **Seta pelo período anterior** (a MESMA consulta nas datas do período anterior; só dias já
    corridos quando o período termina no futuro, `fimJaCorrido`): Total metas, Total demandas,
    Pastas criadas, Pastas concluídas, Relatório (subir é bom); Eventos na agenda (neutra).
    Vencidas e Prazos (cair é bom): foto de `data_de − 1`.
  - **SEGURADAS (DASH-010b, decisão do dono pendente)**: Metas concluídas, Taxa de conclusão e Tempo
    médio ficam SEM seta. Elas recortam as metas pela data de CRIAÇÃO e leem o status de HOJE: as
    metas do período anterior tiveram mais tempo para concluir, então o anterior sai maior e a seta
    mente (concluídas e taxa "caem", o tempo médio anterior sai maior, sem nada ter mudado). Seta que
    mente é pior que ausente. Só destrava com critério por data de CONCLUSÃO (`Tarefa.dataConclusao`
    dentro da janela, o mesmo recorte no anterior).
- **PR-F (feito: lastro da foto diária, migration `Version20261026152200`)**. A `dashboard_foto`
  (por colaborador e por dia) ganhou 3 colunas INTEIRAS ANULÁVEIS: `metas_em_revisao`,
  `pastas_urgentes` e `metas_total` (Meta global = (total − ativas) ÷ total). Só `ADD COLUMN`, tudo
  NULL, sem backfill: foto antiga fica NULL = sem lastro. O histórico de Metas ativas aproveita as fotos
  antigas reais (`metas_ativas` é NOT NULL); Demandas urgentes e Meta global só valem a partir da
  primeira foto com a coluna preenchida (Meta global: a primeira com os 7 números). Meta global com
  `metas_total = 0` fica SEM barra, de propósito (não há o que dividir). Modelo
  por PESSOA, o mesmo das setas de Vencidas/Prazos (o histórico filtrado por responsável ou cargo soma
  o universo do card; meta com mais de um responsável conta para cada um, como o Total da tabela).
  - **Setas de estoque**: Metas ativas e Demandas ativas (`neutra`, cinza), Em revisão (`neutra`) e
    Pastas urgentes (`sobeRuim`), na linha, no Total (pílula) e no celular. O anterior é a foto de
    `data_de − 1`; o ATUAL é o estoque de HOJE sem período (a mesma consulta da foto), e NÃO o número da
    célula, que o painel recorta por criação no período (por isso o balão pode mostrar "antes 10, agora
    4" com 2 na célula). Sem foto da pessoa, coluna NULL (foto de antes do PR-F), sem período ou com pasta
    oculta pelo cadeado (a foto é do escritório inteiro, comparar revelaria a diferença), a seta fica
    oculta e o espaço reservado. Em revisão e Pastas urgentes só consultam o estoque se a coluna está ligada.
  - **Histórico dos cards (dc L1354-1391)**: Metas ativas (`estoque`, sem melhor: stat "Pico"),
    Demandas urgentes (`estoque`, menos é melhor, "Melhor (menor)") e Meta global (`pct`, "p.p.").
    O retrato de um mês é a soma da última foto DE CADA PESSOA no mês que tem a coluna preenchida; mês sem foto é barra
    vazia com o título "sem histórico" (nunca zero); o ano é a média dos meses com foto; a comparação com
    o ano anterior usa só os meses com foto nos dois anos (a série nasce no meio do ano). Sem nenhuma foto
    no período, ou com pasta oculta pelo cadeado, a janela fica indisponível com a nota.
  - A purga do escritório já apaga `dashboard_foto` (`PurgarEscritorioUseCase::ORDEM_DELECAO`,
    guardada por `PurgaCoberturaSchemaTest`).
- **Contrato com o bluejus-bd**: o comando `app:dashboard:fotografar` é idempotente (`ON CONFLICT DO
  UPDATE` por tenant, pessoa e dia), `--data` tem padrão hoje, nenhuma opção é obrigatória, roda 1x
  por dia. Desde o PR-F cada foto traz 7 números por pessoa (metas ativas, demandas ativas, vencidas,
  prazos próximos, metas em revisão, pastas urgentes, total de metas); rodar de novo o mesmo dia regrava
  todos. A `--data` no passado só repara uma noite perdida (o status é o de agora). Nada é preenchido
  para trás: o histórico dos cards começa na primeira foto com os 7 números.

- **PR-G (feito: Inteligência, selo/gauge e botões por alerta; sem migration)**. Porte do desenho 01 VS 25.10.26 no painel BlueJus Intelligence.
  - **Ritmo da meta** (`RitmoDaMeta`, `EstadoDoRitmo`): selo (4 estados do dc), gauge, Ritmo atual e Ritmo necessário, em
    DIAS ÚTEIS, sobre a meta de pastas do mês (teto 9999). O dc calcula o objetivo a partir de períodos anteriores, que o
    sistema não guarda; por isso o objetivo é a meta de pastas. O bloco só existe com meta definida + mês inteiro + sem
    responsável nem cargo (`visivel`) + SEM pasta oculta pelo cadeado (a meta é do escritório inteiro e o realizado sairia
    sem as ocultas: o selo mentiria). Sem isso, o bloco some.
  - **Marcas dos alertas** (Resolvido, Ignorar, Aplicar, Não se aplica): pessoais, em `preferencia_usuario`
    (`dashboard.ia_alertas`, mapa por id de alerta, fora do catálogo do menu ⋮ para o "Restaurar padrão" não apagar).
    `POST /dashboard/inteligencia/alerta`; tocar de novo desfaz; persiste até desfazer. O UseCase lê e regrava o mapa
    inteiro (o último a gravar vence); gravar uma chave por alerta fica como melhoria se virar problema.
  - **Pendências**: "Criar tarefa" (não há tela de tarefa avulsa para receber o título); objetivo por períodos anteriores
    (precisa guardar o histórico de concluídas por período); plano "Aumentar o nível" (feito no PR-K).

- **PR-H (feito: "Simular dia" do rodapé da Inteligência; sem migration)**. Decisão do dono: implementar como o desenho 01 (dc L1341-1347,
  L2572, L2582-2638): faixa "Teste · simular dia" no rodapé, controle de 1 ao último dia do período, número do dia à direita,
  "dia X de Y" e o resto do painel recalculados; abrir o painel zera a simulação.
  - **Contrato**: `?dia_simulado=Y-m-d` na querystring do `GET /dashboard` (e no XHR). `SimulacaoDeDia` valida: data estrita, dentro do
    período filtrado, até o último dia do mês corrente, diferente de hoje; qualquer outra coisa é ignorada (sem erro, sem aviso). Só
    entra no cálculo da Inteligência (`MontarLeituraDoDashboard`: `TempoDoPeriodo` e `MontarMetaDePastasDoMes::comDiaSimulado` para os dias
    úteis do `RitmoDaMeta`). O UseCase, os filtros, o "hoje" global e o banco não sabem dela: nada é gravado.
  - **Diferença do desenho**: o desenho escala o realizado pelo dia (`k = dia / passados`), dado inventado. Aqui os números realizados são os
    de hoje (o aviso diz); só o calendário muda. Arrastar para trás exagera o ritmo atual e para a frente o reduz, de propósito: é teste.
  - **Cadeado**: com pasta oculta o selo continua fora (a regra do PR-G vale simulando); a simulação não expõe número novo.
  - **Tela**: o controle fica fora do `.db-ia-corpo`; o `dashboard-inteligencia.js` (250 ms depois de parar) pede o fragmento com
    `dia_simulado` e troca só o corpo; o dia de hoje não manda o parâmetro (desfaz); fechar o painel desfaz. Trocar um filtro também desfaz.
  - **Fora**: os cartões do Modo avançado (`MotorAvancado`) seguem o `TempoDoPeriodo` simulado, mas sem escala de realizado.

- **PR-K (feito: "Aumentar o nível" do painel Inteligência; sem migration)**. Decisão do dono: implementar como o desenho 01 (dc L1284-1325,
  L2668-2692; `planoCrescimento()` em bluejus-intelligence.js L152-184). Só o primário/Master vê (`podeMeta`), sem pessoa selecionada.
  - **Base real**: a meta de pastas do mês (`RitmoDaMeta`). O desenho parte de um "objetivo" de períodos anteriores que o sistema não
    guarda. Some quando não há `RitmoDaMeta` (sem meta, fora do mês inteiro, com responsável/cargo, com pasta oculta pelo cadeado),
    como o selo do PR-G; não há estado de "sem histórico" porque o bloco não aparece.
  - **Fórmulas** (`MontarPlanosDeCrescimento`, dias úteis): nova meta = `min(9999, ceil(meta x (1 + nível)))`, nível 0/10/20/35% (`NivelDeCrescimento`);
    faltam = `max(0, nova - criadas no mês)`; dias = `max(1, dias úteis que restam)`; ritmo = faltam / dias; teto = `max(ritmo atual x 1,25 ; 0,01)`
    (o `melhorRitmo` do desenho é atual x 1,15, então o teto é o mesmo); esforço = ritmo / teto; viabilidade: até 0,8 Viável, até 1 Desafiador, até 1,25 Arriscado,
    acima Fora da capacidade; sem ritmo atual (nenhuma pasta criada) e com pasta por criar, "Sem base". Meta por colaborador: faltam dividido na proporção
    das pastas que cada pessoa já criou no mês (capacidade real, no lugar da fórmula do desenho sobre metas), pelo maior resto, soma = faltam, até 6 pessoas;
    sem ninguém com pasta criada, a lista não aparece. Marcos: um por semana útil (5 dias), alvo = criadas + ritmo x dias, último = "Fim" = nova meta.
  - **Alavancas**: metas vencidas, prazos próximos, concentrar nas 2 pessoas que mais criaram, nível abaixo se Arriscado/Fora. O desenho calcula "zerar as vencidas cobre X% do que
    falta": metas vencidas e pastas por criar são universos diferentes, então a razão não existe aqui.
  - **Aplicar**: o POST de sempre da meta (`dashboard_meta_pastas_definir`, `DefinirMetaDePastasDoMesUseCase`: primário, 1..9999, CSRF, módulo bi), sem endpoint novo.
    Manter não tem botão (gravaria a mesma meta). Depois de gravar, o JS recarrega o fragmento (cards e painel) e reabre o painel.
  - **Textos**: "A IA monta" virou "Por regras fixas, o painel monta"; "conclusões" virou "pastas". Os 4 níveis chegam prontos; o JS só alterna o painel visível.
  - **Fora**: a escolha do nível não é guardada (abre sempre em +10%, como o dc `iaNivel: 1`).

- **PR-J (feito: Perguntas guiadas como pílulas + caixa única; sem migration)**. dc L1004-1018, L2561-2562: pílulas lado a lado
  (30px, 12px/600, canto 4px; selecionada fundo azul e texto branco, `aria-pressed`), uma caixa de resposta abaixo com ícone de
  estrelas, entrada de .2s; tocar na pílula ativa fecha. As respostas são as de sempre (`MotorAvancado`/Leitura, regras fixas):
  nenhum número novo, nenhum texto de IA. Só `_inteligencia.html.twig`, CSS e `dashboard-inteligencia.js` (clique delegado).

## Estado da frente (24/10)

- #334 e #339 no master (migration 150900 com `finalizada_em`).
- Zerar (branch `dashboard-3`): revisado, re-revisado e com o master trazido; PR aberto.
- Meta de pastas do mês (PR-B, antes PR3c): feita, ver acima (hoje só o primário edita; "quem ele autorizar" ficou fora). PR4 (links de Pastas concluídas) e PR-D (Total e modal do Relatório) feitos.
