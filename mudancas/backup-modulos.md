---
tipo: novidade
---
## Changelog
- Backup: o ZIP passa a levar também os processos (`dados/processos.json`), as tarefas (`dados/tarefas.json` e os anexos em `tarefas/`), os quadros do Kanban (`dados/kanban.json` e os anexos em `kanban/`) e a agenda (`dados/agenda.json`). Cada módulo só entra para quem tem acesso a ele e só com o que a pessoa vê na tela: processos e metas de pasta oculta ficam de fora, o quadro só vai para quem criou ou participa, e o compromisso privado de outra pessoa não entra. Nada do Google Agenda (tokens, conexão ou ids externos) vai no arquivo.

## Para o cliente
- O backup agora traz também os seus processos (com partes, movimentações e notas), as suas tarefas com os anexos, os quadros do Kanban de que você participa e a sua agenda com compromissos e prazos. Só entra o que você já consegue ver no sistema: o que está escondido de você, ou é de outro escritório, não aparece no arquivo.
