---
tipo: alteracao
---
## Changelog
- A opção "Animações" do Guia visual (reduzidas) passa a valer no sistema inteiro: o `base.html.twig` põe `jp-sem-anim` no `<html>` pela preferência do usuário (`animacoes_reduzidas()`), e o `app.css` desliga transições e animações por ela.
- Quem pede movimento reduzido no sistema operacional (`prefers-reduced-motion`) ganha o mesmo em todas as telas.
- Trocar a opção no Dashboard aplica na hora, sem recarregar.

## Para o cliente
- Ao escolher "Animações reduzidas" no Guia visual do Dashboard, o sistema inteiro fica sem animações, não só o Dashboard.
