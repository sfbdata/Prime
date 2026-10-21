# Minhas Metas — reorganização em abas por papel

**Frente:** `minhas-metas-abas` · **Risco:** BAIXO (domínio Tarefa; não toca ponto, identidade nem permissão)
**Desenho-alvo:** `docs/design/redesign-19-10-26/04 - Minhas Metas 1.0.dc.html` (decisão do dono, 21/10).
A proposta antiga de 13/10 (`docs/design/minhas-metas/*.dc.html`, 3 artboards) fica só como histórico.
A decisão de 21/10 revoga o "fica como está" do #206: onde o desenho 04 mostra, ele manda (grupos,
nomes, cores, Concluídas aberta, legenda do andamento, barra de filtro sem Status, prioridade da meta).

## O defeito que originou a frente

Uma usuária relatou que "a meta fica nas metas de quem criou, não nas do responsável". Metade se
confirmou:

- A meta **vai** para o responsável — vínculo gravado (375 de 376 em prod), notificação só para ele,
  e a consulta da tela inclui `MEMBER OF t.responsaveis`.
- A meta **também** aparece na lista de quem criou, porque
  `TarefaRepository::findByResponsavelComFiltros` filtra
  `(:usuario MEMBER OF t.responsaveis OR t.criadoPor = :usuario)`.

Medido em produção: o usuário 11 é responsável por 4 metas e a tela mostra 88 (criou 84 para outros);
o usuário 1 vê 125; o 13 vê 318. A fila de trabalho de quem delega fica poluída pelo que delegou, e
não há como separar. **Nenhum teste cobre o ramo `OR t.criadoPor`** — `TarefaRepositoryFiltrosTest`
só exercita o responsável.

## O que muda

A união vira **quatro abas**, e o usuário escolhe o papel:

| Aba | Chave | Regra |
|---|---|---|
| Sou responsável (padrão) | `responsavel` | `:usuario MEMBER OF t.responsaveis` |
| Criei | `criei` | `t.criadoPor = :usuario` |
| Em acompanhamento | `acompanhando` | `:usuario MEMBER OF t.acompanhantes` |
| Todas | `todas` | responsável **ou** criador **ou** acompanhando (o comportamento de hoje, mais o marcador) |

Aba desconhecida na query cai no padrão (`responsavel`), nunca em `todas` — degradar para a lista
mais ampla contrariaria o motivo da frente.

### Contagens

Cada aba exibe a contagem **de metas em aberto** (status != `concluida`). Concluídas ficam fora da
contagem e num grupo comum, aberto (desenho 04, P:154 e P:163), limitado aos **últimos 30 dias** por
`dataConclusao`. O corte de 30 dias fica no servidor: o desenho é omisso e o mesmo corte alimenta o
"últimos 30 dias" do andamento — hoje a tela
carrega todas as concluídas de sempre, e é isso que a faz pesar com 318 metas.

⚠️ `dataConclusao` é nula em metas concluídas antes da coluna existir. O corte usa
`dataConclusao >= :limite OR dataConclusao IS NULL AND dataAlteracao >= :limite`; sem esse fallback
some meta concluída legítima da tela.

## Agrupamento dentro da lista

**Todas as abas** (`responsavel`, `criei`, `acompanhando` e `todas`), por urgência, na ordem do
desenho 04 (P:154, LIS-07/08; decisão do dono de 21/10):

| # | Chave | Rótulo (`metas.grupos.*`) | Cor | Regra |
|---|---|---|---|---|
| 1 | `atrasadas` | Atrasadas | #a3232b | `prazo < hoje`, não concluída |
| 2 | `proximas` | Vencem em 7 dias | #c08a12 | `hoje <= prazo <= hoje+7` |
| 3 | `em_revisao` | Aguardando revisão | #0a6fa0 | `status = em_revisao` |
| 4 | `depois` | No prazo | #1e7b45 | `prazo > hoje+7` |
| 5 | `sem_prazo` | Sem prazo | #7d909d | `prazo IS NULL` |
| 6 | `concluidas` | Concluídas | #1e7b45 | `status = concluida` (últimos 30 dias) |

O desenho escreve os rótulos em caixa alta; o catálogo guarda a grafia normal e o CSS
(`.mm-grupo-nome`) põe a caixa alta. As chaves ficaram as de antes para não quebrar CSS, JS e
seletores. Nenhum grupo tem nota (sai a nota por aba da revisão) e nenhum é recolhido: Concluídas é
um grupo comum, aberto, sem seta. Grupo vazio não aparece.

