---
tipo: novidade
---
## Changelog
- Agenda: sincronia com o Google Agenda nos dois sentidos, numa agenda com o nome do escritório criada na conta Google de cada pessoa (escopo `calendar.app.created`: a agenda principal não é lida nem escrita). OAuth com PKCE, refresh token cifrado, e-mail da conta pelo `id_token`, o do cadastro só sugerido; rotas `/agenda/google/conectar`, `/agenda/google/retorno` e `/agenda/google/desconectar`; sincronia a cada 5 minutos pelo Messenger e logo depois de cada mudança; comando `app:agenda:google-varredura`.
- O compromisso vai também à agenda do escritório de cada participante conectado (cópia só de leitura); criado no Google entra visível à equipe, com os convidados da equipe como participantes; cancelado no Google fica aqui como "cancelado". A mesma conta Google pode ser ligada em vários escritórios.
- Desconectar pergunta se apaga também a agenda do escritório no Google ("Não, manter" marcado) e revoga a autorização quando a conta não está ligada em outro escritório.
- Tabelas `agenda_google_conexao`, `agenda_google_ligacao`, `agenda_google_exclusao` e `agenda_google_varredura`; coluna `evento.importado_do_google` ("Importado do Google Agenda." no detalhe).

## Para o cliente
- Agora dá para ligar a Agenda do BlueJus ao seu Google Agenda: o BlueJus cria no seu Google uma agenda com o nome do escritório, e o que você cria, edita ou exclui aqui aparece lá, e vice-versa. A sua agenda pessoal do Google não é lida. Os compromissos em que você é participante também aparecem lá, só para consulta. Para conectar, use "Sincronizar com o Google Agenda" na lateral da Agenda; para parar, "Desconectar".
