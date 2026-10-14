# E8 — Lista do Expediente: investigação e plano (antes de implementar)

> **Para quem:** o dono (decisões abaixo) e a sessão que implementar a E8.
> **Desenho:** `REPOSITORIO_BLUEJUS/prototipo/03 - EXPEDIENTE Lista 1.1.dc.html` (D:linha). **BlueJus:** `templates/expediente/*`,
> `templates/pasta/_tabela.html.twig`, `_filtros`, `_card`, `_resp_*`, `ExpedienteController`. Investigação read-only em 14/10
> (uma consulta agregada à PROD, sem dado pessoal, para medir marcadores e valores de enum).

## Enquadramento

- A especificação do Farlei diz da Lista (R271): "recriada só pelo print; **os ajustes ainda não foram pedidos**". O §9
  item 12 classifica como **inferência do Claude** o trilho recolhível, a alternância tabela/lista e a troca de
  responsável na linha — mudar o comportamento atual delas exige confirmar com o Farlei.
- Escopo já recortado na spec (§2): hover azul, chips de prioridade/situação, menu de botão direito **só com ações que já
  têm rota**. Cadeado/sigilo, destaque de linha, Suspenso/Cancelado e descrição/duplicar marcador já estão na lista de
  backend (§3.3).
- Frente `expediente-ux` (registrada como ativa em `docs/frentes-ativas.md`): **2 commits de 19/08 nunca integrados**
  (`f96a044b` "redesenhar workspace de pastas", `15555eb1`), ~1.150 linhas em `templates/expediente/`. Mesmo domínio.

## Conflitos relevantes (desenho × comportamento real)

| # | Conflito | Desenho | BlueJus hoje | Dado de PROD |
|---|---|---|---|---|
| C1 | Frente concorrente | — | `expediente-ux` com redesenho não integrado no mesmo arquivo | — |
| C2 | Marcadores | **um** marcador plano por pasta; "Remover marcador"; excluir = "pastas ficam sem marcador" | vários por pasta, **hierárquicos**; excluir apaga sub-marcadores em cascata | 80 marcadores (43 sub); 1.160 pastas marcadas, **140 com mais de um** |
| C3 | Cor do marcador | cor saturada usada como **texto e ícone** (10 cores) | 9 pastéis + sem cor (`EditarMarcadorUseCase`); aplicar o padrão do dc aos pastéis fica ilegível | 67 de 80 **sem cor** |
| C4 | Prioridade | NORMAL / **ALTA** / URGENTE | enum `normal / prioridade / urgente`, rótulo "Prioridade" (usado em outras telas) | `normal, prioridade, urgente` |
| C5 | Situação | Ativo / Suspenso / Cancelado / Arquivado; sem alternância por clique | só `ativo / arquivado` (+ lápide "Excluída"); **duplo clique alterna** | `ativo, arquivado` |
| C6 | Metas na linha | nº de metas **abertas** | **total**, com cor de estado (revisão/concluídas) | — |
| C7 | "Editar nome rápido" (F2, botão direito) | edita o nome do cliente na linha | só `pasta_edit`: redireciona, exige NUP e **reativa pasta arquivada** se `situacao` não vier; a coluna mostra o *identificador da pasta*, não o nome cru | — |
| C8 | Cadeado / sigilo / "Pedir acesso" | `localStorage`, "só primário" | não existe; a spec (§3.12, R204) exige servidor e permissão por funcionário — **mudança de permissão** | — |
| C9 | Destaque de linha | 6 cores em `localStorage`, várias linhas | não existe; contradiz o destaque do Farlei (§3.7: servidor, 15 cores, 1 por aba — para comentários) | — |
| C10 | Nova Pasta | cliente **obrigatório** (R229), mostra "PASTA {próximo NUP}", sugestões de cliente | cliente opcional no servidor; NUP só no servidor (prever colide, comentário R1); aviso de duplicada (só BlueJus) | — |
| C11 | Extras do BlueJus ausentes do dc | — | favoritos (estrela e ordem), lápide, chips de filtro, deep-link do Dashboard, gaveta no celular, sub-marcadores | — |

Sem backend (ficam ∅ no checklist, não são simulados): cadeado/sigilo, destaque de linha, Suspenso/Cancelado, filtro
"Sem responsável", 25/100 por página (`PER_PAGE=50`), selo "Nx" (mesmo nome e CPF), NUP previsto e sugestões de cliente,
descrição/nome único do marcador, edição rápida em JSON, Assistente de pastas e Chat I.A.

## Arquivo compartilhado

`pasta/_tabela.html.twig` (tabela, CSS da linha, cartões) também serve **`/pasta` (`pasta_index`)**; `pasta/_resp_estilo`
e `_resp_chip` servem o **cabeçalho da Pasta** (lista "só com decisão" do HANDOFF). `_filtro_barra`/`filtro-tabela.*`
**não** são usados pela Lista. Exclusivos e livres: `expediente/index.html.twig`, `_pasta_item`, `_acervo_geral`,
`_painel_marcador`, `pasta/_filtros`. A janela da E7 (`pasta-dialogos.js`) não carrega no Expediente.

## Proposta de escopo (só visual, com dado e rotas que existem) — aguarda decisões

1. Hover azul da linha (D:28-35), cabeçalho sticky, colunas e tipografia da tabela, NUP em azul.
2. Chips de prioridade nas cores do dc **com o rótulo real** ("Prioridade") e situação com ponto (2 estados), mantendo o
   duplo clique.
3. Trilho e itens de marcador no visual do dc **mantendo hierarquia e multimarcador**; cores pastéis como fundo.
4. Barra do acervo, Nova Pasta, busca, filtros, paginação sempre visível, resumo "página de N · Total", vazio do dc.
5. Menu de botão direito na linha: Abrir · Abrir em nova aba · Alterar responsável · Alterar marcador · Copiar número ·
   Copiar nome. Menu do marcador: Ver pastas · Novo · Editar · Excluir. "Editar nome rápido", Trancar e Destacar ficam ∅.
6. Janela de confirmação no lugar do `confirm()` de excluir marcador (levar o componente da E7 ao Expediente).
7. `_tabela`: escopar o visual novo ao Expediente para `/pasta` não mudar sem decisão.