Na linha, a faixa de 4px à esquerda tem a cor do grupo e o fundo é branco; só a atrasada tem fundo
#fdf3f3 (P:162). A pílula de status da linha (`metas.status.*`) segue o mesmo balde do grupo.

A aba `criei` agrupa como as outras (P:151-154: a aba só escolhe quais metas entram). Até o acerto
do desenho 04 ela agrupava por etapa da revisão.

Um item aparece em **exatamente um** grupo. A precedência da classificação não mudou: `concluida`,
depois `em_revisao` (vence a urgência do prazo), depois sem prazo, atrasada, próxima e no prazo.

## KPIs (faixa de atalhos)

Quatro cartões clicáveis. Os três primeiros filtram a aba corrente; o quarto **troca de aba**.

| KPI | Conta | Ao clicar |
|---|---|---|
| Atrasadas | metas em aberto do usuário como **responsável**, `prazo < hoje` | aba `responsavel` + `prazo=vencidas` |
| Vencem em 7 dias | idem, `hoje <= prazo <= hoje+7` | aba `responsavel` + `prazo=proximas` |
| Aguardando sua revisão | metas **criadas** pelo usuário com `status = em_revisao` | aba `criei` |
| Sem prazo | metas em aberto do usuário como **responsável**, `prazo IS NULL` | aba `responsavel` + `prazo=sem` |

Os KPIs são sempre do usuário logado e **não** acompanham o filtro de busca ativo — são um retrato
fixo, não um resumo do resultado filtrado. O rótulo do terceiro diz "criadas por você" para o usuário
não ler os quatro como se fossem do mesmo universo.

🔑 **O KPI e a faceta que ele abre têm de enxergar o MESMO universo.** Os três de prazo e as facetas
`vencidas`/`proximas`/`sem` compartilham o recorte `TarefaRepository::FORA_DA_FILA` (fora: concluída e
em revisão) e o mesmo corte por dia. Sem isso o usuário clica em "Atrasadas: 4" e recebe 7 linhas —
foi o que a primeira versão fazia, porque a faceta excluía só `concluida`. O teste
`testKpiBateComALista` compara os dois lados; é ele que trava a regressão.

## Trilho lateral (coluna direita, 356px)

- **Seu andamento** (sempre este título, em qualquer aba) — "X de Y concluídas", barra de progresso
  e a legenda de três itens do desenho 04 (P:111-117, P:177), nesta ordem: "N concluída(s)" #1e7b45,
  "N atrasada(s)" #a3232b, "N em revisão" #0a6fa0 (`metas.trilho.legenda.*`). Os números são de
  TODAS as metas da pessoa (universo da aba `todas`, concluídas dos últimos 30 dias, sem as pastas
  do cadeado), sem a aba aberta e sem os filtros da tela: `TarefaRepository::contarAndamento`, uma
  consulta agregada, também na recarga parcial. A fatia "em aberto" saiu (decisão do dono de 21/10,
  que revoga a de 14/10); com isso a legenda não soma Y, e não precisa.
- **Quem delegou para você** (aba `responsavel`) / **Com quem estão** (aba `criei`) — pessoas com
  contagem de abertas e de atrasadas. Teto de 5 linhas, com "e mais N pessoas".
- **Precisa de atenção** — as atrasadas de TODAS as metas da pessoa (mesmo universo do andamento),
  da que venceu primeiro, no máximo 4 (`MinhasMetasOutput::TETO_ATENCAO`), sem "ver todas" (desenho
  04, P:119-127 e P:178). O item abre a meta (TRI-09).

O trilho não aparece no modo lista.

## Em acompanhamento (marcador pessoal)

Marcação **por usuário**, invisível para os colegas: marcar não muda a meta para ninguém.

- Relação `ManyToMany` `Tarefa.acompanhantes` → tabela de junção `tarefa_acompanhamento`
  (`tarefa_id`, `user_id`, PK composta, ambos `ON DELETE CASCADE`). Mesma anatomia de
  `tarefa_responsaveis`, que já funciona — entidade própria só se algum dia precisarmos da data.
