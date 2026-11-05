---
tipo: correcao
---
## Changelog
- Cadeado da pasta e permissão de ver a pasta em pasta, processo e relatórios (auditoria de segurança, lote B: 1.02, 1.03, 3.04, 3.05, 3.12, 2.01):
  - 1.02: o relatório de pastas paradas (tela e PDF) tira as pastas ocultas pelo cadeado (`AcessoAPasta::idsOcultosPara`), também para quem vê todas as pastas ou é da Controladoria. O envio automático não muda.
  - 1.03: a aba Tarefas do processo não mostra metas de pasta oculta. `TarefaRepository::findByProcesso(Processo, Tenant, pastasOcultas)` com tenant explícito.
  - 3.04: vincular processo (por id, por CNJ já cadastrado, apensado e pelo Push) recusa o processo cujas pastas são todas ocultas com "Processo não encontrado." (`ProcessoOcultoPeloCadeadoException`), e a busca para vincular não o lista. No Push, "trocar a pasta" vincula antes de desvincular, na mesma transação.
  - 3.05: o relatório de registros do Dashboard só mostra o trecho do texto de quem pode ver a pasta (`AcessoAPasta::podeVer`, uma vez por pasta), em todas as abas; total e contagem iguais.
  - 3.12: `AcessoAMeta` exige cuidar da meta ou poder ver a pasta; nos demais casos, 404 (show, despacho, responsáveis, anexos, duplicar). Decisão registrada em `docs/AUTORIZACAO.md`.
  - 2.01: as buscas de clientes e de processos da pasta exigem EDIT (como o vincular), 403 na pasta excluída e 404 para outro escritório.

## Para o cliente
- Quem está fora do cadeado de uma pasta não vê mais essa pasta no relatório de pastas paradas, nem as metas dela na aba Tarefas do processo, e não consegue vincular a outra pasta um processo que só existe nas pastas trancadas.
- Quem não pode abrir uma pasta não lê mais, no relatório do Dashboard, o começo do texto dos registros dela, e não abre as metas dela pelo número, a menos que seja responsável ou criador da meta.
- A busca de clientes e de processos dentro da pasta fica só para quem pode editar a pasta, que é quem pode vincular.
