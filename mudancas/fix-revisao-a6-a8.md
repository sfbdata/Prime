---
tipo: correcao
---
## Changelog
- Achados da revisão dos PRs #815 (A6) e #812 (A8), já no master. Tela "Selecione um escritório" (`tenant-selecionar.css`): no tema escuro o perfil (`.sel-conta-texto > span`) ficava cinza sobre o azul do hover; agora fica branco no hover e no foco. O foco do teclado ganha contorno sólido #0b5f86 por fora (o anel translúcido sumia sobre o azul). Chat I.A. (`chat-ia.css`, `chat_ia/pagina.html.twig`): o texto do estado "perto do limite" da barra da I.A. gratuita volta ao âmbar, com o token novo `--ci-aviso` (#7a5a0c no claro, #e3b341 no escuro).

## Para o cliente
- Na tela "Selecione um escritório", com o tema escuro, o seu perfil embaixo do nome do escritório agora aparece em branco quando a linha fica azul, em vez de quase sumir.
- Quem navega pelo teclado vê um contorno mais forte em volta do escritório selecionado.
- No Chat I.A., quando a I.A. gratuita está perto do fim, o aviso aparece de novo em amarelo escuro, legível no tema claro e no escuro.
