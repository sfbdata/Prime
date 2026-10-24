---
tipo: novidade
---
## Changelog
- BlueJus IA: comando "Ler N documentos" na aba Documentos do drawer da pasta (`ler_documentos`, versão `agente-leitura-v1`). PDF e imagem (até 3 por vez; PDF até 10 MB e 100 páginas, imagem até 3 MB, 20 MB juntos) vão inline ao provedor como blocos `document`/`image` em base64, com o tipo medido pelo conteúdo; sem Files API e sem OCR próprio. O worker relê cada documento por id e escritório, fora da lixeira e da aba oculta; pasta em segredo nunca envia; `contexto_resumo` guarda só id e sha256. O texto do aceite passa a citar os documentos (`TextoDoAceiteDaIa`): aceite anterior bloqueia só a leitura até o administrador salvar a configuração com a IA ligada. Sem migration.

## Para o cliente
- A BlueJus IA agora lê os PDFs e imagens que você marcar na aba Documentos da pasta (até 3 por vez) e aponta tipo, datas, partes, valores, prazos e providências. Para liberar, o administrador renova o aceite na configuração da BlueJus IA.