- Rota `POST /tarefas/{id}/acompanhar`, CSRF `acompanhar_tarefa_<id>`, alterna e devolve JSON
  `{acompanhando: bool}`. Passa pelo mesmo `verificarAcessoTarefa` das demais ações.
- Sem `MinhasMetas`: o botão aparece em cada linha da lista e na `tarefa_show`.

**Isolamento:** a tabela não tem `tenant_id`, como `tarefa_responsaveis` — o escopo vem da `Tarefa`,
que é `TenantAware` e passa pelo `TenantFilter`. O teste cross-tenant tem de provar isso, e provar
com o **recurso irmão** (usuário de outro tenant tentando marcar), não só com a listagem.

**Desempenho:** o estado do marcador e a contagem de comentários de TODA a lista vêm de
`carregarMarcadoresDaLista`, em duas consultas. Perguntar à entidade linha a linha
(`meta.ehAcompanhadaPor`, `meta.mensagens|length`) custa uma ida ao banco por meta, porque as
coleções são lazy e não há `EXTRA_LAZY` no projeto — com 87 linhas na tela seriam 174 consultas.

## Modo lista

Alternador cartões/lista, uma linha por meta, sem trilho. A escolha vai na query (`modo=lista`) e é
lembrada no `localStorage` — nunca no servidor: é preferência de tela, não dado do escritório. O
script grava quando `modo` vem explícito na URL e só redireciona quando ele está ausente, para não
sobrescrever a escolha que o usuário acabou de fazer; armazenamento bloqueado cai no padrão sem
quebrar a tela.

Colunas: marcador · Meta (título + pasta) · quem (delegou/responsável, conforme a aba) · Prazo ·
Status · Atualizada · ações.

## Arquitetura

```
GET /tarefas/minhas?aba=&modo=&busca=&prioridade=&prazo=
  → TarefaController::minhas
    → ListarMinhasMetasUseCase::executar(User, Tenant, AbaMetas, filtros)
      → TarefaRepository::findParaMinhasMetas(...)   (lista, já escopada pela aba)
      → TarefaRepository::contarPainelMinhasMetas(...) (os quatro KPIs)
      → TarefaRepository::contarPorAba(...)          (número em cada aba)
      → TarefaRepository::contarAndamento(...)       ("Seu andamento": X de Y e fatias, todas as metas)
      → TarefaRepository::findAtrasadasParaAtencao(...) ("Precisa de atenção": até 4 atrasadas, todas as metas)
    → MinhasMetasOutput  (grupos montados, KPIs, trilho)
```

`contarAndamento` e `findAtrasadasParaAtencao` partem do mesmo universo (`universoDoAndamento`: aba
`todas`, sem cadeado, concluídas dos últimos 30 dias, sem os filtros da tela) e rodam também na
recarga parcial; só o modo lista os dispensa. O isolamento entre escritórios vem do TenantFilter: meta
de outro escritório com o usuário como responsável não entra (`testTrilhoNaoContaMetaDeOutroEscritorio`).

**Barra de filtro (desenho 04, P:69-80, P:174):** busca, Prioridade e Prazo. O filtro Status saiu
(o desenho não o tem; decisão do dono de 21/10): `status` na URL é ignorado. O select de prazo mostra "Atrasadas", "Vencem em 7 dias"
e "Sem prazo", com os valores de sempre (`vencidas`, `proximas`, `sem`, que os KPIs usam). O botão do
motor compartilhado diz "Limpar filtros" (`data-ft-rotulo-limpar` no root), é de texto, fica na
linha dos selects e só aparece com filtro ativo; zera busca e selects, não a aba. Os chips dos filtros
ativos não aparecem nesta barra (o desenho não os tem; continuam no DOM). Tudo por CSS local em
`.mm-barra-cartao`: `filtro-tabela.js` e `filtro-tabela.css` não mudam.

- `AbaMetas` é um enum `string` em `src/Tarefa/Enum/`, com `tryFrom` + padrão — o controller nunca
  repassa string crua ao repositório.
- O agrupamento mora no **UseCase/DTO**, não no Twig: hoje o template filtra a coleção em Twig, o que
  esconde a regra de negócio na view e impede teste unitário do agrupamento.
- Toda consulta continua herdando o `TenantFilter`; nenhum método novo recebe id de usuário vindo do
  request — o usuário é sempre o logado, fixado no servidor.

## Prioridade da meta (desenho 04, P:77, P:137-144, P:153)

