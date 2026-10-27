---
tipo: alteracao
---
## Changelog
- Agenda: a tela de `/agenda` passa a seguir o desenho "Agenda 1A": barra com Hoje, setas, busca e Mês/Semana/Lista; coluna direita com Detalhe do evento, Hoje e A seguir, Legendas e Filtros (Pessoa, Status, Mostrar cancelados). Seis tipos de evento (Audiência, Prazo, Reunião, Urgente, Pessoal e Lembrete) na janela de criar e editar.
- A tela antiga continua disponível em `/agenda/classica` até a nova ser aprovada. Nenhum backend foi apagado; a ligação com o Google Agenda segue num cartão no fim da coluna direita.
- Perdas declaradas: a alternância "Minha agenda / Equipe" (o filtro Pessoa cobre "só eu" e "todo o escritório") e os cartões de prazos de hoje, amanhã e 7 dias. Feriados e aniversários não aparecem mais na grade. A engrenagem "Gerenciar legendas" aparece sem ação (não há cadastro de legendas). Sem migration.

## Para o cliente
- A Agenda ganhou um visual novo, com a lista do dia e o detalhe do evento ao lado do calendário, e filtros por pessoa e por situação.
