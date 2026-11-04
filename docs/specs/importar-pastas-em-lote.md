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
   soltos vira "NOVA PASTA". Ignora `.oculto`, `Thumbs.db`, `desktop.ini`, e tudo que está dentro de pasta oculta em qualquer nível
   abaixo da escolhida (`.ocultos/rascunho.pdf`; correção de 03/11/2026). Nome do cliente =
   nome da subpasta sem o número da frente ("001 - ", "01_", "1. "; nome só com número fica;
   03/11/2026), `_`/`-` viram espaço, maiúsculas. Ordem alfabética pt-BR.
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
  lote: conta e entra na mensagem final, cada um com nome e motivo curto ("2 arquivos não foram
  anexados: foto.jpg (passa de 3 MB) e app.exe (tipo não aceito)"; até 5 pelo nome, depois "e mais N"). Espaço esgotado
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

- **D11 Pasta de um cliente só (pedido do Samuel, 03/11/2026; ampliada em 04/11/2026 a pedido do
  Farlei).** Quando a pasta escolhida tem **qualquer** subpasta com arquivo (com ou sem arquivo solto
  direto nela), o modal pergunta, no visual do próprio
  modal (`.il-pergunta`, sem Bootstrap): "Esta é a pasta de um cliente só?", com o texto
  'A pasta "X" tem N subpastas (A, B, C...). Criar N pastas, uma por subpasta, ou 1 pasta só, "X", com
  tudo?' (até 5 nomes; com arquivo solto, conta mais uma pasta "X" para os soltos). Os botões são
  "Criar N pastas" (primeiro) e '1 pasta só, "X"'. O botão da IA (etapa 2) aparece junto, quando a IA
  está disponível: monta uma pasta por subpasta e já pede a sugestão.
  - **1 pasta só** (antes "Sim"): tudo vira **uma** pasta, com o nome da pasta escolhida como cliente. O arquivo que estava
    numa subpasta leva o caminho dentro da pasta escolhida na **descrição** do documento
    (`descricao` do upload, que já existe; coluna `text`): ex. `Processo/Inicial.pdf`. Arquivo solto
    fica sem descrição. Sem migration.
  - **Criar N pastas** (antes "Não"): comportamento de antes (cada subpasta de 1º nível vira uma pasta; os soltos, outra com o
    nome da pasta escolhida).
  - **Pasta = caso** (regra do Samuel, 03/11/2026): o mesmo cliente pode ter várias pastas. O "1 pasta
    só" é para **um caso só**; quando as subpastas são casos diferentes do mesmo cliente, a resposta é
    "Criar N pastas". Quem decide é a pessoa; o sistema nunca junta casos sozinho.
  - Só arquivos soltos: não pergunta. Só subpastas: **pergunta** desde 04/11 (antes não perguntava, e
    "PAULO/Documentos" e "PAULO/Processo" viravam 2 clientes, DOCUMENTOS e PROCESSO). A seleção de
    "arquivos soltos" também não pergunta.

## Arquivos

- `app/src/Expediente/Controller/ImportarLoteController.php` (rota nova)
- `app/templates/expediente/_importar_lote_modal.html.twig` (modal do desenho, CSS escopado `.il-`)
- `app/public/js/expediente-importar-lote.js`
- `app/templates/expediente/index.html.twig`: 2 linhas (include do modal e do script)
- `app/tests/Expediente/Functional/ImportarLoteControllerTest.php`

Sem migration.

## Etapa 2 (só spec, sem código): IA sugere o agrupamento

Pedido do Samuel em 03/11/2026. Ainda sem código; esta seção é o alvo da implementação.
Risco: MÉDIO (manda dado do escritório ao provedor de IA). As regras de IA vêm de
`app/src/Inteligencia/CLAUDE.md` e das specs `ia-anthropic-ligar.md` e `chat-ia-cadastro.md`.

### O problema

O agrupamento por regra (subpasta de 1º nível = uma pasta, mais a pergunta D11) erra quando a pasta
do computador é bagunçada: vários níveis ("Clientes/2024/MARIA/Processo 1"), nome com número e
sobrenome ("0123_maria_silva_trabalhista"), arquivos soltos que são de um cliente da subpasta ao lado.
A IA olha **só a árvore de nomes** e sugere quais pastas do computador são um caso cada, com o nome do
cliente. A pessoa confere na tabela de sempre e clica Importar.

### Regra do Samuel: pasta = caso (inegociável)

- No BlueJus, uma pasta é um **caso**. O mesmo cliente pode ter várias pastas.
- **A IA nunca junta pastas de casos diferentes**, nem do mesmo cliente. No máximo informa
  "mesmo cliente que a pasta X" ao lado da linha, sem mudar o agrupamento.
- Garantia técnica, não só no prompt: a IA não devolve grupos de arquivos livres. Ela escolhe **pastas
  do computador** (nós da árvore) que viram um caso cada. Todos os arquivos abaixo do nó escolhido vão
  juntos. Dois nós irmãos nunca viram um grupo só. O servidor recusa resposta em que um nó escolhido
  está dentro de outro.

### O que vai ao provedor (e o que nunca vai)

- **Vai:** a lista de caminhos relativos (pastas e nomes de arquivo), numerada, dentro de um bloco
  delimitado novo `ARVORE_DO_LOTE` em `NeutralizadorDeConteudo::DELIMITADORES` (o nome de arquivo é
  conteúdo externo: dado, nunca instrução).
- **Antes de sair:** `MascaradorDeDadosPessoais` sempre ligado neste uso (CPF, CNPJ, telefone e e-mail
  no nome do arquivo são comuns: "RG 123.456.789-00.jpg" vira "RG [CPF].jpg").
- **Nunca vai:** conteúdo dos arquivos, tamanho, data, nada do escritório, id de pessoa ou escritório
  (nem em metadado).
- O nome do cliente vai, porque está no nome da pasta. É o que a regra do domínio já permite
  ("do cliente sai só o nome").
- **Nada é guardado:** a árvore não é gravada no banco nem em log. A linha de uso guarda só
  `contexto_hash` e contagens (caminhos, nós, grupos sugeridos). Nome de arquivo nunca vai a log,
  notificação ou auditoria.

### Onde a IA aparece (liberação, aceite e cota)

- **Botão "Sugerir agrupamento com IA"** na tabela do lote, só quando
  `DisponibilidadeDeInteligencia::para()` libera: plataforma ligada, escritório liberado pelo Master e
  habilitado pelo admin, pessoa com o módulo `inteligencia`, cota e orçamento com folga. Fora disso o
  botão não aparece e o lote segue por regra, como hoje.
- **Clique explícito, nunca automático.** Cada clique é 1 análise na cota do escritório.
- **Sem aceite novo (decisão do Samuel, 04/11).** Nem versão 3 de `VersaoDoAceiteDaIa` nem aviso
  próprio desta função. O botão segue só as regras que a IA já tem hoje:
  `DisponibilidadeDeInteligencia::para()` (que já inclui o aceite atual do admin) e a cota.
- **ZDR:** igual ao resto da IA. Piloto com a retenção padrão (decisão de 26/10), ZDR antes de abrir a
  outros escritórios. Nada de Batch nem Files API.

### Modelo e custo por lote

- Sempre **Haiku 5.5** (`claude-haiku-5-5`), fixado no `PedidoDeLinguagem::$modelo` deste uso, mesmo que o
  Master escolha outro modelo para o resto. A tarefa é classificação de nomes; o modelo maior não paga
  a diferença.
- `exigeJson`, `maxTokens` 4.000, sem `temperature`. Até **2.000 itens** por pedido (pastas e arquivos da
  lista numerada), cada um cortado em 200 caracteres. Lote maior: a IA sugere só os primeiros 2.000 e a tela diz isso.
- **Custo estimado** (preço de `ModeloDeIa::precoPorMilhao()`: US$ 0,10 por milhão de entrada,
  US$ 0,50 por milhão de saída, mais 1,1x da região `us` em `CustoDaIa`):

  | Lote | Entrada (≈) | Saída (≈) | Custo por clique |
  |---|---|---|---|
  | 100 arquivos | 3 mil tokens | 0,5 mil | ≈ US$ 0,0006 (R$ 0,004) |
  | 500 arquivos | 11 mil tokens | 2 mil | ≈ US$ 0,0023 (R$ 0,013) |
  | 2.000 itens (teto) | 42 mil tokens | 4 mil | ≈ US$ 0,0068 (R$ 0,04) |

  Conta: ~20 tokens por caminho mais ~1 mil de instrução; dólar a R$ 5,50. [Suposição] O consumo real
  sai de `tokens_entrada`/`tokens_saida` gravados na linha da análise.

### Resposta da IA e validação no servidor

```json
{"casos": [{"no": 12, "cliente": "MARIA DA SILVA", "mesmoClienteQue": null, "motivo": "subpasta com procuração e RG"}],
 "soltos": [3, 4], "duvidas": [{"no": 7, "texto": "pode ser de dois clientes"}]}
```

- `no` é o número de uma **pasta** da lista enviada. `soltos` são arquivos que a IA não pôs em caso.
- O servidor confere antes de devolver à tela e descarta o que não passa:
  - nó inexistente ou que é arquivo;
  - nó dentro de outro nó escolhido;
  - cliente vazio ou com mais de 255 caracteres;
  - `mesmoClienteQue` apontando para nó que não é caso.
- Arquivo que não ficou em nenhum caso vai para a linha "Arquivos sem caso" (desmarcada), para a
  pessoa decidir. Nunca some.
- Teto de 500 casos por resposta. Resposta inválida inteira: a tela mantém o agrupamento por regra e
  diz "A IA não conseguiu sugerir agora".

### Na tela

- A tabela é a mesma. A sugestão só **troca as linhas**: cada caso vira uma linha com o nome sugerido
  (editável), a coluna SITUAÇÃO mostra "Sugerida pela IA", e "mesmo cliente que a pasta X" aparece
  como texto pequeno sob o nome. Não junta nada.
- Botão "Voltar ao agrupamento por pasta" desfaz a sugestão (volta ao `montar` por regra).
- **A IA nunca grava.** Quem cria as pastas é o clique em Importar, com usuário e escritório da
  sessão, pela rota que já existe (D1). O texto da tela diz que a sugestão é da IA e pode errar.

### Rota e peças (para a implementação)

- `POST /expediente/importar-lote/sugerir` (`expediente_importar_lote_sugerir`): módulo `pastas` e
  `inteligencia`, CSRF `expediente_importar_lote`, corpo JSON com a lista de caminhos (só nomes).
  Responde `{casos, soltos, duvidas}` já validados, ou 409 com o motivo da indisponibilidade.
- `SugerirAgrupamentoDoLoteUseCase` no domínio Inteligencia, pela porta `ProvedorDeLinguagem`; tipo
  novo de análise `agrupamento_lote` (conta na cota). Teste unitário com `ProvedorFalso` (resposta
  boa, nó aninhado, nó irmão juntado, índice inválido, JSON quebrado) e functional da rota
  (sem módulo, IA indisponível para o escritório, cota esgotada, sucesso). Nenhum teste chama a Anthropic de verdade.
- Sem migration: `tipo` da análise é `varchar(40)` com o enum só no PHP (`TipoDeAnalise`), então
  `agrupamento_lote` entra sem mudar o banco.

### Decisões do Samuel (04/11/2026)

O jurídico não trava a função; os advogados ajustam os termos depois.

1. **Sem aceite novo:** nem v3 nem aviso na função. Valem só `DisponibilidadeDeInteligencia::para()`
   e a cota, como no resto da IA.
2. **Clique explícito:** a IA só roda quando a pessoa clica. Nunca automática.
3. **Teto de 2.000 itens** (pastas e arquivos da lista) por pedido. Lote maior: a IA sugere os primeiros 2.000 e a tela avisa.
