---
tipo: novidade
---
## Changelog
- Editor de artigos dos blogs (Painel do Master): botões Revisar texto, Localizar e substituir (Ctrl+H, plugin searchreplace do TinyMCE), Diminuir/Aumentar recuo (só dentro de lista, gera sublista `ul`/`ol` dentro do `li`) e Inserir símbolo, com os mesmos ícones, dicas e textos do editor da pasta.
- A parte pura da revisão (dicionários, símbolos, correção, sugestão e as regras de ortografia, palavra repetida e espaço duplo) saiu do `editor-rico.js` para o módulo comum `public/js/revisao-de-texto.js`, carregado antes dos dois editores; o comportamento do editor da pasta não muda.
- Teste de ida e volta da sublista: a lista branca do artigo mantém `ul`/`ol` dentro do `li` e o conversor gera o Markdown recuado.

## Para o cliente
- No Painel do Master, o editor de artigos dos blogs ganhou a revisão de texto do editor da pasta: ela aponta erros de digitação, palavras repetidas e espaços duplos, e corrige com um clique (cada correção pode ser desfeita).
- O mesmo editor agora localiza e substitui palavras (Ctrl+H), cria sublistas com os botões de recuo e insere símbolos como §, º e R$.
