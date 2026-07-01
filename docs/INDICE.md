# Índice de Documentação — JusPrime / BlueJus

Mapa de todos os arquivos `.md` do projeto (exclui `vendor/` e `node_modules/`,
que são dependências de terceiros). Links são relativos a esta pasta (`docs/`).

_Atualizado em 2026-07-01._

## Raiz do projeto
- [CLAUDE.md](../CLAUDE.md) — contexto geral (arquitetura, workflow, Docker, git)
- [DASHBOARD.md](../DASHBOARD.md)
- [DEPLOY.md](../DEPLOY.md)
- [FASE2-ARQUIVOS-DRIVE.md](../FASE2-ARQUIVOS-DRIVE.md)
- [IMPORTACAO-ACERVO.md](../IMPORTACAO-ACERVO.md)
- [PENDENCIAS.md](../PENDENCIAS.md)
- [REFATORACAO-DOMINIOS.md](../REFATORACAO-DOMINIOS.md)
- [SETUP.md](../SETUP.md)

## Documentação (`docs/`)
- [AUTORIZACAO.md](AUTORIZACAO.md) — modelo de autorização (4 camadas, bypasses, falhas)
- [checklist-review.md](checklist-review.md)
- [refatoracao-identidade-global.md](refatoracao-identidade-global.md)

### Etapas de refatoração (`docs/etapas/`)
- [etapas/5b1-domain-layer.md](etapas/5b1-domain-layer.md)
- [etapas/5c-levantamento.md](etapas/5c-levantamento.md)
- [etapas/5c-levantamento-2026-05-13.md](etapas/5c-levantamento-2026-05-13.md)
- [etapas/5c.3e.7-fixtures-levantamento.md](etapas/5c.3e.7-fixtures-levantamento.md)

### Planos (`docs/superpowers/`)
- [superpowers/plans/2026-06-29-b5-frente1-listener-trava-tenant.md](superpowers/plans/2026-06-29-b5-frente1-listener-trava-tenant.md)

## Specs de features (`docs/specs/`)

### Isolamento multi-tenant
- [specs/isolamento-tenant-sistemico.md](specs/isolamento-tenant-sistemico.md)
- [specs/access-request-isolamento-tenant.md](specs/access-request-isolamento-tenant.md)
- [specs/agenda-isolamento-tenant.md](specs/agenda-isolamento-tenant.md)
- [specs/cliente-isolamento-tenant.md](specs/cliente-isolamento-tenant.md)
- [specs/cliente-cpf-cnpj-por-tenant.md](specs/cliente-cpf-cnpj-por-tenant.md)
- [specs/demitir-funcionario-cross-tenant.md](specs/demitir-funcionario-cross-tenant.md)
- [specs/notificacao-isolamento-tenant.md](specs/notificacao-isolamento-tenant.md)
- [specs/pasta-expediente-isolamento-tenant.md](specs/pasta-expediente-isolamento-tenant.md)
- [specs/peca-imagem-isolamento-tenant.md](specs/peca-imagem-isolamento-tenant.md)
- [specs/ponto-isolamento-tenant.md](specs/ponto-isolamento-tenant.md)
- [specs/ponto-admin-escopo-tenant.md](specs/ponto-admin-escopo-tenant.md)
- [specs/processo-isolamento-tenant.md](specs/processo-isolamento-tenant.md)
- [specs/profile-isolamento-tenant.md](specs/profile-isolamento-tenant.md)
- [specs/resource-access-isolamento-tenant.md](specs/resource-access-isolamento-tenant.md)
- [specs/servicedesk-isolamento-tenant.md](specs/servicedesk-isolamento-tenant.md)
- [specs/super-admin-escopo-tenant.md](specs/super-admin-escopo-tenant.md)
- [specs/tarefa-isolamento-tenant.md](specs/tarefa-isolamento-tenant.md)

### Auditoria e remediação
- [specs/auditoria-multitenant.md](specs/auditoria-multitenant.md)
- [specs/auditoria-pos-remediacao-multitenant.md](specs/auditoria-pos-remediacao-multitenant.md)
- [specs/followups-seguranca-residual.md](specs/followups-seguranca-residual.md)
- [specs/DEPLOY-PROD-multitenant.md](specs/DEPLOY-PROD-multitenant.md)
- [specs/PROGRESSO-PENDENCIAS.md](specs/PROGRESSO-PENDENCIAS.md)

