---
tipo: correcao
---
## Changelog
- Auditoria 3.01 (ajuste da revisão do #635): o `AuditLogSubscriber` grava também o escritório dono do registro do próprio escritório (`Tenant`) e do vínculo (`UserTenant`), não o da sessão. Sem escritório na sessão (comando, worker), segue sem escritório.
- Teste novo: o Master altera os dados do escritório na edição legada `/tenant/{id}/edit`.

## Para o cliente
- Histórico de alterações: mudanças nos dados de um escritório e nos vínculos de usuário passam a aparecer só no histórico do próprio escritório, mesmo quando feitas por quem atende mais de um escritório.
