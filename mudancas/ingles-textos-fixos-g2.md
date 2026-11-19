---
tipo: alteracao
---
## Changelog
- Inglês: textos que ainda estavam fixos em português na Pasta e no Push passam ao catálogo, com a versão em inglês (`comum.js.*` para o editor de texto, o visualizador de documentos, o gerenciador de arquivos e o Push; `pasta_push.*`, `pasta_show.js.*` e `pasta_avisos.*`). A página pública do link externo do Push passa a carregar o `bj-traducao.js`. O português não muda. Os arquivos entram na guarda `templates-extraidos.json` (área nova `pasta_modelos`). A palavra de confirmação para excluir no Push vem do catálogo (EXCLUIR em português, DELETE em inglês). Testes que liam o texto literal no JS passam a conferir a chave e o português do catálogo (`tests/Support/TextoDoCatalogo.php`).

## Para o cliente
- Quem usa o sistema em inglês passa a ver em inglês o editor de texto (barra, menu do botão direito, localizar e substituir), o visualizador de documentos, o gerenciador de arquivos da pasta, a página do link do Push e os avisos da pasta. Em português tudo continua igual.
