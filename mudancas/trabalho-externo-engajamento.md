---
tipo: novidade
---
## Changelog
- Trabalho Externo: visão de engajamento (F4, spec §4.7). `GET /trabalho-externo/api/engajamento` devolve os selos das abas, o banner (saudação pelo horário de Brasília, subtítulo e chips do interno e do externo), o estado do aviso de vaga nova, o lembrete diário do externo (cadastrar, completar, revisar ou em dia), as boas-vindas de quem chega pelo link de vagas (`?vagas=` e `?cadastro=`), o `motiv` e a situação das ofertas acompanhadas (`?ofertas=`, só das vagas já publicadas). Aceita `?como=` da prévia do Master, só para leitura.
- Aviso de vaga nova por usuário no servidor: `POST /trabalho-externo/api/vagas/alerta` `{ligado}`, com CSRF, só para o externo e só com o sino ligado pelo Master. A escolha guarda o escritório do contexto (`PreferenciaDoUsuario` `vagas.alerta`).
- Avisos no sino da Central (`AvisosDoTrabalhoExterno`, tipos `trabalho_externo_*` em `Notificacao` e `TiposDeAvisoDaCentral`): pedido de divulgação novo ou reenviado e candidatura para a Central (Master e Equipe com `talentos`, na sede); aceite em vaga de escritório, candidatos enviados, vaga aprovada e não aprovada (com o motivo) para o dono; mudança de andamento para o candidato, no escritório gravado na candidatura; vaga nova, reaberta ou ofertada de novo para quem ligou o aviso, com área em comum; "Solicitar informações" numa demanda para quem solicitou. Sem sede ou sem escritório, nenhum aviso; o aviso não lido do mesmo link não se repete; o título nunca leva nome nem contato de candidato.
- Banco de currículos ordenado pela última atualização ("Currículos atualizados aparecem primeiro"); a coluna RECEBIDO e "Novos na semana" seguem pela data de recebimento.
- Rotas novas: `trabalho_externo_engajamento`, `trabalho_externo_vagas_alerta`. Migration `Version20261019235800`: coluna `escritorio_do_candidato` na candidatura (FK para o escritório, SET NULL). A tela ganha `data-url-engajamento`, `data-url-vagas-alerta` e `data-eu-id`.

## Para o cliente
- A Central de vagas agora cumprimenta você e mostra o que mudou desde a última visita: pedidos para aprovar, ofertas ao vivo, currículos novos e vagas da sua área. Quem procura vaga pode ligar o aviso e recebe no sino quando sai uma vaga da área dele, e acompanha no sino cada passo da candidatura.
