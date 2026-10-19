---
tipo: novidade
---
## Changelog
- Agenda: sincronia com o Google Agenda nos dois sentidos, por pessoa (OAuth com PKCE, refresh token cifrado, rotas `/agenda/google/conectar`, `/agenda/google/retorno` e `/agenda/google/desconectar`); sincronia a cada 5 minutos pelo Messenger e logo depois de cada mudança; painel GOOGLE AGENDA com os três estados do desenho e "Desconectar"; comando `app:agenda:google-varredura`; tabelas `agenda_google_conexao`, `agenda_google_exclusao` e `agenda_google_varredura`; colunas `evento.google_id` e `evento.google_hash`.

## Para o cliente
- Agora dá para ligar a Agenda do BlueJus ao seu Google Agenda: o que você cria, edita ou exclui aqui aparece no Google, e o que você marca no Google aparece aqui como compromisso particular ("Só eu"). Os feriados do sistema e os prazos das pastas não são enviados. Para conectar, use "Sincronizar com o Google Agenda" na lateral da Agenda; para parar, "Desconectar".
