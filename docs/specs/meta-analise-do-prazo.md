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
- **Decidido (coordenação, 27/10/2026), vale para a tela:**
  1. Sem rótulo "I.A." em regra: o bloco se chama "Análise do prazo"; o estado "Estimado" não diz "pela I.A.".
  2. O prazo legal aparece como ESTIMATIVA, com data, termo inicial e fonte (a explicação do motor). Antes de
     gravar a meta, a pessoa confirma a análise ("Confirmar análise"), informa o prazo jurídico ou corrige as
     informações: o servidor recusa (422) a meta cujo prazo jurídico ainda é só estimado ou "necessita conferência".
  3. Sem leitura do conteúdo de documentos (regra 7 da Inteligência): continua fora.
  4. Prazo e responsável obrigatórios na Nova meta (D-META): já valem em `TarefaController::criarParaPasta`.
- **Feito (27/10): tela e gravação.** Rota `tarefa_analise_do_prazo` (`AnaliseDoPrazoController`, só leitura, mesmo
  CSRF do formulário), `RegistrarAnaliseDoPrazoNaMetaUseCase` na criação (quando o modal manda `analise_prazo=1`) e
  migration `Version20261027153400` (Tarefa: `prazo_juridico`, `estado_prazo_juridico`, `data_publicacao`,
  `data_ciencia`, `usou_prazo_sugerido`). O estado é refeito no servidor com as datas recebidas.
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

1. Leitura de conteúdo de documentos pela IA: privacidade, custo e extração (frente D). Segue fora.

## Decisões da revisão de 27/10 (orquestrador, pela recomendação)

- **A trava do "Confirmar análise" é do formulário, não segurança.** O servidor só a aplica quando o modal manda
  `analise_prazo=1` (o Push e o drawer da IA preenchem o mesmo modal, então passam por ela). Chamadas sem o campo
  (testes, integrações) criam a meta como sempre; se a consulta da análise falhar, a meta também é criável. Tornar
  a trava obrigatória no servidor derrubaria esses caminhos: fica como decisão futura do dono.
- **Confirmação velha:** mudar título, descrição, publicação ou ciência depois de "Confirmar análise" desfaz a
  confirmação (o prazo digitado pela pessoa fica). Limpar a data jurídica volta ao prazo estimado.
- **Linha de aprendizado do desenho:** fora desta entrega (no desenho vem de localStorage, sem lastro multi-tenant);
  o elemento existe escondido, para quando houver backend.
