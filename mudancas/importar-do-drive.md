---
tipo: novidade
---
## Changelog
- "Importar do Google Drive" no modal Nova Pasta do Expediente, ao lado do "Importar em lote", só com o Drive do escritório conectado. O mesmo `expediente-importar-lote.js` recebe a árvore do Drive (`expediente_importar_lote_drive_arvore`, `LoteDoDrive`) com os caminhos no formato do navegador: pergunta, subpastas até 10 níveis, número da frente, ignorados, recusados e IA são os do lote do computador.
- Cada arquivo vai por `pasta_peticionar_upload_drive`: o servidor baixa do Drive (ou exporta Docs, Planilhas e Apresentações como DOCX, XLSX e PDF) e grava pelo mesmo `UploadPecaUseCase`. O trecho comum do upload foi para `PeticionarController::anexar`.
- Segurança: só vale pasta e arquivo dentro da pasta-raiz conectada (conferido pelos `parents`, na árvore e em cada envio) e com o módulo Pastas.
- A pasta criada fica ligada à pasta de origem (`drive_folder_id`) e cada documento ao arquivo de origem (`drive_file_id`); o envio automático usa essa pasta e não a renomeia (`pasta.drive_manter_nome`, migration `Version20261104120000`).

## Para o cliente
- Agora dá para trazer uma pasta do Google Drive do escritório direto para o BlueJus: em Nova Pasta, clique em "Importar do Google Drive" e cole o link da pasta. Tudo funciona como no Importar em lote do computador: cada subpasta vira uma pasta, a organização das subpastas vem junto e Documentos, Planilhas e Apresentações do Google entram como Word, Excel e PDF.
- A pasta importada continua ligada à pasta do Drive: o envio automático guarda os documentos nela, sem criar outra pasta nem trocar o nome que ela tem no Drive.
