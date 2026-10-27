# Agenda 1A (desenho "09 - Agenda 1A (restaurada)")

Desenho: `docs/design/redesign-25-10-26/09 - Agenda 1A (restaurada).dc.html` (arquivo inteiro, 786 linhas, termina em
`</html>`; dependências do cabeçalho `bj-dica.js`, `bj-contas.js`, `bluejus-central.js` são da moldura, não da tela).
Substitui a tela de 18/10 (`docs/specs/agenda-vs-18-10-26.md`): o desenho mais novo manda. Risco: BAIXO na tela (só
leitura e reaproveita os endpoints de gravação existentes); a ligação com o Google (MÉDIO) não muda.
Frente: Agenda 1A. Sem migration prevista (a 153600 fica livre).

## Estado da frente (reler após compactação)

- 27/10: spec escrita. Decisão do coordenador abaixo (D1 a D6). A Agenda atual continua de pé em `/agenda/classica`
  até o Farlei aprovar a nova no smoke; a remoção da antiga é PR posterior. Nada de backend é apagado.
- PRs: 1) base (spec, rota `/agenda/classica`, seis tipos, `local` e `descricao` no JSON); 2) tela nova em `/agenda`
  (template, `agenda-1a.js`, `agenda-1a.css`); 3) remoção da antiga (só depois do OK do dono).

## 1. O que o desenho pede (resumo; o inventário completo sai do script `inventario_dc.py`)

- Cabeçalho e abas são a moldura do sistema (`base.html.twig`), não da tela.
- Barra: Hoje, setas Anterior/Próximo, título do período (24px) + resumo ("N eventos · N cancelados · N legendas ocultas"),
  busca (pílula, "Buscar evento, local ou pessoa"), segmento Mês/Semana/Lista, botão "Novo evento".
- Mês: cabeçalho de dias, grade de 5 ou 6 linhas, número do dia (hoje em círculo na cor de acento), contador quando
  há mais de 3 eventos, chips (barra de cor, hora, título, ícone de repetição e de cadeado), "+ N mais" expande o dia.
- Semana: cabeçalho com dia e número, faixa "Dia inteiro" (só se houver), corpo 07:00 a 21:00 com blocos posicionados
  (52px por hora, mínimo 30px), linha vermelha do "agora" na coluna de hoje, rolagem a partir de 640px.
- Lista: grupos por dia do mês (cabeçalho com número, "Hoje · dia", contagem), linhas com ponto, hora, título, meta,
  tag do tipo e tag do status; vazio "Nenhum evento neste período com esses filtros." com ícone `bi-calendar-x`.
- Coluna direita (322px): Detalhe do selecionado (tipo, status, título, data, horário e duração, local, recorrência,
  visibilidade, descrição, participantes com "organizador", Concluir, Editar, Cancelar); cartão Hoje (rótulo, linhas
  com "agora", vazio "Nenhum compromisso hoje.") e "A seguir" (4); Legendas (liga/desliga por tipo, contagem do
  período, "mostrar todas", engrenagem "Gerenciar legendas"); Filtros (Pessoa, Status Todos/Agendados/Concluídos,
  "Mostrar cancelados", "Limpar filtros" só com filtro ativo).
- Estados e animações: `agIn` (.34s) ao trocar de vista, `agSlide` (.3s) no detalhe, hover de chip `brightness(.97)`,
  responsivo em 980px (coluna única) e 640px (cabeçalho).
