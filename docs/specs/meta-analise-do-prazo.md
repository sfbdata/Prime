# Nova meta: análise do prazo (desenho 02, dc L95-172) e ajustes da análise documental

Frente `meta-prazo`, sessão f6, aberta em 19/10/2026 a pedido do coordenador (fatias de Inteligência do 02
tiradas da 4b). **Empilhada sobre o #82** (`push-painel-monitoramento`), declarado: usa o `DiasUteisNacionais`
dele como fonte única de dias úteis. Risco: prazo jurídico é risco (`trilha-b-inventario/componentes.md`).

## Estado da frente

- **Regra única de dia útil (coordenação, 20/10/2026):** a do #145 (`DiasUteisDoEscritorio`: feriado nacional e
  estadual da UF do escritório, recesso; ponto facultativo conta como útil). "N dias" sem qualificador conta ÚTEIS
  (CPC art. 219); só "corridos" escrito conta corridos. **Feito aqui:** corridos para úteis no `AnaliseDoPrazoDaMeta`,
  com teste. **Quando o #145 chegar ao master:** o `DiasUteisNacionais` passa a delegar ao `DiasUteisDoEscritorio`
  (hoje ele tira Carnaval, segunda de Carnaval e Corpus Christi e não conhece estadual). Anotado: o `somarUteis` do
  `pasta-metas.js` conta só seg-sex.

- **Feito: o motor, sem tela.** `App\Tarefa\Service\AnaliseDoPrazoDaMeta` (porte do `IA_PRAZO`, dc 3971-4026),
  com a tabela de tipos do CPC, as datas escritas no texto, o termo inicial pela ciência ou pela publicação, a
  folga, a urgência e a prioridade; e o calendário do `DiasUteisNacionais` (feriados nacionais com os móveis,
  recesso). Testes: `tests/Tarefa/Unit/AnaliseDoPrazoDaMetaTest.php`. O defeito do desenho (FERIADOS
  redeclarado em MM-DD, D2 da auditoria) não foi copiado.
- **Travado pelo dono (coordenador, 19/10), pacote junto com a 0d:**
  1. Rótulo "I.A." numa regra (chip, "ANÁLISE DA I.A.", "Estimado pela I.A.", tooltips): conflita com a regra 1
     da Inteligência ("nenhuma resposta que não veio do modelo") e com a S-4. Até a resposta, nenhum rótulo
     "I.A." em regra.
  2. Sugestão automática de prazo legal visível na tela: risco jurídico. Até a resposta, nenhum prazo sugerido
     visível.
  3. Leitura do conteúdo de documentos pela IA (análise documental, "Ler N documentos", "Índice inteligente"):
     conflita com a regra 7. Até a resposta, nada de leitura de conteúdo.
- **Depois da decisão:** migration da Tarefa (prioridade, tipo, prazo jurídico, estado, publicação, ciência,
  "usou a sugestão") e a criação da meta no `TarefaController::criarParaPasta` (legado), combinadas com a 7d
  (#89 mexe no mesmo controller).
- **Análise documental (B), feito:** a fase dos documentos sugeridos vem da última movimentação do Push quando ela
  a diz (`FaseDoProcessoPeloPush`, que reaproveita a "Situação atual" do #82: regras do CPC sobre o texto, sem
  IA nem leitura de documento), como o `faseDe` do `bj-docsug.js`; senão, segue pela classe processual. A tela diz
  "pela última movimentação do Push: <situação>". O resto (camada de IA do "Sugerir documentos", confiança "Alta" pelo PDF, memória por
  processo, leitura de documentos) está no "Para o dono".

## Desvios do desenho (revisão de 19/10, decididos pela segurança do prazo)

- **Prazo pela publicação:** o desenho conta os dias úteis a partir do termo (1º dia útil depois da publicação) e
  perde esse dia: um prazo de 5 dias publicado em 23/10 daria 03/11. Aqui conta a partir da publicação, com o
  1º dia útil seguinte como dia 1 (CPC 224 § 3º), e dá 30/10, a mesma data da "Situação atual" do Push. A data
  mais cedo é a segura. Pela ciência não muda.
- **Explicação com data no texto e termo:** o desenho explicava uma contagem pelo termo que não gerou a data; aqui
  a explicação diz a data do texto que foi usada.
- **Bordas:** mês inválido no texto ("15/13") é descartado e cai na próxima regra (o JS virava janeiro do ano
  seguinte); "em 0 dias úteis" é hoje.
- **Fase pelo Push (B):** "Aguardando intimação para réplica" = contestação e "Análise judicial após a réplica" =
  réplica, como o `faseK` do desenho; "Concluso ao juízo" (confiança baixa) e saneamento não trocam a fase: a
  lista segue pela classe. A situação é recalculada aqui e no painel do Push (duas consultas leves a mais por
  página): quando o #82 entrar, vale unificar numa leitura só.

## O motor (contrato para a tela que vier)

Entrada `EntradaDaAnaliseDoPrazo`: texto (título + descrição), hoje, publicação, ciência, prazo jurídico
informado, confirmado. Saída `AnaliseDoPrazo`: tipo, dias úteis legais e fonte, prazo jurídico (e se é
aproximado), estado (`EstadoDoPrazoJuridico`: informado, confirmado, estimado, necessita conferência, sem prazo
legal; SEM rótulo de tela), prazo interno, folga, prioridade, urgência, dias até o interno, dias úteis até o
jurídico e feriados no período, termo inicial e origem, a alternativa pela origem quando a pessoa informou outra
data, e o "porquê" literal do desenho. Síncrono e determinístico, como o desenho: pode ser chamado a cada
alteração do modal por uma rota leve, sem fila.

## Para o dono

1. Rótulo da análise (ver 1 acima) e se o prazo legal sugerido aparece (2).
2. Leitura de conteúdo de documentos pela IA (3): privacidade, custo e extração (frente D).
3. Prazo e responsável obrigatórios na nova meta (o desenho exige; hoje são opcionais: D-META).
