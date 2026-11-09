---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, E9 (trilha de auditoria encadeada, P64): tabela `ponto_auditoria` (migration `Version20261109190000`), uma cadeia por escritório com sequência sem buraco (`pg_advisory_xact_lock(4203, escritório)` e índice único), cada evento selado com HMAC-SHA256 junto com o último selo da cadeia. A chave é derivada de `PONTO_HMAC_SECRET` (`ChavesDoHmacDoPonto`, finalidade `ponto-trilha`, versão gravada em cada linha); sem o segredo, os eventos entram sem selo e nada falha.
- Entram na trilha, no mesmo COMMIT do fato: batida, justificativa enviada, editada e excluída pelo colaborador, abonada, recusada, voltou para análise, abono em lote, lançada já abonada pelo gestor (com a batida de cada esquecimento lançado), batida do esquecimento abonado e escala alterada. Sede, feriado, limites, prazos, modo facial, foto e a chave Global do Master continuam só no `audit_log`.
- Aba "Auditoria" na /ponto para quem gere o ponto do escritório (`GestaoDoPonto::veAuditoria`, `admin.ponto.manage`; primário e Master passam): eventos do mais recente ao mais antigo, 300 por página, com a conferência da cadeia no cabeçalho: íntegra, alterada (com o número do primeiro evento que não confere), trecho sem selo (as faixas de eventos gravados sem selo, na cor de alerta) ou sem selo (servidor sem o segredo).
- A purga do escritório apaga a trilha dele (`PurgarEscritorioUseCase`).
- Produção: criar `PONTO_HMAC_SECRET` no `.env.prod` para os eventos saírem selados.

## Para o cliente
- O Ponto Eletrônico ganhou a aba "Auditoria", para quem gere o ponto do escritório: cada batida, justificativa e análise passa a ficar registrada numa trilha selada, com data, hora e autor, e a tela avisa quando um evento registrado foi alterado.