- Props do desenho: `mostrarFimDeSemana` (padrão sim), `densidade` (confortável), `riscarCancelados` (sim),
  `acento` (#0c7a9c). Entram com o valor padrão fixo no CSS; não viram configuração.

## 2. Decisões (D1 a D6 do coordenador, 27/10, aceitas sobre a recomendação da frente)

- D1. A tela nova segue o 1A. O que o desenho não mostra **sai da tela**: mini calendário, 4 cartões de prazos, lista
  dos 10 calendários, "testar lembretes", visão Dia, alternância Minha agenda/Equipe, menu de botão direito,
  "próximos feriados", feriados e aniversários na grade. **Perda declarada, a destacar no PR:** o escopo
  "Minha agenda / Equipe" (substituído pelo filtro Pessoa, que cobre "só eu" e "todo o escritório") e os cartões de
  prazos de hoje/amanhã/7 dias.
- D2. Janela de criar/editar: o desenho não a mostra (botões "Novo evento" e "Editar" sem janela). Desenho omisso:
  vale a convenção do sistema, ou seja, a janela atual (mesmos campos, POST nos mesmos endpoints).
- D3. Google: o desenho não tem. Fica um cartão pequeno no fim da coluna direita (conectar, sincronizar, desconectar,
  aviso), porque sem ele ninguém conecta nem revoga o consentimento (LGPD, art. 8º, § 5º). Desvio ⚠ declarado.
- D4. Feriados e aniversários não aparecem (o 1A não tem). Backend, push, lembretes globais e prazos das pastas
  seguem vivos. Se o dono quiser feriados como evento de dia inteiro, vira PR próprio.
- D5. Salvaguarda: `/agenda/classica` serve a tela de 18/10 até o dono aprovar a nova. O menu e a aba apontam para
  `/agenda` (nova). Nenhum código nem backend é apagado nesta frente.
- D6. Agenda 4b (apagar a agenda do Google de quem sai do escritório) **não é tocada**: pendente com o dono.

## 3. Legendas e tipo (a parte sem lastro direto)

O desenho trata "tipo" e "legenda" como a mesma coisa: seis legendas (Audiência `#0073b7`, Prazo `#00a65a`, Reunião
`#f39c12`, Urgente `#f56954`, Pessoal `#605ca8`, Lembrete `#00c0ef`). No sistema o `TipoDeCompromisso` tinha quatro
valores (meus, aud, reun, prazo) e a `LegendaCor` por escritório não tem tela de cadastro (nenhuma rota a edita).
Decisão da frente (coordenador aceitou o escopo; esta parte é consequência dele):

- As seis legendas são **fixas**, as do desenho, e cada uma é um tipo. O enum ganha `Urgente` (`urg`), `Pessoal`
  (`pess`) e `Lembrete` (`lemb`); a coluna `evento.tipo` (varchar 10) comporta, **sem migration**.
- Legenda de cada item, pelo calendário que o servidor já devolve: `aud`→Audiência, `prazo`→Prazo, `reun`→Reunião,
  `urg`→Urgente, `pess`→Pessoal, `lemb`→Lembrete, `meus`→Pessoal (o "Compromisso" genérico é o pessoal). Item de
  pasta (prazo/audiência) entra pelo mesmo calendário. Aniversário e datas do sistema não vêm (D4).
- A tela de 18/10 não oferece os três tipos novos no formulário, mas os mostra (entradas em `CAL` do JS e no catálogo).
  Os lembretes globais caem em "Compromisso" para o tipo que não conhecem (`PERF[tipo] || PERF.meus`).
- Tinta e tom das seis cores vêm das tabelas `INK` e `TINT` do desenho.
- A engrenagem "Gerenciar legendas" é desenhada como no desenho, mas **não tem destino**: o sistema não tem cadastro de
  legendas e o desenho não mostra a tela. ⚠ Fica sem ação e é declarada na conferência; cadastro de legendas é frente
  própria, se o dono quiser.
- A janela de criar/editar da tela nova oferece os seis tipos (a lista do desenho), no lugar dos quatro.

## 4. Dados (contrato com o servidor)

`GET /agenda/compromissos?de=&ate=&escopo=` (existente) devolve `itens` (uma linha por dia) e `contagens`. A tela nova
precisa de um acréscimo, retrocompatível com a tela antiga: `local` e `descricao` em cada item (o DTO só levava
`observacao`, que junta os dois).

Resto sai do que já existe: `calendario` (a legenda), `status`, `privado`, `recorrente`, `repetir`, `participantes`
(ids; os nomes vêm da `equipe` da página), `usuarioId`/`donoNome` (organizador), `h1`/`h2` (horário), `dia` (dia
inteiro), `editavel`. Concluir/Cancelar usam `POST /agenda/compromissos/{id}/status`; Excluir e Editar, a janela da D2.
A busca é do cliente (título, local, nomes dos participantes), como no desenho. O filtro Pessoa filtra por
participante ou criador. Prazos/audiências de pasta aparecem como eventos somente leitura (sem Concluir/Editar),
como hoje.

## 5. Testes

Unit: os seis tipos (cor, lembrete padrão, aceitos pelo `CompromissoInput`). Functional: `/agenda` 200 com a tela nova; `/agenda/classica` 200
com a antiga; JSON de `/agenda/compromissos` com `local` e `descricao` e sem quebrar o contrato antigo; 403 sem módulo.
Tela (arranjo por filho direto): barra, segmento, grade 7 colunas, coluna direita de 322px com Detalhe, Hoje, Legendas
e Filtros na ordem do desenho; ausência de `.card`, `.modal` do Bootstrap, mini calendário, cartões de prazos e
"testar lembretes".

## 6. Conferência ✓/✗ (PR 2, revisor imparcial lendo desenho, inventário e código)

Resultado: nenhum ✗ restante. Os 5 ✗ de CSS/JS apontados foram corrigidos (fundo da faixa "Dia inteiro" `#fcfdfe` e da
coluna de hoje `#fbfdfe`; `type="search"` virou `text`; `title` do chip saiu; piscar do Detalhe ao concluir/cancelar).

| # | Item | Resultado |
|---|---|---|
| 1 a 3 | Moldura do sistema, regras base, keyframes (renomeados `a1In`/`a1Slide`, mesmos valores) | ✓ |
| 4 | `agIn` só ao trocar de vista | ⚠ anima também em Anterior, Próximo e Hoje (o elemento do desenho persiste) |
| 5, 6 | Responsivo 980px e 640px | ✓ |
| 7 a 15 | Barra, busca, resumo, mês, chip, contador, "+N mais" | ✓ (título da semana sem travessão: "7 a 13 de setembro de 2026"; ⚠ regra do dono) |
| 16 a 20 | Semana: cabeçalho, "Dia inteiro", 07:00 a 21:00, 52px/h, linha do agora, blocos | ✓ |
| 21 | Lista: grupos, "Hoje · dia", tags, vazio com `bi-calendar-x` | ✓ |
| 22, 23 | Detalhe completo; horário "09:00 às 11:00 · 2 horas" | ✓ / ⚠ travessão trocado por "às" |
| 24 | Detalhe pré-selecionado | ⚠ só abre ao clicar (desenho tem id fixo de exemplo) |
| 25, 26 | Recorrência além da semanal; descrição com quebra de linha | ⚠ menor, dado real do sistema |
| 27 | Evento de pasta no Detalhe | ⚠ sem Editar/Cancelar; link "Abrir a Pasta {nup}" (o desenho não tem; sem ele não se chega à pasta) |
| 28 a 30 | Hoje, "A seguir", Legendas (6 fixas, contagem, mostrar todas) | ✓ |
| 31 | Engrenagem "Gerenciar legendas" | ⚠ aprovado (§3): sem ação |
| 32 | Filtros: Pessoa, Status, cancelados, Limpar | ✓ |
| 33 | Cartão Google no fim da coluna | ⚠ aprovado (D3) |
| 34 | Janela de criar/editar herdada do sistema, com 6 tipos e botão Excluir no rodapé | ⚠ aprovado (D2); Excluir não está no desenho |
| 35 a 38 | Textos só do JS, `title`/`placeholder`, cores/medidas, tema escuro | ✓ |

Sem sobra da tela antiga (mini calendário, cartões de prazos, "testar lembretes", visão Dia, Minha agenda/Equipe):
coberto por `testSemOQueODesenhoNaoTem`. Sem XSS provado: todo texto de usuário passa por `esc()`; o status é validado
contra a lista fixa.
