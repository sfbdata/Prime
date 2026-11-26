---
tipo: novidade
---
## Changelog
- Artigos dos blogs (Painel do Master): ação "Agendar" na lista e no editor do rascunho, com janela de data e hora (horário de Brasília, só futuro) e as mesmas verificações de Publicar; "Cancelar agendamento" devolve o artigo a rascunho. A lista mostra a situação "Agendado" e "Agendado para dd/mm/aaaa hh:mm".
- Nova tarefa agendada `blog:publicar-agendados` (a cada 5 minutos, no scheduler): publica os artigos agendados cuja hora chegou, um por vez, pelo mesmo caminho de Publicar (motivo "Artigo agendado"). Artigo que falha volta a rascunho, entra no log e o aviso de falha do agendador chega ao Master.

## Para o cliente
- O Master agora pode agendar a publicação de um artigo dos blogs para um dia e uma hora, em vez de publicar na hora. O artigo entra no ar sozinho, alguns minutos depois do horário marcado, e o agendamento pode ser cancelado antes disso.
