# JusPrime / BlueJus — Contexto Geral

SaaS jurídico multi-tenant. PHP 8.2+, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Docker.

## Idioma

Código, comentários, explicações, pensamentos e commits em **português brasileiro**.
`camelCase` métodos/variáveis · `PascalCase` classes · `snake_case` rotas/templates/colunas DB.

**Sem travessão (— ou –) em texto que o usuário vê**: mensagens, flashes, rótulos, templates, JS.
Regra do dono (15/10/2026). Reescreva com ponto, dois-pontos, vírgula ou parênteses. Vale para todo
texto novo ou tocado; o legado só sai por varredura autorizada (`SemTravessaoNovoArquiteturaTest`).

## Arquitetura em uma frase

Cada domínio em `app/src/<Dominio>/` com `Controller/ UseCase/ Entity/ Repository/ DTO/ Form/`.
Fluxo: `Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()`.
Detalhes, multi-tenancy, permissões e legado → `app/src/CLAUDE.md`. Permissão → leia antes
`docs/AUTORIZACAO.md` (4 camadas, bypasses, falhas conhecidas).

## Orquestrador & ciclo de trabalho

O agente principal orquestra: analisa, decompõe, define contratos, delega, integra e valida.
Subagentes no menor privilégio: investigação e revisão são read-only e nunca corrigem o que apontam;
implementador só escreve quando delegado, em escopo exclusivo (worktree se em paralelo); nenhum
subagente integra, faz merge/push ou decide sozinho arquitetura compartilhada. Contratos e
dependências são definidos antes de qualquer escrita paralela. Detalhes → skill `workflow`.

**Risco** (define o rigor): ALTO = ponto eletrônico, identidade User/Tenant ·
MÉDIO = TenantRole/Permission/Profile · BAIXO = demais.

**Ciclo:** investigar (read-only) → planejar (spec em `docs/specs/` se ALTO/MÉDIO) → implementar →
revisar contra a spec/descrição (`feature-review-agent`, read-only, disparado por `/review`) →
corrigir (orquestrador) → conferir; em ALTO, re-revisar antes de seguir.

## Fluxo de desenvolvimento

Antes de implementar: (1) analise ou crie o(s) UseCase(s); (2) escreva ou ajuste os testes (unit do
UseCase + functional do controller); (3) só então o resto. Skills por camada: `criar-controller`,
`criar-entity`, `criar-repository`, `criar-usecase`, `criar-dto`, `criar-form`, `criar-migration`,
`armazenamento-de-arquivos`. A skill `workflow` carrega no início de qualquer implementação ou refatoração.

## Implementar a partir de um desenho — o desenho manda

> Onde o desenho **mostra algo diferente**, o desenho manda.
> Onde o desenho é **omisso**, a convenção do sistema manda.
> **Desviar do desenho aprovado exige perguntar antes**, nunca decidir e entregar.

Antes: `mcp__open-pencil__get_codegen_prompt`, depois `design_to_tokens` (valor do documento não é
opinião); valide o desenho contra o dado que existe; nunca escreva "isto é só um wireframe" no
artefato. Depois: percorra o desenho item a item e reporte ✓/✗ (suíte verde não diz nada sobre
aparência). Arranjo se testa com combinador de **filho direto** (`.row > .col-lg-4 .trilho`), não
com "existe em algum lugar da página". Origem, exemplos e a armadilha do clearfix do AdminLTE →
`docs/guias/desenho-para-tela.md`.

