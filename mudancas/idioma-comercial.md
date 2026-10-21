---
tipo: interno
---
## Changelog
- Idioma (spec idioma-do-sistema, entrega por área): o texto de tela da seção Comercial do painel do Master (`master/_comercial*.html.twig` e `master/comercial*.js`) passa para o catálogo `translations/messages+intl-icu.pt_BR.yaml`, no bloco de topo `comercial:` (259 chaves: 180 do Twig e 79 do JS em `comercial.js.*`). Só o português, sem mudança visível.
- O `_comercial.html.twig` entrega `{{ catalogo_js('comercial.js') }}` e carrega o `bj-traducao.js` antes dos scripts da seção, que rodam no meio do corpo, antes do script do base. O `bj-traducao.js` ignora a segunda carga.
- A área `comercial` entra no `tests/Arquitetura/templates-extraidos.json`, e texto fixo novo nesses arquivos é barrado.
- Formato: a hora de hoje no Relatório usa o `BJFormato.hora`, com a mesma saída.
- Ficam para o PR seguinte: o texto que vem do PHP (erros e mensagens em JSON) e os e-mails.

## Para o cliente
- Nada muda na tela. É preparação para o sistema em outros idiomas.
