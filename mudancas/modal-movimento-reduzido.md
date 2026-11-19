---
tipo: correcao
---
## Changelog
- Bug de produção de 19/11: com o Windows em "animações desligadas" (`prefers-reduced-motion: reduce`), o `adminlte.min.css` 4.0.0-rc3 põe `.fade{display:block}` e isso vence o `.modal{display:none}`. Toda janela `.modal.fade` fechada (por exemplo `#modalInstalarIOS`, no `base.html.twig`) ficava na tela, invisível e por cima de tudo, sem deixar clicar. O `app.css`, carregado depois do AdminLTE em `base.html.twig` e `layout_peticionar.html.twig`, devolve `display:none` à `.modal.fade:not(.show)` nessa mídia. Os outros `.fade` (aba, alerta, aviso) e o `.collapse{display:block}` da mesma regra não sofrem, porque as regras do Bootstrap para eles são mais fortes.

## Para o cliente
- Quem usa o Windows com as animações desligadas voltou a conseguir clicar no sistema. Antes, uma janela invisível cobria a tela inteira.
