---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 7): ferramenta `ler_documentos_e_modelos` (listar, ler PDF e imagem, lixeira da pasta, modelos de peça e o corpo de um modelo sem preencher), ferramenta `ler_processos` (buscar, detalhar com movimentações paginadas e para vincular) e a parte `publicacoes` do `ler_pasta`, que lê as publicações do Diário sem marcar como lidas.
- PDF e imagem vão num bloco à parte da resposta (`ArquivoNaResposta`), fora do teto de 50 mil caracteres; o log guarda só o sha256 do arquivo.
- `LeitorDeDocumentosDaPasta` aceita a porta do aceite (`PortaDaLeituraDeDocumentos`): a I.A. continua com o aceite da BlueJus I.A.; o conector usa a liberação do conector.
- `ler_agenda`: o prazo sai com o status da tela da agenda (`agendado` ou `concluido`), não mais nulo quando está em aberto.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT conectado à sua conta passa a abrir os PDFs e as imagens da aba Documentos de uma pasta, ver os modelos de peça do escritório, consultar os processos e ler as publicações do Diário de uma pasta.
- Ler uma publicação pelo app não a marca como lida no BlueJus.
- Na agenda, o app passa a saber se um prazo já foi cumprido ou ainda está em aberto, como você vê na tela.
- O app só enxerga o que você já vê no BlueJus: abas ocultas, pastas trancadas e processos em segredo de justiça ficam de fora.