### Segurança geral
- [specs/csrf-ajax-endpoints.md](specs/csrf-ajax-endpoints.md)
- [specs/servicedesk-anexo-download-seguro.md](specs/servicedesk-anexo-download-seguro.md)
- [specs/uploads-fora-do-public.md](specs/uploads-fora-do-public.md)

### Features de domínio
- [specs/aceite-termos-de-uso.md](specs/aceite-termos-de-uso.md)
- [specs/compressao-upload-arquivos.md](specs/compressao-upload-arquivos.md)
- [specs/notificacoes-link-justificativa-ponto.md](specs/notificacoes-link-justificativa-ponto.md)
- [specs/ponto-tipos-dispensa-abonada-sistema-indisponivel.md](specs/ponto-tipos-dispensa-abonada-sistema-indisponivel.md)
- [specs/purga-quarentena-e-cadastros.md](specs/purga-quarentena-e-cadastros.md)
- [specs/self-service-escritorios.md](specs/self-service-escritorios.md)
- [specs/servicedesk-migracao-dominio.md](specs/servicedesk-migracao-dominio.md)
- [specs/servicedesk-notificacao-novo-chamado.md](specs/servicedesk-notificacao-novo-chamado.md)
- [specs/sincronizacao-drive-bidirecional.md](specs/sincronizacao-drive-bidirecional.md)
- [specs/tema-escuro.md](specs/tema-escuro.md)
- [specs/validacao-oab.md](specs/validacao-oab.md)
- [specs/validacao-oab-plano-fase1.md](specs/validacao-oab-plano-fase1.md)

## Documentação da aplicação (`app/docs/`)
- [REST_ROUTES.md](../app/docs/REST_ROUTES.md)
- [permissions-catalog.md](../app/docs/permissions-catalog.md)

## Instruções por camada (CLAUDE.md)
- [app/src/CLAUDE.md](../app/src/CLAUDE.md) — layout de domínios, legado, regras transversais
- [app/src/Controller/CLAUDE.md](../app/src/Controller/CLAUDE.md) — padrões de controller, rotas, permissões
- [app/src/Entity/CLAUDE.md](../app/src/Entity/CLAUDE.md) — entidades Doctrine, UUID, multi-tenant, enums
- [app/src/Repository/CLAUDE.md](../app/src/Repository/CLAUDE.md) — filtro de tenant, paginação, DTOs via DQL
- [app/src/Shared/CLAUDE.md](../app/src/Shared/CLAUDE.md) — código transversal
- [app/templates/CLAUDE.md](../app/templates/CLAUDE.md) — convenções Twig
- [app/tests/CLAUDE.md](../app/tests/CLAUDE.md) — tipos de teste, DAMA, Foundry, attributes PHPUnit

## Skills e comandos (`.claude/`)
- [commands/git-commit.md](../.claude/commands/git-commit.md)
- [commands/relatorio-dia.md](../.claude/commands/relatorio-dia.md)
- [commands/review.md](../.claude/commands/review.md)
- [skills/criar-controller/SKILL.md](../.claude/skills/criar-controller/SKILL.md)
- [skills/criar-dto/SKILL.md](../.claude/skills/criar-dto/SKILL.md)
- [skills/criar-entity/SKILL.md](../.claude/skills/criar-entity/SKILL.md)
- [skills/criar-form/SKILL.md](../.claude/skills/criar-form/SKILL.md)
- [skills/criar-repository/SKILL.md](../.claude/skills/criar-repository/SKILL.md)
- [skills/criar-usecase/SKILL.md](../.claude/skills/criar-usecase/SKILL.md)
- [skills/workflow/SKILL.md](../.claude/skills/workflow/SKILL.md)

## Não versionados (gitignored, apenas locais)
- [dossie-system-overview.md](../dossie-system-overview.md)
- [relatorio-commits-10-11-jun-2026.md](../relatorio-commits-10-11-jun-2026.md)
