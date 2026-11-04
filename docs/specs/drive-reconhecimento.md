# Reconhecimento do Drive ao conectar

Pedido do Samuel (04/11/2026). Risco BAIXO/MÉDIO (Sync; não toca identidade nem permissão).

## Regra

Antes de o envio automático criar pastas no Drive, o sistema reconhece as pastas que já estão lá.

1. **Quando roda:** ao definir a pasta-raiz (`DefinirRootFolderDriveUseCase`; é este passo que liga o envio, não há
   outro) e pelo botão "Reconhecer de novo" da tela `/sync/drive/conexao`. Roda no worker (mensagem
   `ReconhecerPastasDoDrive`, transporte `async`): um acervo grande pede muitas leituras do Drive.
2. **O que compara:** as subpastas DIRETAS da raiz sem vínculo (nenhuma `pasta.drive_folder_id` igual, no sistema
   inteiro) com as pastas do escritório sem vínculo e fora da lixeira. Casa se:
   - o nome do Drive normalizado é igual ao `ReconciliadorDePasta::nomeEsperado()` normalizado; ou
   - o nome do Drive sem o número da frente ("001 - ") é igual ao nome do cliente (também sem número).
   Normalizar (`NormalizadorDeNomeDoDrive`): sem acento, minúsculas, espaços repetidos e das bordas fora, traço longo
   como hífen.
3. **Casamento único** (1 candidata, e ela só casou com essa subpasta): grava `drive_folder_id` (sem mexer em
   `drive_manter_nome`) e liga cada documento sem vínculo ao arquivo do Drive de mesmo nome original (sem caixa) e
   mesmo tamanho em bytes, na pasta e nas subpastas (até 10 níveis). Arquivo já ligado a outro documento é pulado.
4. **Dúvida** (0 candidatas, 2 ou mais, ou candidata disputada por outra subpasta): grava `ReconhecimentoPendente`
   (tenant, drive_folder_id, nome no Drive, ids das candidatas, estado). Uma por subpasta e escritório.
5. **Enquanto pendente:** `garantirFolderDaPasta` (worker e cron) não cria pasta no Drive para as candidatas, e o
   Drive para o sistema (`--modo=importar`) não cria pasta a partir da subpasta. Os dois registram no log.
6. **Decisão na tela** (mesma permissão da tela): "Ligar a esta pasta" (uma candidata, ou o número da pasta quando
   não há candidata) liga a pasta e os documentos e marca `confirmado`; "Ignorar" marca `ignorado` e as candidatas
   voltam ao envio normal.

## Casos

- Reexecutar é idempotente: subpasta com registro (pendente ou decidido) é pulada; decidida não volta.
- Pasta ignorada não disputa mais candidata nenhuma; pendente continua disputando.
- Nada é criado, apagado ou renomeado no Drive pelo reconhecimento.

## Decisões onde o pedido era omisso

- "Ligar o envio" = definir a pasta-raiz (a conexão só fica ativa aí). O pedido é disparado só se a conexão ficou
  pronta.
- Worker em vez de na hora: o reconhecimento lista arquivos de cada pasta casada; não cabe no tempo de uma página.
  Consequência: entre ativar e o worker rodar, o envio por evento pode criar pasta antes do reconhecimento.
- Número digitado = NUP da pasta (como a tela mostra). Vale também quando as sugeridas já não servem.
- Pasta na lixeira não é candidata. Documento na lixeira não é ligado.
