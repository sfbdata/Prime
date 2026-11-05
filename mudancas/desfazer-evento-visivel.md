---
tipo: correcao
---
## Changelog
- Auditoria de segurança (continuação do 3.02/3.03): desfazer pelo registro de auditoria num compromisso da agenda passa a exigir também o módulo agenda e ver o compromisso (`EventoDaAgendaRepository::visivelPara`, na `GuardaDaTelaNoDesfazer`), as barreiras do `agenda_editar`. "Só eu" de outra pessoa e reunião "Para Todos" com participantes de que o ator não faz parte são recusados, até para o administrador e o Master; o botão de desfazer some para eles.

## Para o cliente
- Na auditoria, só quem usa a agenda e enxerga o compromisso pode desfazer uma alteração nele. Um administrador que não foi convidado para uma reunião não consegue mais desfazer mudanças dela pelo histórico.