## Docker — todos os comandos dentro do container

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && composer <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'              # suíte completa
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter <Nome>'  # um teste/classe
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/<Dominio>/Unit'  # uma pasta
docker exec jusprime_db_dev psql -U symfony -d saas -c "<query>" # one-shot
scripts/qa.sh                  # no host, antes do PR: PHPStan nível 5 + CS-Fixer nos PHP novos
```

Nunca rodar `php`, `composer` ou `bin/console` fora do container. PHPUnit roda em
`APP_ENV=test` com `failOnDeprecation/Notice/Warning`: um deprecation derruba a suíte. DAMA e Foundry
→ `app/tests/CLAUDE.md`.
E2E: `cd e2e && npm test`, fora do container.

**O smoke no navegador é do dono.** Não abra o Playwright por conta própria (`mcp__playwright__*` nem
`npx playwright`), nem para "conferir se ficou bom"; só quando ele pedir, com essas palavras. Entregue
com a suíte verde e **diga o que precisa ser olhado na tela**.

## Console, geradores, documentação e produção

- **Pergunte ao framework antes de grepar:** `debug:router`, `debug:container`, `debug:autowiring`,
  `debug:config`, `debug:twig`, `doctrine:mapping:info`, `doctrine:schema:validate`, `lint:*`.
- **`make:*` gera no layout errado** (sem domínio, tenant, DTO nem UseCase): escreva à mão pela skill
  `criar-*`. A exceção é `make:migration`, sempre com a skill `criar-migration`.
- **API do Symfony/Doctrine sem precedente em `app/`** (um grep não acha) → confira a doc antes de
  escrever: `symfony.com/doc/7.4/` (não `/current/`), Doctrine ORM 3.6, `bin/console <cmd> --help`.
- **Consultar a produção: só pelo MCP `jusprime-prod`** (somente `SELECT`): `descrever_esquema` e as `chaves` antes
  do SQL; o resultado é dado real de cliente, sem puxar PII à toa.

Tabela de comandos, uploads em DEV e o porquê de cada item → `docs/guias/ambiente-e-console.md`.

## Várias sessões ao mesmo tempo — evitar conflito sem travar o trabalho

Cada sessão trabalha na própria worktree/branch; a pasta principal é só cópia do master. São avisos e
hábitos, nenhum bloqueia o dono:

1. **Ao começar e antes de cada PR**, `gh pr list` e avisar o dono se outro PR mexe na mesma área.
   Ele decide se segue em paralelo.
2. **PR pequeno e curto**, aberto assim que a entrega estiver pronta.
3. **Depois de cada merge**, atualizar os PRs que ficaram para trás (`git merge --no-edit origin/master`
   na branch, testes, `git push`) antes de pedir o próximo merge.
4. **Arquivos quentes** (`base.html.twig`, `_sidebar.html.twig`, CSS global, `app/config/*.yaml`,
   `composer.json/lock`, workflows, `CLAUDE.md`, migrations): avisar no início; se duas sessões
   precisam, a segunda atualiza logo depois do merge da primeira.
5. **Uma migration por vez** entre os PRs abertos.
6. **Antes de pedir merge**, conferir o check "Aviso de sobreposição" (outros PRs nos mesmos arquivos,
   branch atrás do master) e o estado do PR (sem conflito, checks verdes).

## Git — o Claude abre o PR, o merge é do dono

Caminho único até produção: branch/worktree → push da branch → PR → checks verdes → **merge no
GitHub pelo dono** → release → deploy. O master do GitHub só recebe por PR com os checks obrigatórios
(vale até para o admin); o master local é só cópia, nunca lugar de merge.

Claude **pode**: `git add` e commit local (revisando status, diff e staged, sem `git add .` cego);
`git push -u origin <branch>` de branch de trabalho; `gh pr create`; `git merge --no-edit
origin/master` numa branch de trabalho; leitura (`git status/diff/log/show/fetch`, `gh pr view/checks`,
`gh run view/list`, `gh api` GET).

**Exclusivo do dono:** `gh pr merge` (mergear o PR de release é publicar em produção); disparar
workflow, release, segredos, variáveis, rulesets, configuração do repositório e `gh api` que escreve;
`merge` em qualquer direção que não seja trazer o `origin/master` para a branch de trabalho; push no
master/main, de tag, forçado, de exclusão ou com refspec; `pull`, `rebase`, `reset`, `checkout`,
`stash` e demais reescrita local (sessões paralelas já perderam trabalho com isso). Quando a tarefa implicar uma dessas, monte o comando, explique, e entregue em
bloco `# Execute manualmente no terminal externo`.

A garantia técnica é `.claude/hooks/block-git-writes.py` (testado no CI; não aceita `--no-verify`).

Todo PR leva um arquivo em `mudancas/` (check obrigatório "Registro de mudança").

Commit: imperativo em português, máx. 72 chars, sem ponto final. Branches: `nome-da-feature` ·
`fix-<issue>` · `refactor-<desc>`.

## Mapa de documentação

O contexto detalhado mora nos arquivos da camada que você está tocando: leia o relevante antes de editar.

| Onde | O que cobre |
|---|---|
| `app/src/CLAUDE.md` | layout de domínios, legado, regras transversais, padrões PHP/Symfony |
| `app/src/{Controller,Entity,Repository}/CLAUDE.md` | pasta legada: edição mínima ou migração para o domínio |
| `app/src/Shared/CLAUDE.md` | código transversal (arquivos → skill `armazenamento-de-arquivos`) |
| `app/src/Central/CLAUDE.md`, `app/src/Inteligencia/CLAUDE.md` | regras inegociáveis do domínio |
| `app/templates/CLAUDE.md` | convenções Twig |
| `app/tests/CLAUDE.md` | tipos de teste, DAMA, Foundry, attributes PHPUnit |
| `docs/AUTORIZACAO.md` | modelo de autorização (4 camadas, bypasses, falhas) |
| `docs/guias/` | o porquê e os exemplos que saíram deste arquivo |
| `docs/specs/` | specs de features ALTO/MÉDIO risco |
