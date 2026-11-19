---
tipo: correcao
---
## Changelog
- Push Processual (bug de produção de 19/11, OAB 53290/DF sem nenhuma publicação): OAB cadastrada dentro da janela consultada busca os últimos 90 dias (`SincronizarPublicacoesDjenUseCase::periodoDaOab`). Antes, o cadastro não buscava histórico, o "Sincronizar agora" olhava só hoje e a captação diária só 2 dias. Vale nas duas noites seguintes ao cadastro e no "Sincronizar agora" até lá, então uma falha passageira do DJEN ainda tem outra chance.
- O 500 "O sistema está muito ocupado" do DJEN ganha 2 novas tentativas por página, com 3s entre elas, na consulta por OAB. A consulta por número de processo, o 429 e o timeout seguem como estavam.
- "Buscar processos" (`ProcessosDaOabController::buscarDaOab`): se o DJEN seguir ocupado, o aviso diz "Diário Oficial ocupado, tente de novo em alguns minutos" e qual OAB ficou sem resposta.

## Para o cliente
- Ao cadastrar uma OAB no Push Processual, as publicações dos últimos 90 dias passam a chegar, e não só as do dia.
- Quando o Diário Oficial está sobrecarregado, o sistema tenta de novo sozinho. Se ainda assim ele não responder, o botão "Buscar processos" avisa para tentar de novo em alguns minutos.
