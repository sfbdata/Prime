---
tipo: melhoria
---
## Changelog
- Agenda nova: a grade mostra também os prazos e audiências cumpridos das pastas, riscados como concluídos (desenho 09); os lembretes continuam só com os abertos. Método novo `PastaPrazoRepository::doEscritorioNoPeriodo`, já com as pastas ocultas do cadeado.
- Agenda nova: "Avisar no Chat" também no prazo e na audiência de pasta (`POST /agenda/prazos/{id}/avisar`), com o atalho para a pasta; só para quem vê as pastas.

## Para o cliente
- Na Agenda, os prazos já cumpridos continuam aparecendo no dia, riscados, para você ver o que foi feito.
- Agora dá para avisar um colega no Chat sobre um prazo ou uma audiência da pasta, direto da Agenda.
