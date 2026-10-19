# Painel lateral do Push, parte A: monitoramento, Buscar agora e Situação atual (P4a)

Frente do painel lateral da aba Push Processual, dividida em P4a a P4e (aprovado pelo coordenador, 19/10/2026):
P4a (esta) monitoramento + buscar agora + situação atual por regras; P4b avisar quando chegar (equipe);
P4c e-mails do cliente + envio manual da Análise IA com CC da Controladoria; P4d envio automático ao cliente;
P4e atualizar com arquivos (PDF/ZIP/DOCX + OCR) e análise completa do processo (12 etapas, relatório de 14 seções).

Desenho: `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html` (checkout principal):
coluna direita L2567-2628, grade `pushAnim` L6595 (`minmax(0,1fr) minmax(0,300px)`), `pushVals` L5409-5449,
`previsaoProc` L5092-5117, `REGRAS_PROC` L5064-5078, `FERIADOS_NAC` L5079, `diaUtil`/`somaUteis`/`uteisAte`
L5089-5091, taxa de acertos L5105-5109. Risco BAIXO.

## 1. Monitoramento por processo

- `Processo` ganha `monitoramentoPausadoEm` (null = ativo), `monitoramentoPausadoPor` (FK user, SET NULL) e
  `ultimaVerificacaoEm` (quando o BlueJus consultou o DJEN por este número pela última vez; não confundir com
  `dataAtualizacao`, que vem do DataJud).
- A captação (`SincronizarPublicacoesDjenUseCase`, via `ProcessoRepository::listarNumerosAcompanhadosDoTenant`) pula
  processos pausados e grava `ultimaVerificacaoEm` de cada número consultado com sucesso.
- Cartão (L2568-2577): bolinha verde piscando/cinza; títulos e subtítulos exatos:
  sem processo "Sem monitoramento"/"Vincule um processo"; ativo "Monitoramento ativo"/"Captura automática nos
  diários e no PJe"; pausado "Monitoramento pausado"/"Novas movimentações não serão capturadas". Switch
  (`role=switch`, aria-label "Monitoramento") do processo **principal**; linha "{nº} · {tribunal}"; linha
  "Última verificação: hoje, HH:MM" / "ontem, HH:MM" / "dd/mm/aaaa, HH:MM" / "ainda não verificado".
- `POST /pasta/{id}/push/monitoramento` (CSRF, EDIT na pasta) alterna. Pausar não apaga nada.

## 2. Buscar agora

- Botão "Buscar agora" (`bi-cloud-download`, título "Busca, lê e audita o processo: prazos, decursos, pendências e
  próximos atos"); rodando: `bi-arrow-repeat` "Lendo o processo...". Desabilitado com monitoramento pausado.
- `POST /pasta/{id}/push/buscar` (CSRF, VIEW na pasta): para cada processo da pasta não pausado, consulta o DJEN pelo
  número (últimos 30 dias, mesma deduplicação e reconciliação da captação) e grava `ultimaVerificacaoEm`.
  Limite: 1 busca por pasta a cada 60 s (429 "Aguarde um minuto para buscar de novo."). Respeita o limite do DJEN
  (para e avisa). DataJud: fora deste PR (o mapper apaga e regrava movimentações; precisa de spec própria).
- Resposta e aviso: "N movimentação(ões) nova(s)" ou "Nenhuma movimentação nova", e a página recarrega a aba.
- As 12 etapas com barra de progresso são da leitura completa (P4e); aqui só o estado "Lendo o processo...".

## 3. Situação atual (variante sem leitura, `previsaoProc`)

`App\Processo\Service\PrevisaoProcessual` (porte fiel, testado com os casos do desenho):
- Movimentações: as publicações DJEN dos processos da pasta (a mesma lista da aba), texto normalizado
  (`tipoComunicacao + ' ' + teor` sem HTML, minúsculo, sem acento, até 5000 caracteres), mais recente primeiro.
- `REGRAS_PROC` transcrita (13 regras, mesma ordem, mesmos textos de fase/quem/dias/base/próximo/post/confiança).
  Ajustes de contexto L5097-5102: `exige contestacao`, classe "cumprimento de senten" rebaixa as regras de
  conhecimento com a nota exata, "prazo de N dias" do texto vence `R.dias` com a nota exata.
- Dias úteis: sábado, domingo, recesso 20/12 a 20/01 e os feriados nacionais (use os do `CalendarioDoSistema` de
  âmbito nacional, que cobre os móveis de qualquer ano). Contagem `somaUteis` como L5089-5091 (o dia da
  movimentação conta como publicação; começa no 1º dia útil seguinte). Tooltip da previsão com o texto exato do
  desenho.
- Campos e textos exatos L2605-2624 e L5110-5116 (selo "Prazo encerrado"/"Prazo terminando"/"Em andamento"/"Sem
  prazo legal para a parte", "faltam N dias úteis", "(encerrado)", "Sem data", "PRÓXIMA ETAPA PROVÁVEL",
  "Depende de ato do juízo", "Confiança X", "Base: … · histórico de N movimentações", nota de alerta, rodapé
  "Previsão da I.A, não é ato oficial.").
- Some com monitoramento pausado, sem processo ou sem movimentações (L5420).
- Taxa de acertos: tabela `previsao_processual` (tenant, processo, chave da última movimentação, previsto,
  conferida, acertou, em). Gravada no servidor quando a última movimentação muda (na montagem da aba, idempotente
  por chave), comparando como L5105-5109. Texto "Previsões conferidas: X de Y acertaram" / "Ainda sem previsão
  anterior para conferir".

## 4. Tela

- A aba Push passa à grade de duas colunas do desenho (L6595), com a coluna direita de 300 px contendo só o cartão
  de Monitoramento/Situação atual neste PR (os cartões "Avisar quando chegar" e "Avisar cliente" chegam em P4b/P4c).
  Em telas estreitas a coluna desce para baixo da lista.
- Template `templates/pasta/_push_painel.html.twig`, CSS `public/css/pasta-push-painel.css`, JS
  `public/js/pasta-push-painel.js` (vanilla, sem innerHTML com dado). Estilos inline do desenho portados 1:1 em
  classes citando a linha.

## 5. Fora deste PR

Variante "com leitura completa" e "Ver análise completa" (P4e); DataJud no Buscar agora; apensados com switch
próprio (o desenho não tem); filtro por processo (só no desenho de 18/10).

## 6. Testes mínimos

Unit: cada regra de `REGRAS_PROC` com um texto real, ajustes de contexto, contagem de dias úteis (feriado,
recesso, virada de ano), selo de status, exemplo do desenho (Intimação 02/10/2026 → réplica, 26/10/2026, faltam 5
em 19/10/2026, confiança Média com a nota da classe), taxa de acertos. Functional: alternar monitoramento (CSRF,
sem EDIT 403, outro tenant 404), buscar agora (DJEN dublê, 429 no limite), captação pula pausado e grava a última
verificação, cartão com os textos certos em cada estado, grade com filho direto.
