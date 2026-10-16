# Comandos `/` do Chat I.A — classificação para decisão (D5)

Fonte: `COMANDOS_CHAT_IA.md` do pacote do Designer de 13/10 — 321 entradas em 50 categorias, cerca de 300
nomes distintos (`/hoje`, `/equipe`, `/parado`, `/decisao`, `/causa`, `/plano`, `/riscos`, `/briefing`,
`/capacidade`, `/rollback`, `/evolucao`, `/proximo`, `/porque`, `/executivo`, `/atencao`, `/meudia`,
`/semprocesso`, `/contexto` aparecem em duas ou três categorias com textos diferentes). No protótipo cada
entrada é `{c, g, ic, t, d, eq, m, p}`: um título, uma frase e uma **instrução para o LLM** (`p`). Nenhuma
traz regra de cálculo, fonte de dado ou critério — quem decidia a resposta era o modelo.

Isso define a regra desta frente: **comando só vira código quando a regra é verificável contra o dado que
existe.** Se não, é decisão de produto (o que o número significa) ou depende do provedor (D1).

## Classe (a) — consulta determinística sobre dado existente

Implementáveis sem IA e sem inventar regra, **depois** de a Central existir (é onde o comando aparece) — ou
antes, como relatório na tela que já mostra o dado. Cada linha tem a pergunta que falta responder.

| Comando | O que o desenho diz | Dado no BlueJus | Falta decidir |
|---|---|---|---|
| `/semprocesso` | pastas sem processo; PDF; "enviar" para a Controladoria | `pasta_processo` | quem é "a Controladoria" (D3); o PDF é o mesmo da tela? |
| `/semdistribuicao` | sem responsável ou sem início | `pasta.responsavel_id` | o que é "sem início" |
| `/paradas` · `/semandamento` · `/parados` | sem movimentação há mais de 30 dias | timeline da pasta (`PastaTimelineAssembler`), Push | **o que conta como movimentação** (documento? registro? publicação? meta?) |
| `/perdi` | o que aconteceu desde a última visita | `pasta_timeline_visita` + timeline | nada — candidato mais limpo |
| `/timeline [termo]` | timeline da pasta com busca | `MontadorDaTimelineInteligente` | nada — já existe na Pasta |
| `/prazos` | o que vence e o que fazer | metas (`tarefa`) com prazo | horizonte (7 dias? 30?) e "o que fazer" (é LLM) |
| `/semprovidencia` | publicação do Push sem meta correspondente | `publicacao_djen` × `tarefa` | **o que é "correspondente"** |
| `/divergencias` | processo sem pasta, cliente ou responsável divergente | `processo`, `pasta_processo` | regra de "divergente" |
| `/qualidadedados` | CPF/CNPJ inválido e cadastro duplicado | `cliente_pf/pj` (`SimilaridadeDeNomes` já existe) | limiar de duplicidade |
| `/distribuicao` · `/carga` | concentração de tarefas por pessoa | contagens de pastas/metas por responsável (Dashboard) | métrica (contagem pura? ponderada?) |
| `/fluxo` | recebimentos previstos 30/60/90 dias | `pasta_pagamento` | se Cobrança entra junto |
| `/financas` | recebido, vencido, a receber | `pasta_pagamento` | "concentração" e "o que falta saber" (LLM) |
| `/dicas` | ligar/desligar as dicas dos botões | — (preferência de tela) | depende de `bj-dica.js` entrar (decisão aberta na frente fidelidade) |
| `/modocrise` | só avisos críticos | preferência + notificações | o que é "crítico" |

## Classe (b) — o modelo responde sobre o contexto (dependem da D1)

A maioria dos comandos de leitura "inteligente": `/hoje`, `/agora`, `/meudia`, `/prioridades`, `/executivo`,
`/briefing`, `/radar`, `/riscos`, `/previsao`, `/causa`, `/resolva`, `/decidir`, `/missing`, `/narrativa`,
`/caso`, `/operacao`, `/negociacao` (sem o cadastro), `/revisarmsg`, `/aguardando`, `/cliente`, `/clientes`,
`/experiencia`, `/equipe`, `/atencao`, `/rendimento`, `/feedback`, `/cobrar`, `/relatorio`, `/semanal`,
`/mensal`, `/rumo`, `/roadmap`, `/em90`, `/cenario`, `/nadafazer`, `/objetivo`, `/sensibilidade`,
`/pesquisar`, `/saber`, `/provar`, `/semelhantes`, `/investigar`, `/plano`, `/orquestrar` e afins. Viram um
**fluxo por comando** na porta `ProvedorDeLinguagem` existente (contexto delimitado + `NeutralizadorDeConteudo`
+ cota + auditoria), cada um com o recorte de dado que pode ler — o recorte é a parte que precisa de
especificação, porque é ele que decide o que sai do escritório.

⚠️ Vários avaliam **pessoas** (`/rendimento`, `/desvios`, `/potencial`, `/feedback`, `/cobrar`, `/elogios`,
`/checkin`, `/cargacognitiva`, `/competencias`). Antes de qualquer código: base legal (LGPD, art. 20 —
decisão automatizada), quem pode pedir sobre quem, e se o colaborador vê o que foi dito dele.

## Classe (c) — domínio que não existe no BlueJus (especificação do Farlei)

Não há entidade, tabela nem regra para: núcleo cognitivo e governança cognitiva (`/nucleo`, `/regras`,
`/aprendizados`, `/memoria`, `/constituicao`, `/propostas`, `/registro`, `/cerebro`, `/healthcheck`,
`/skills`, `/novaskill`, `/snapshot`, `/rollback`, `/safemode`, `/runtime`), grafo de conhecimento (`/kg`,
`/porque`, `/cadeia`, `/afeta`, `/ciclos`, …), red team e break-glass (`/seguranca`, `/redteam`,
`/breakglass`), inteligência coletiva entre escritórios (`/coletivo`, `/gate`, `/benchmark` — **cruza
tenants**, o que o isolamento proíbe sem decisão expressa), negociação (`/negnova`, `/negproposta`, …),
terceiros (`/terceiros`, `/terceirocad`, …), workflows (`/workflow`, `/wfaprovar`, …), experimentos,
oportunidades, decisões registradas, compromissos, e os "testes" (`/testecognitivo`, `/maturidade`,
`/regressao`, `/atencaoteste`, `/cvteste`, …), que no protótipo testavam o próprio protótipo.

Cada grupo é um módulo de produto. Não se implementa por comando: implementa-se o módulo (entidade,
permissão, tela) e o comando vira um atalho para ele.

## Recomendação

1. Aprovar a classe (a) como primeiro lote, respondendo as perguntas da tabela — `/perdi` e `/timeline`
   não têm pergunta pendente.
2. A classe (b) entra por fluxo, um a um, depois da D1 — começando pelos que só leem a pasta aberta.
3. A classe (c) volta para especificação; o catálogo do Designer não é especificação.
