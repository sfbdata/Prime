# src/Repository/ — Legado

⚠️ Pasta legado. Não criar arquivos novos aqui.

## Ao tocar um arquivo desta pasta

Decida ANTES de editar:

**Opção A — Edição mínima cirúrgica**
Se a alteração é uma correção pontual e pequena (1-2 linhas),
edite no lugar. Não faça refactor oportunista junto.

**Opção B — Migração para domínio**
Se a alteração é não-trivial OU o arquivo precisa de evolução
maior, MIGRE para `src/<Dominio>/Repository/` antes de
implementar a mudança. Veja `app/src/CLAUDE.md` seção
"Refatorando código legado" para o checklist.

## Regras válidas aqui

As mesmas regras gerais do projeto (raiz CLAUDE.md) se aplicam:
strict_types, type hints, multi-tenant, PermissionChecker.

Padrões de camada (estrutura, convenções, heurística 5-10-20)
estão documentados nas skills do projeto — consulte ao migrar.
