# Minhas Metas — reorganização em abas por papel

**Frente:** `minhas-metas-abas` · **Risco:** BAIXO (domínio Tarefa; não toca ponto, identidade nem permissão)
**Desenho aprovado:** <https://claude.ai/code/artifact/0e1a9bfa-3864-43fa-84b9-540ce0437257>
(fontes em `docs/design/minhas-metas/*.dc.html` — 3 artboards: aba responsável, aba criei, modo lista)

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
contagem e num bloco recolhido, limitado aos **últimos 30 dias** por `dataConclusao` — hoje a tela
carrega todas as concluídas de sempre, e é isso que a faz pesar com 318 metas.

⚠️ `dataConclusao` é nula em metas concluídas antes da coluna existir. O corte usa
`dataConclusao >= :limite OR dataConclusao IS NULL AND dataAlteracao >= :limite`; sem esse fallback
some meta concluída legítima da tela.

## Agrupamento dentro da lista

**Aba `responsavel`, `acompanhando` e `todas`** — por urgência, na ordem:

1. Atrasadas — `prazo < hoje`, não concluída
2. Próximos 7 dias — `hoje <= prazo <= hoje+7`
3. Depois — `prazo > hoje+7`
4. Sem prazo — `prazo IS NULL`
5. Enviadas para revisão — `status = em_revisao` (sai da fila de trabalho: a bola está com quem criou)
6. Concluídas — recolhido

**Aba `criei`** — por etapa da revisão:

1. Aguardando sua revisão — `status = em_revisao` (é a ação de quem criou)
2. Em andamento — `status = pendente`, ordenado por prazo (atrasadas primeiro)
3. Concluídas — recolhido

Um item aparece em **exatamente um** grupo. `em_revisao` tem precedência sobre a urgência do prazo em
todas as abas; sem essa precedência a mesma meta cairia em dois blocos.

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

- **Andamento** — barra de progresso e as quatro fatias: concluídas (30 dias), **em aberto**,
  atrasadas, em revisão. As fatias somam o total exibido; se não somarem, o número está errado.
  A segunda chama-se "em aberto", e não "no prazo", porque soma as de prazo folgado **e** as sem
  prazo nenhum — chamar de "no prazo" o que não tem prazo seria mentir no rótulo.
- **Quem delegou para você** (aba `responsavel`) / **Com quem estão** (aba `criei`) — pessoas com
  contagem de abertas e de atrasadas. Teto de 5 linhas, com "e mais N pessoas".
- **Precisa de atenção** — as atrasadas, no máximo 5, com "ver todas" quando houver mais.

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
GET /tarefas/minhas?aba=&modo=&busca=&status=&prioridade=&prazo=
  → TarefaController::minhas
    → ListarMinhasMetasUseCase::executar(User, Tenant, AbaMetas, filtros)
      → TarefaRepository::findParaMinhasMetas(...)   (lista, já escopada pela aba)
      → TarefaRepository::contarPainelMinhasMetas(...) (KPIs + fatias do trilho)
      → TarefaRepository::contarPorAba(...)          (número em cada aba)
    → MinhasMetasOutput  (grupos montados, KPIs, trilho)
```

- `AbaMetas` é um enum `string` em `src/Tarefa/Enum/`, com `tryFrom` + padrão — o controller nunca
  repassa string crua ao repositório.
- O agrupamento mora no **UseCase/DTO**, não no Twig: hoje o template filtra a coleção em Twig, o que
  esconde a regra de negócio na view e impede teste unitário do agrupamento.
- Toda consulta continua herdando o `TenantFilter`; nenhum método novo recebe id de usuário vindo do
  request — o usuário é sempre o logado, fixado no servidor.

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
12. Filtros de busca/status/prazo continuam funcionando dentro de cada aba.
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
  Os dois N+1 que isso causava foram mortos por pré-carga, mas restam `meta.pasta.prioridade` e
  `meta.responsaveis` (ambos pré-existentes: a tela antiga já os lia). Um `MetaOutput` resolvido por
  DQL fecharia a conta — é uma frente própria, não um remendo desta.
- **Metas de pasta arquivada entram nos KPIs** (34 no dev). A lista sempre as trouxe; o que é novo é
  pôr um número em cima. Decisão do dono se `situacao` deve entrar no recorte.

## Fora do escopo (decisões do dono, não implementar sem pedir)

- Botão "Nova meta" fora da pasta — hoje a meta só nasce dentro de uma pasta; criar aqui exigiria um
  seletor de pasta e muda o fluxo de criação.
- Filtro por pasta na barra.
- Notificar quem acompanha quando a meta muda.
- Paginação da lista (hoje é carga única; o corte de 30 dias nas concluídas já alivia).
