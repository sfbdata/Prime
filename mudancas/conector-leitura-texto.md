---
tipo: novidade
---
## Changelog
- Conector de I.A. (PR 7b da spec mcp-remoto-oauth, 6.5): a ação `ler` da `ler_documentos_e_modelos` passa a devolver como texto a peça do editor, DOCX, DOC, ODT, RTF, TXT, CSV, XML e KML, no `dados.texto`, neutralizado linha a linha e sem máscara (6.2.6), com `cortado` e `aviso`. PDF e imagem seguem como arquivo. O texto começa nos 60 mil caracteres da spec e encolhe até a resposta caber no teto de 45 mil do JSON (6.2, regra 7).
- `LeitorDeDocumentosDaPasta::textos`, com as mesmas conferências do `anexos` (pedido, pasta, escritório, lixeira, abas e aceite), o formato gravado conferido pelo conteúdo e os tetos de bytes do upload.
- `ExtratorDeTextoDocx` sai do Chat I.A. para `Shared/Texto`, com a exceção neutra `ArquivoSemTextoException`; o Chat I.A. converte e segue igual. Extrator comum novo (`ExtratorDeTexto`) com leitores próprios de DOC (Word 97-2003), ODT e RTF, com a proteção de pacote, sem DOCTYPE nem entidade; XML e KML lidos como texto, nunca interpretados.

## Para o cliente
- O assistente de I.A. ligado ao BlueJus (Claude ou ChatGPT) passa a ler, além de PDF e imagem, as peças escritas no editor do BlueJus e os documentos de Word (DOCX e DOC), LibreOffice (ODT), RTF, texto, planilha CSV, XML e mapas KML da pasta. Documento muito longo vem só com o começo, e o assistente avisa. As mesmas regras de acesso continuam valendo: lixeira, aba Documentos oculta e escritório sem o aceite do conector não deixam nada sair.
