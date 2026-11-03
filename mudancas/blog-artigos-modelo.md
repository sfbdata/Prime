---
tipo: interno
---
## Changelog
- Domínio novo `Blog` (fatia P1 do painel de artigos dos blogs): entidades `ArtigoDoBlog` e `ImagemDoArtigo` (tabelas `artigo_blog` e `artigo_blog_imagem`, migration `Version20261103100000`), repositórios, catálogo de categorias e autores copiado dos sites, conversor próprio de HTML para Markdown com lista branca e as diretivas do blog, validador com as regras do zod, exportador do `.md` e `SalvarArtigoDoBlogUseCase`. Ainda sem tela.
