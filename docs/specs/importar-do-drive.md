# Importar do Google Drive (modal Nova Pasta)

Pedido do Samuel (04/11/2026), via Coordenador. Risco BAIXO (Expediente/Pasta, sem identidade nem permissão nova).

## Regra única

O "Importar do Google Drive" segue **as mesmas regras** do "Importar em lote" do computador
(`app/public/js/expediente-importar-lote.js`, versão com #623, #624 e #625):

- pergunta "Criar N pastas ou 1 pasta só?" com os nomes, quando há subpasta (#624);
- subpastas viram subpastas dos Documentos até 10 níveis; o que passa vai na descrição (#625, `pasta_secao_criar`);
- número da frente tirado do nome, ignorados (Thumbs.db, desktop.ini, ocultos) e recusados iguais;
- botão da IA (#623) igual: ele já recebe só caminhos.

A regra antiga "NUP - CLIENTE - AÇÃO" do `ReconciliarCommand::driveParaSistema` **não** entra.

**Uma lógica só.** O JS do lote ganha o conceito de "fonte de arquivos", com contrato
`{caminho, nome, tamanho, anexar(FormData), urlDeEnvio}`:

| Fonte | caminho | envio |
|---|---|---|
| Computador | `webkitRelativePath` ou `name` | `arquivo` = File, para `uploadUrl` |
| Drive | caminho montado pelo servidor | `drive_file_id`, para `uploadDriveUrl` |

Só as leituras de `File` (caminho, tamanho e o append do envio) passam pela fonte. Pergunta, montar, níveis,
IA, subpastas e recusados não mudam e não são copiados.

## Fluxo

1. Modal Nova Pasta, ao lado de "Importar em lote": botão "Importar do Google Drive" (ícone `bi-google`).
   Só aparece se o escritório tem `TenantDriveConexao` pronta (`estaPronta()`); sem conexão, não aparece.
2. Campo "Link da pasta do Drive" (aceita link `/folders/<id>`, `?id=` ou o id; `ExtratorDeFolderId`).
   Não há Picker do Google no sistema; o link basta nesta entrega.
3. GET `expediente_importar_lote_drive_arvore` (`?pasta=`): o servidor lista a árvore recursivamente
   (`listarSubpastas` + `listarArquivos`) com a conexão do escritório e devolve
   `{itens:[{id, caminho, tamanho, mimeType}], truncado}`. Teto de 2.000 arquivos e 10 níveis abaixo dos
   Documentos (os mais fundos seguem para a descrição, como no computador). Arquivo nativo do Google
   é exportado (`files.export`) e entra com o nome mais a extensão: Docs como `.docx`, Planilhas como
   `.xlsx`, Apresentações como `.pdf`. O que não exporta (formulário, atalho, mapa, site) cai em
   "recusados" com o motivo.
   **Segurança:** o token é do escritório e enxerga o Drive inteiro dele. Só vale pasta **dentro** da
   pasta-raiz conectada (`rootFolderId`): o servidor sobe pelos `parents` até achar a raiz. Fora dela,
   recusa com "Escolha uma pasta dentro da pasta do escritório no Drive". O usuário precisa da mesma
   permissão de criar pasta, na árvore e no envio.
4. Daí em diante é o mesmo `lerPasta(lista)` do computador.
5. Envio: um POST por arquivo para `pasta_peticionar_upload_drive` (irmã de `pasta_peticionar_upload`,
   mesmo token `peticionar_upload_{id}`, mesmos campos, `drive_file_id` no lugar de `arquivo`).
   O servidor baixa num temporário, monta `UploadedFile` e chama o **mesmo** `UploadPecaUseCase`
   (tipos, tamanho, duplicado e cota iguais) e devolve o mesmo JSON. Permissão, CSRF e seção do
   `PeticionarController::upload` vão para um método comum, sem cópia. Arquivo acima do limite já sai
   recusado pelo tamanho da árvore, sem baixar.

## Decisões

- Síncrono, um arquivo por POST, como o lote do computador (já sequencial, com progresso). Sem worker.
- Sem migration. Não grava `drive_file_id` de origem no documento: o arquivo é **copiado** para a pasta
  (a exportação ao acervo do Drive segue como em qualquer upload).
- Erros: Drive sem conexão, pasta não encontrada ou sem acesso viram mensagem curta no modal.

## Testes

- Functional da árvore e do upload pelo Drive, com fake de `GoogleDriveClientFactoryInterface`
  (árvore simulada; os 11 cenários de `~/lote-teste` viram árvores simuladas e o resultado da árvore é
  comparado aos caminhos de cada `esperado.json`).
- Segurança: pasta fora da raiz conectada recusada (árvore e envio, que confere o arquivo também);
  usuário sem permissão de criar pasta recebe 403.
- Exportação: Docs, Planilhas e Apresentações chegam com `.docx`, `.xlsx` e `.pdf`; formulário recusado.
- Limites e duplicado: o mesmo caminho do `UploadPecaUseCase` (um caso de tipo recusado e um de tamanho).
- Estático do JS: as funções de lógica aparecem uma vez só; o envio do Drive usa a fonte.

## Para olhar na tela (smoke do dono)

Com o Drive conectado, colar o link de uma pasta com os 11 cenários e conferir o mesmo resultado do
lote do computador.
