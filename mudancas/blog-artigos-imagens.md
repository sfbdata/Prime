---
tipo: interno
---
## Changelog
- Painel de artigos dos blogs, fatia P3: imagens no corpo (botão de imagem do TinyMCE) e imagem de compartilhamento (capa) no editor. Categoria de arquivo nova `blog_imagem`, global, desligada no espelho do R2; envio em `POST /master/blog/artigos/{id}/imagens` (jpg, png e webp pelo conteúdo, até 5 MB e 10000 px de lado), entrega em `GET /master/blog/imagens/{id}`, limpeza das imagens sem uso ao salvar e ao excluir, com o arquivo removido só depois do COMMIT. A capa guarda o id da imagem do artigo.

## Para o cliente
- No Painel do Master, a equipe do BlueJus passa a pôr imagens nos artigos dos blogs e a escolher a imagem que aparece quando o artigo é compartilhado.
