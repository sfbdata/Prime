---
tipo: interno
---
## Changelog
- Painel de artigos dos blogs, fatias P2 e P7: seção "Artigos dos blogs" no Painel do Master (lista com busca, filtros, duplicar e excluir), editor próprio em `/master/blog/artigos/{id}` com TinyMCE restrito e os blocos do blog (caixa de atenção, etapas, checklist, chamada para criar conta, leia também), SEO com contadores e prévia do Google, verificar com as regras do zod e prévia aproximada.
- Marcadores nos artigos (P7): coluna `marcadores` (jsonb) em `artigo_blog` (migration `Version20261103140000`), até 8 por artigo e 40 caracteres cada, sugestões dos já usados no editor e chave `marcadores` no frontmatter exportado.
- Comando `blog:importar-do-repositorio <pasta-dos-sites>` (com `--simular`) para trazer uma vez os artigos que hoje estão no repositório dos sites.
- Ainda sem publicar, agendar e imagens (fatias P3, P4 e P5).

## Para o cliente
- No Painel do Master, a equipe do BlueJus passa a escrever, revisar e organizar os artigos do Blog BlueJus e do Blog BlueJus Grátis, com etiquetas por assunto para facilitar a busca dos textos.