A faceta "Prioridade" (Normal, Alta, Urgente) filtra pela prioridade **da meta**, não mais pela da
pasta. Uma meta normal numa pasta urgente não entra em "Urgente".

- Enum `App\Tarefa\Enum\PrioridadeDaMeta` (`normal`, `alta`, `urgente`; rótulos em
  `metas.prioridade.*`), coluna `tarefa.prioridade` VARCHAR(20) NOT NULL DEFAULT 'normal'
  (migration `Version20261020149300`, só estrutura). Não reaproveita `PrioridadePasta`.
- **Meta nova herda da pasta** na criação: pasta `urgente` vira `urgente`, `prioridade` vira `alta`,
  o resto `normal`.
- **Metas antigas** recebem a mesma herança pelo comando idempotente
  `app:tarefa:herdar-prioridade-da-pasta` (`--dry-run`, `--escritorio=ID`), por escritório, que só
  toca meta ainda `normal`. Roda uma vez depois da publicação (`DEPLOY.md`).
- O valor antigo `prioridade` na URL é ignorado; o join da pasta fica só para a busca.
- O cartão não mostra mais a prioridade da pasta (o desenho 04 não mostra prioridade na linha).
- **Decisão do dono (21/10):** prioridade herdada da pasta, sem campo de escolha, até a I.A. definir.
  O desenho 04 não tem onde escolher e o 02 faz a prioridade nascer da análise da I.A., que está
  travada. A herança só vale enquanto a meta está `normal`: quem um dia escolher antes de gravar não é
  sobrescrito.

## Testes (o que precisa ficar provado)

**Repositório / UseCase**
1. Aba `responsavel` **não** traz o que o usuário criou para outra pessoa. *(o defeito relatado)*
2. Aba `criei` traz o que ele criou para outros e **não** traz o que só lhe foi atribuído.
3. Aba `criei` **traz** a meta que ele criou para si mesmo (é dele nos dois papéis).
4. Aba `acompanhando` traz só o que ele marcou; marcação de colega não vaza.
5. Aba `todas` = união das três, **sem duplicar** a auto-atribuída.
6. Aba inválida (`?aba=xxx`) cai em `responsavel`.
7. Concluída há mais de 30 dias não entra; concluída ontem entra.
8. Concluída com `dataConclusao` nula usa `dataAlteracao` como referência.
9. Cada meta cai em exatamente um grupo; `em_revisao` vence a urgência do prazo.
10. KPIs batem com a lista correspondente.

**Controller**
11. As quatro abas respondem 200 e marcam a aba certa como ativa.
12. Filtros de busca/prioridade/prazo continuam funcionando dentro de cada aba; não há faceta Status.
13. XHR devolve só o fragmento.

**Isolamento (obrigatório)**
14. Usuário do tenant B não vê meta do tenant A em nenhuma aba.
15. Usuário do tenant B recebe 403/404 ao tentar marcar acompanhamento numa meta do tenant A.
16. Cada KPI de prazo bate **exatamente** com o tamanho da lista que ele abre (item 10 medido de
    verdade, comparando os dois lados — não só os KPIs entre si).

Cada teste dos itens 1–5 é provado **reintroduzindo o defeito** (voltando o `OR` antigo) antes de
valer como verde.

## Dívida conhecida e aceita

- **O template recebe `Tarefa`, não um Output DTO**, contra o que `app/templates/CLAUDE.md` manda.
  Os dois N+1 que isso causava foram mortos por pré-carga, mas resta `meta.responsaveis` (ambos pré-existentes: a tela antiga já os lia). Um `MetaOutput` resolvido por
  DQL fecharia a conta — é uma frente própria, não um remendo desta.
- **Metas de pasta arquivada entram nos KPIs** (34 no dev). A lista sempre as trouxe; o que é novo é
  pôr um número em cima. Decisão do dono se `situacao` deve entrar no recorte.

## Fora do escopo (decisões do dono, não implementar sem pedir)

- Botão "Nova meta" fora da pasta — hoje a meta só nasce dentro de uma pasta; criar aqui exigiria um
  seletor de pasta e muda o fluxo de criação.
- Filtro por pasta na barra.
- Notificar quem acompanha quando a meta muda.
- Paginação da lista (hoje é carga única; o corte de 30 dias nas concluídas já alivia).
