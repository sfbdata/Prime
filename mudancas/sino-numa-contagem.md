---
tipo: alteracao
---
## Changelog
- Sino do topo: o selo e os números das abas "Minhas" e "Gestão" saem de uma consulta só (`NotificacaoService::contarNaoLidasDoSino()` e `NotificacaoRepository::contarNaoLidasPorCategoria()`, com `COUNT` e `SUM(CASE WHEN ...)` pela lista `Notificacao::TIPOS_GESTAO`), no lugar das três contagens que o `NotificacaoExtension` fazia em toda página. Os filtros são os mesmos de antes: escritório do sino (incluindo o caso sem escritório escolhido), cadeado, cortes da leitura da pasta e silêncio do sininho.
- Medido: 2 consultas a menos em toda página com o layout, para primário, colaborador e Master (Meu Perfil: 44 para 42 no primário, 46 para 44 no colaborador). O `/notificacoes/count` e o `/notificacoes/lista-dropdown` já faziam uma contagem só e não mudaram.
- Testes novos: os números da consulta nova são iguais aos das três contagens antigas (primário, colaborador fora do cadeado, sem leitura da pasta, sininho em silêncio, sem escritório escolhido, avisos lidos e não lidos, outro escritório) e uma trava do número de consultas do sino no layout e no `/notificacoes/count`.

## Para o cliente
- As telas do sistema abrem um pouco mais rápido: o sininho do topo agora confere os avisos não lidos de uma vez só, em vez de três. Os números do sininho e das abas continuam exatamente os mesmos.
