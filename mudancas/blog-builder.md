---
tipo: interno
---
## Changelog
- Painel de artigos dos blogs, fatia P4 (lado do BlueJus): Publicar agora e Despublicar no editor, `PublicacaoDosBlogs` (tabela `publicacao_blogs`, migration `Version20261103160000`), pacote montado pelo worker no formato da spec 6.1 em `%publicacao_blogs_dir%` (todos os artigos publicados, imagens, `canonical` na cópia do Grátis de artigo nos dois blogs), comando `blog:conferir-publicacoes` a cada minuto na agenda, comando `blog:gerar-de-novo` e a faixa da última publicação com o histórico na lista.

## Para o cliente
- No Painel do Master, a equipe do BlueJus passa a publicar e tirar do ar os artigos dos blogs com um clique e acompanha se cada publicação já está no ar.
