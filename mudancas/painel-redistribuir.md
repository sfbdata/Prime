---
tipo: novidade
---
## Changelog
- Painel BlueJus Intelligence, perfil individual: botão "Redistribuir" (aparece quando a pessoa tem meta ativa e está acima da média de metas do escritório). Abre uma janela com as metas ativas da pessoa, todas desmarcadas, com uma sugestão de destino por regra fixa (quem tem menos metas ativas, sem IA e sem avaliação de desempenho) que o gestor pode trocar. Só "Confirmar redistribuição" grava, com no máximo 50 metas por vez. Só o Master e os usuários primários redistribuem. Cada meta ganha um registro no histórico ("Responsável alterado de A para B por C."), a auditoria guarda `meta_redis` e `ia_redis`, e quem recebe e quem entrega recebem um aviso neutro. Sem alteração de banco.

## Para o cliente
- O gestor pode passar metas de uma pessoa para outras direto do perfil dela no Painel. Nada é movido sozinho: ele marca as metas, confere quem recebe cada uma e confirma. Quem recebe e quem entrega são avisados, e a meta guarda no histórico quem mudou o responsável.
