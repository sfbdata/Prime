# Dashboard, parte 1 (PR1)

Risco BAIXO (Dashboard, leitura). Desenho: `01 - Dashboard 1.2.2.dc.html` (claude-design-2026-10-05)
e o README do handoff (seções "Período padrão", "Troféu de melhor resultado", "Som do campeão",
"Botão direito no colaborador"). Itens do inventário: DASH-001, DASH-003, DASH-011, parte da DASH-019.

## Escopo deste PR

1. **Período padrão "Este mês"**: sem `data_de` e sem `data_ate` na URL, o painel abre de 1º do mês
   até hoje. "Limpar filtros" só aparece quando o filtro difere disso (outro período, datas manuais,
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
- Pastas concluídas (PR3) = "Demandas encerradas no período" do desenho, lida como "arquivadas no
  mês": desarquivar tira da conta e pasta excluída não conta.

## Padrões PROVISÓRIOS (até o dono responder)

- **Empate total** (mesmas concluídas e mesmas vencidas): **ninguém recebe o troféu** e o ranking
  divide a posição (1º, 1º, 3º). O desenho é omisso.
- "Relatório" sem link: o destino do desenho (Expediente filtrado por autor do registro e pelas
  abas Dados da pasta, Financeiro e Detalhes) não existe, e a regra B12 só deixa o número virar link
  quando a lista de destino conta a mesma coisa. Fica com a DASH-012 (lista da pessoa).
- Universo do troféu e do cartão: vínculo ativo no escritório (`UserTenant.isActive`). Usuário com
  `User.isActive = false` e vínculo ainda ativo continua no universo (não mudado aqui). Escritório em
  quarentena de exclusão não abre o painel, então não há universo a filtrar.

## Fora deste PR

Zerar (DASH-014, feito no PR3b), meta de pastas (DASH-016), pastas concluídas (DASH-013, feita no PR3), setas × ontem, a tabela
`dashboard_campeao_mes` que congelaria o "1º do mês", lista e link do Relatório (DASH-012).

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
  - `pasta.arquivada_em`: gravada pelo `Pasta::setSituacao()` ao ENTRAR em Arquivado (pílula, menu ⋮ e
    "Editar dados"; regravar Arquivado não troca a data), apagada ao sair (e no restaurar da
    lixeira). Backfill das arquivadas não excluídas: o arquivamento mais recente entre o cartão do
    Registro (`sistema = 'situacao'`, "para "Arquivado"") e o `audit_log` (update `situacao → arquivado`;
    a de criação não serve, porque sai sem o id da pasta). Vale o mais recente dos dois, e não "Registro primeiro", porque o
    "Editar dados" arquiva sem cartão, só com auditoria. Sem fonte, fica nula e a pasta não entra em
    período nenhum (a contagem sai no log da migration; no banco de desenvolvimento `saas`, 0 de 512; em produção, 0 de 555 em 23/10);
  - `dashboard_zeramento` (tenant, coluna, `user_id` nulo = todos, `contar_desde`, `por_id`,
    `criado_em`, `desfeito_em`, `desfeito_por_id`; índice `(tenant_id, coluna)`) e
    `dashboard_meta_pastas` (tenant, `mes` = dia 1º da vigência, valor, `definida_por_id`,
    `criada_em`; vale a de `mes` mais recente até o mês pedido, e no mesmo mês a última gravada).
    Entidades e repositórios em `app/src/Dashboard`; as duas entram na purga do escritório. Os
    casos de uso de zerar e de meta ficam para os PRs seguintes.
  - Coluna extra **Pastas concluídas** (chave `pastas_concluidas`, rótulo e ajuda do desenho
    `EXTRAS.pastasConcluidas`: "Pastas concluídas", "Demandas encerradas no período", soma, entre
    Pastas urgentes e Eventos na agenda): pastas com `arquivada_em` no período, por responsável,
    situação Arquivado, sem lápide e sem as ocultas pelo cadeado. Tem tendência (`alta`, subir é bom).
    Número sem link: o destino do desenho (`/expediente` com `concluida`) não filtra pela data do
    arquivamento, e a regra B12 só deixa virar link quando a lista conta a mesma coisa.
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
    concluídas, o arquivamento depois do corte; Eventos, o início do evento. Taxa e Metas concluídas
    saem de Total − Ativas com o mesmo corte.
  - **Na tela**: célula zerada sem link (o destino não conta a partir do corte) e sem seta (o
    anterior não tem corte); no Total, sem pílula. Troféu, card "Pastas criadas" e cartão do
    colaborador (posição, Comentários, Rendimento) seguem o corte; o "1º do mês" não (é histórico).
  - **Permissão**: zerar e desfazer só para o Master ou primário ativo (`RegraDosPrimarios::
    podeGerirPrimarios`), 403 no servidor. Os demais veem "Pedir para zerar": aviso na Central
    (tipo `dashboard_pedido_zerar`) a cada primário ativo e a cada pessoa com `UserTenant.controladoria`
    (ciência, "nada foi alterado"); um pedido igual por dia (o segundo volta "Você já tinha pedido
    isso hoje"). Sem Controladoria no escritório, o texto diz só "usuário primário".
  - **Multi-tenant**: escritório e autor da sessão; pessoa que não é colaboradora ativa do escritório
    dá 404; CSRF no header em todo POST.
  - **Desvios do desenho**: "Você recebe a resposta no Chat I.A." saiu do aviso de sem permissão (não
    há fluxo de resposta); "Ver relatório" navega direto em vez do toast com link; o desfazer confirma
    com `window.confirm`, como o desenho.
- **PR4 em diante**: meta de pastas.
- **Contrato com o bluejus-bd**: o comando `app:dashboard:fotografar` é idempotente, `--data` tem
  padrão hoje, nenhuma opção é obrigatória, roda 1x por dia.
