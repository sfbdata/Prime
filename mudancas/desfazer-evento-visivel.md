---
tipo: correcao
---
## Changelog
- Auditoria de segurança (continuação do 3.02/3.03): desfazer pelo registro de auditoria num compromisso da agenda passa a exigir também ver o compromisso (`EventoDaAgendaRepository::visivelPara`, na `GuardaDaTelaNoDesfazer`). "Só eu" de outra pessoa e reunião "Para Todos" com participantes de que o ator não faz parte são recusados, até para o administrador e o Master; o botão de desfazer some para eles.

## Para o cliente
- Na auditoria, só quem enxerga um compromisso da agenda pode desfazer uma alteração nele. Um administrador que não foi convidado para uma reunião não consegue mais desfazer mudanças dela pelo histórico.
