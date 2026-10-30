# Importar pastas em lote (Expediente)

Risco: MÉDIO (cria pastas e grava arquivos em série; permissão de módulo e de recurso).
Pedido do Samuel em 30/10/2026, aprovado pelo dono. Desenho:
`docs/design/redesign-30-10-26/03 - EXPEDIENTE - VS 30.10.26.dc.html` (modal `lotOn`, JS `montar`/`importar`).

## O que o desenho pede

1. Botão "Importar em lote" no rodapé do modal Nova Pasta (visual da bluejus-17). Ao clicar, fecha a
   Nova Pasta e abre o modal "Importar pastas em lote".
2. Vazio: texto explicativo, exemplo em árvore, área "Selecionar a pasta dos clientes"
   (`webkitdirectory`) e o link "Ou selecionar arquivos soltos (vira uma pasta só)".
3. Agrupamento (`montar`): cada subpasta de 1º nível vira uma pasta; arquivo solto direto na pasta
   escolhida entra numa pasta com o nome dela (fiel ao `montar` do desenho); seleção de arquivos
   soltos vira "NOVA PASTA". Ignora `.oculto`, `Thumbs.db`, `desktop.ini`. Nome do cliente =
   nome da subpasta, `_`/`-` viram espaço, maiúsculas. Ordem alfabética pt-BR.
4. Tabela: marcar/desmarcar, Nº PASTA previsto (a partir do próximo número), CLIENTE editável,
   ARQUIVOS (qtd · tamanho), SITUAÇÃO (Pronta, Ignorada, Importada). Resumo
   "N pasta(s) · N arquivo(s) · numeração a partir de X" e "Escolher outra pasta".
5. Importar: exige nome em toda pasta marcada; barra de progresso "Pasta X · CLIENTE" e %;
   no fim, mensagem verde com a faixa de números e o total anexado; botão vira "Concluir".

## O que o sistema já tem (reaproveitado, nada novo de domínio)

- `CriarPastaUseCase` (transação, `GerarNumeroDePasta` com `pg_advisory_xact_lock` por tenant,
  vínculo com cadastro de cliente do mesmo nome). Mesmo caminho do `pasta_new`.
- Upload da aba Documentos: `pasta_peticionar_upload` (`UploadPecaUseCase`): MIME pelo conteúdo,
  teto por tipo, cota do plano (`ArmazenamentoComCota` → `espaco_esgotado`), aviso de conteúdo igual
  em outra pasta (`duplicados`), permissão `edit` na pasta e CSRF `peticionar_upload_{id}`.
- Próximo número: `expediente_nova_pasta_sugestoes` devolve `proximoNumero`.
- Não há limite de pastas por plano; o limite é a cota de espaço.

## Decisões (pela recomendação)

- **D1 Orquestração no navegador, uma pasta e um arquivo por pedido.** Rota nova
  `POST /expediente/importar-lote/pasta` (`expediente_importar_lote_pasta`) cria UMA pasta pelo
  `CriarPastaUseCase` e devolve `{status, id, nup, cliente, uploadUrl, uploadToken}`. Os arquivos sobem
  um a um pela rota de upload que já existe. Motivo: cada pedido fica dentro de `upload_max_filesize`/
  `post_max_size`, a barra de progresso é real e todas as travas do upload (MIME, teto, cota) valem
  sem cópia. Nada de rota que recebe tudo de uma vez.
- **D2 Permissão.** A rota nova exige o módulo `pastas` (igual ao `pasta_new`) e CSRF próprio
  `expediente_importar_lote`. O `uploadToken` só vai na resposta se a pessoa tem `edit` na pasta
  criada; sem isso a pasta é criada e a linha mostra "Sem permissão para anexar".
- **D3 Sem aviso de pasta duplicada.** O desenho não tem a etapa; cliente repetido é caso real.
  O lote passa direto (equivale ao `confirmar=1`).
- **D4 Categoria dos arquivos: DEMAIS** ("Demais documentos"): o lote traz RG, procuração e afins
  misturados; classificar fica para a aba Documentos.
- **D5 Número real é o do servidor.** A coluna Nº PASTA mostra a previsão; depois de importada,
  mostra o número gravado. A mensagem final usa os números reais.
- **D6 Falhas (desenho omisso, convenção do sistema).** Arquivo recusado (tipo, tamanho) não para o
  lote: conta e entra na mensagem final ("N arquivo(s) não foram anexados (motivo)"). Espaço esgotado
  ou falha ao criar a pasta interrompe o lote e mostra o erro no lugar do `lotErro`, junto do que já
  foi criado. Pasta criada nunca volta para a fila: o botão Importar retoma só as que faltam, e a
  pasta que parou no meio do envio fica "Incompleta".
- **D7 ⚠ Desvio de texto aprovado pela regra do pedido.** O desenho diz que arquivo repetido é
  "unificado na central, sem ocupar espaço de novo". O sistema não unifica armazenamento; a frase
  vira a verdade: "N arquivo(s) iguais a documentos de outras pastas" (conta os uploads com
  `duplicadoDe` não vazio na resposta do upload).
- **D10 Nome do cliente até 255 caracteres** (a coluna); maior volta 422 com mensagem.
- **D8 Concluir recarrega a lista** do Expediente (a lista é do servidor; o desenho insere as linhas
  no topo e ordena por número decrescente).
- **D9 Gancho.** O botão é da bluejus-17 (`data-acao="importar-lote"`) e dispara `bj:importar-lote`
  no `document`. O nosso JS só ouve o evento; não edita o modal Nova Pasta.

## Arquivos

- `app/src/Expediente/Controller/ImportarLoteController.php` (rota nova)
- `app/templates/expediente/_importar_lote_modal.html.twig` (modal do desenho, CSS escopado `.il-`)
- `app/public/js/expediente-importar-lote.js`
- `app/templates/expediente/index.html.twig`: 2 linhas (include do modal e do script)
- `app/tests/Expediente/Functional/ImportarLoteControllerTest.php`

Sem migration.
