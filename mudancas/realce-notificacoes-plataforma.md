---
tipo: alteracao
---
## Changelog
- Realce da linha do "Guia visual BlueJus" v30 (pedido A2 de 19/11) na lista de notificações (`notificacao/index.html.twig`, `.notif-row`, lida e não lida) e na tabela da plataforma (`admin/platform/_base_platform.html.twig`, `.platform-table`): fundo #2b7ab0 e texto branco; botões (`.btn`, `.bjp-btn`, `.bjp-ico`), menus (`.dropdown-menu`), selos (`.badge`) e o ícone da notificação mantêm a cor própria. Cantos (A1): etiqueta da notificação, selo de contagem e filtros da plataforma e o selo Principal/Suplementar da revisão de OAB de pílula para raio 4px. Teste `RealceDaLinhaV30Test`.

## Para o cliente
- Na página de notificações, ao passar o mouse numa notificação, a linha fica azul com o texto branco, como nas outras listas do sistema. As etiquetas "nova" e "lida" ficaram retangulares.
- Na área de administração da plataforma, as linhas das tabelas ganham o mesmo realce azul, e os filtros e selos ficaram retangulares.
