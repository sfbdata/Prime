# Push: sub-aba "Acervo por jurisdição" (desenho 11)

Risco BAIXO. Só leitura, sem migration, sem credencial. Terceira sub-aba de Processos da OAB, ao lado de "Processos acompanhados" e "Processos relacionados" (`?sub=acervo`).

## Desenho

- Sub-aba "Acervo por jurisdição (n)": n = quantidade de órgãos distintos.
- Layout em flex: coluna esquerda 380px (min 280, fundo #eef2f5, borda #d3dde4, rolagem, máx. 680px); direita a tabela de processos (mesmas colunas de Acompanhados).
- Topo fixo da coluna: busca "Pesquise por número de processo" (aceita só dígitos, ponto e hífen) e botão 36x36 "Atualizar" (`bi-arrow-repeat`); atualizar recarrega e mostra o aviso "Acervo atualizado.".
- Acordeão de 6 grupos, com contagem à direita, nesta ordem: "Pendentes de ciência ou de resposta" (itálico #0a6fa0, aberto por padrão), "Apenas pendentes de ciência", "Ciência dada · pendente de resposta", "Cujo prazo findou · sem resposta", "Sem prazo", "Respondidos nos últimos 10 dias". Filhos: um botão por órgão (`bi-bank`), ordem alfabética, com contagem; selecionado fundo #e5f1f7 e cor #0a6fa0; sem órgão = "Órgão não informado".
- Vazio inicial (nenhum grupo/órgão/busca): "Selecione uma jurisdição" e a tabela some. Tabela sem resultado: "Nenhum processo com esses filtros.".
- Barra de seleção e "Parar de acompanhar" iguais às de Acompanhados.

## Classificação (só processos acompanhados)

Base: a publicação mais recente do processo (não excluída).

| Grupo | Regra |
|---|---|
| Sem prazo | processo sem publicação |
| Respondidos | prazo cumprido (`prazoCumpridoEm`) |
| Prazo findou | prazo calculado vencido e sem cumprir |
| Ciência dada | publicação lida, no prazo |
| Pendente de ciência | publicação não lida, no prazo |
| Pendentes de ciência ou de resposta | união dos dois últimos |

Prazo vem de `PrazoDaPublicacaoCalculador` (dias úteis, prazo escolhido ou do teor). Publicação sem prazo definido conta como "Sem prazo".

## Desvio decidido (confirmar com o dono)

O desenho rotula "Respondidos nos últimos 10 dias", mas a regra dele não filtra por data. Adoto o rótulo: respondido só entra se `prazoCumpridoEm` for dos últimos 10 dias; respondido mais antigo sai dos grupos.

## Órgão de agrupamento

`Processo.orgaoManual` > `Processo.orgaoJulgador` (processo cadastrado) > `nomeOrgao` da última publicação > "Órgão não informado".

## Entrega

- `SituacaoDoPrazoDoProcesso` (enum de 5 casos) e `AcervoPorJurisdicaoUseCase` (monta contagens por grupo e órgão e a lista filtrada por grupo, órgão e número). Nome evita a colisão com `ClassificacaoDoProcesso`.
- `ProcessosDaOabRepository`: consulta da última publicação por processo acompanhado (DBAL, tenant explícito, exclui `excluido_em`).
- Controller: `?sub=acervo&grupo=&orgao=&q=`; rota de atualizar só recarrega; mesma permissão (módulos `djen` e `processos`).
- Template: parcial `_acervo.html.twig` com componentes do desenho (aside, acordeão, botões) e CSS `.pp-acv*`; JS `push-acervo.js` (acordeão, filtro de dígitos, aviso).

## Testes

Unit da classificação (casos de borda: sem publicação, cumprido há 9 e 11 dias, vencido, lida/não lida). Functional: sub-aba existe com contagem, vazio inicial, filtro por grupo e órgão, isolamento por tenant, filhos ordenados, busca só dígitos.
