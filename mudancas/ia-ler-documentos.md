---
tipo: novidade
---
## Changelog
- BlueJus IA: comando "Ler N documentos" na aba Documentos do drawer da pasta (`ler_documentos`, versão `agente-leitura-v1`). PDF e imagem (até 3 por vez; PDF até 10 MB, imagem até 3 MB, 20 MB juntos, 100 páginas somadas) vão inline ao provedor como blocos `document`/`image` em base64, com o tipo medido pelo conteúdo; sem Files API e sem OCR próprio; timeout de 300 s e `max_tokens` 8000 só nessa leitura. O worker relê cada documento por id e escritório, fora da lixeira, reconfere o acesso de quem pediu e as abas ocultas de agora, e conta as páginas que o upload não contou; falha de I/O do armazenamento volta à fila. Pasta em segredo nunca envia; `contexto_resumo` guarda só id e sha256. Versão do texto do aceite em `inteligencia_configuracao.consentimento_versao` (migration aditiva `Version20261024150400`, linhas atuais na 1): só a versão 2, que cita os documentos, libera a leitura; o administrador renova salvando a configuração com a IA ligada.

## Para o cliente
- A BlueJus IA agora lê os PDFs e imagens que você marcar na aba Documentos da pasta (até 3 por vez) e aponta tipo, datas, partes, valores, prazos e providências. Para liberar, o administrador renova o aceite na configuração da BlueJus IA.
