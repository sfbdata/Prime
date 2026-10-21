---
tipo: novidade
---
## Changelog
- Pasta, aba Documentos: cobrança automática do checklist (desenho 02). Todo dia útil o responsável é cobrado dos documentos pendentes, com no máximo 2 avisos por pasta por dia; procuração e contrato são urgentes; o tom sobe a cada aviso e a controladoria acompanha a partir do 4º.
- Item marcado como entregue sem o documento anexado avisa quem marcou e a controladoria (auditoria).
- Desativar o checklist avisa a controladoria; desativado há 30 dias, o responsável recebe o lembrete de revisar.
- Selo "cobrado Nx" nos itens e a pendência da aba conta os documentos cobrados e ainda não anexados.
- Rotina diária `app:pasta:cobrar-checklist` (cron no `DEPLOY.md`).

## Para o cliente
- O checklist de documentos da pasta agora cobra sozinho o responsável pelos documentos que faltam, todo dia útil, até que sejam anexados.
