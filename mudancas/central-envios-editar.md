---
tipo: novidade
---
## Changelog
- Relatório de campanhas: "Editar campanha" abre a campanha no editor da Central em modo edição (conteúdo, data e frequência; público e modelo travados), só antes de começar a sair (`agendada`, `na_fila` antes do expansor, `recorrente` entre execuções); `POST /master/comercial/campanhas/{id}/editar` reconfere sob `FOR UPDATE`, valida como a confirmação e grava a trilha `camp_edit` com `{frequencia, agendada}` (M5.3).
- "Excluir campanha" no menu de uma campanha: se ativa, cancela o envio (`camp_fim`) e a esconde do Relatório; se encerrada, só esconde. Nada é apagado do banco (M5.3).

## Para o cliente
- No Painel do Master, uma campanha agendada ou na fila pode ser editada antes de começar a sair: dá para mudar o texto, a data e a frequência do envio.
- A opção "Excluir campanha" para o envio de uma campanha ativa e a tira do Relatório. As já enviadas só saem da lista; o histórico fica guardado.
