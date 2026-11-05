---
tipo: novidade
---
## Changelog
- Reconhecimento do Drive antes de qualquer envio: ao definir a pasta-raiz (e pelo botão "Reconhecer de novo" em `/sync/drive/conexao`), o worker (`ReconhecerPastasDoDrive`, `ReconhecerPastasDoDriveUseCase`) casa as subpastas diretas da raiz com as pastas sem vínculo pelo nome do envio ("NUP - CLIENTE - AÇÃO") ou pelo cliente sozinho, sem diferença de caixa, acento e espaços e ignorando o número da frente (`NormalizadorDeNomeDoDrive`).
- Casamento único grava `drive_folder_id` na pasta e `drive_file_id` nos documentos de mesmo nome e tamanho (pasta do Drive e subpastas, até 10 níveis), respeitando o vínculo único do arquivo (`LigadorDeArquivosDoDrive`). Nada é criado, apagado ou renomeado no Drive.
- Dúvida (nenhuma candidata, várias, ou pasta disputada) vira `ReconhecimentoPendente` (tabela `sync_drive_reconhecimento`, migration `Version20261104130000`), decidida na tela: "Ligar a esta pasta" (candidata ou número digitado) ou "Ignorar".
- Enquanto a dúvida está pendente, o envio automático não cria pasta nova no Drive para as candidatas e a importação não cria pasta a partir da subpasta.
- Trava: até o 1º reconhecimento terminar (`sync_drive_conexao.reconhecimento`), o envio não cria pasta nova no Drive. Se ele falhar, a trava continua e a tela pede para tentar de novo.

## Para o cliente
- Quem já tem o acervo no Google Drive pode conectar sem medo de pastas em dobro: ao conectar, o BlueJus procura as pastas que já estão no Drive e liga cada uma à pasta do escritório de mesmo nome, junto com os documentos que já estão lá.
- Quando o BlueJus não tem certeza de qual pasta é qual, ele pergunta na tela "Conectar meu Drive": você escolhe a pasta certa ou manda ignorar. Até você decidir, ele não cria pasta nova no Drive para aquele caso.
- Enquanto o sistema reconhece as pastas do Drive, nenhuma pasta nova é criada lá. Se o reconhecimento não terminar, a tela do Drive avisa e mostra o botão para tentar de novo.
