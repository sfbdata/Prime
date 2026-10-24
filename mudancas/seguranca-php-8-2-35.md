---
tipo: correcao
---
## Changelog
- Infra: imagem base fixada em `php:8.2.35-fpm` (correções de segurança do PHP; o deploy não faz `--pull` e a tag flutuante podia reusar a 8.2.34 do cache) e `session.upload_progress.enabled = Off` em `conf.d/seguranca.ini`, conforme o aviso de segurança do PHP de 23/10/2026. Sem mudança de comportamento no app.
