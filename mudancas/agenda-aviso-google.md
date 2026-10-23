---
tipo: novidade
---
## Changelog
- Agenda: janela que orienta a conectar o Google Agenda ao abrir a tela, para quem ainda não conectou, com "Agora não", "Sincronizar agora" (mesmo fluxo do botão do painel) e a caixa "Não mostrar esta mensagem novamente". A dispensa fica em `preferencia_usuario` (chave `agenda.google_aviso_dispensado`, por escritório e usuário), gravada por `POST /agenda/google/aviso/dispensar` com CSRF. Não abre com o servidor sem as credenciais do Google. Sem migration.

## Para o cliente
- Ao abrir a Agenda, o BlueJus lembra que dá para ligar a sua conta Google e receber os lembretes no celular. Se preferir, marque "Não mostrar esta mensagem novamente".
