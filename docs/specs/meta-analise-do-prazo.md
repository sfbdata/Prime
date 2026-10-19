# Nova meta: análise do prazo (desenho 02, dc L95-172) e ajustes da análise documental

Frente `meta-prazo`, sessão f6, aberta em 19/10/2026 a pedido do coordenador (fatias de Inteligência do 02
tiradas da 4b). **Empilhada sobre o #82** (`push-painel-monitoramento`), declarado: usa o `DiasUteisNacionais`
dele como fonte única de dias úteis. Risco: prazo jurídico é risco (`trilha-b-inventario/componentes.md`).

## Estado da frente

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
