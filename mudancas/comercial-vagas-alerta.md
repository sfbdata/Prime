---
tipo: novidade
---
## Changelog
- Central de envios (decisão do dono 21/10, F3.3 (a), spec comercial-e-emails §11): com o modelo `vagas` ("Ofertas · vagas abertas"), o público "Base de contatos" soma os confirmados de `comercial_campanhas` e de `vagas_alerta`, sem repetidos (quem está nas duas sai por `comercial_campanhas`). Os outros modelos, inclusive as cópias `cu<id>`, continuam só com `comercial_campanhas`.
- Cada destino leva a lista pela qual entrou: coluna `lista` em `comercial_campanha_destino` (migration `Version20261020149600`, padrão `comercial_campanhas`). Ela decide o link de descadastro, o List-Unsubscribe e a checagem do Carteiro. No modelo `vagas`, quem saiu de qualquer uma das duas listas fica de fora.
- Âncora no servidor: o modelo `vagas` só é aceito no modo simples, com o botão para as vagas. A trava vale na confirmação, no envio de teste e na execução (uma campanha antiga sem a âncora não alcança a lista de vagas).
- A contagem recebe o modelo; a tela reconta ao trocar de modelo.

## Para o cliente
- As campanhas de vagas do BlueJus também passam a chegar a quem pediu os alertas de vagas por e-mail. Quem não quiser mais é só clicar no link de descadastro do próprio e-mail.
