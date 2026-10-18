# Ambiente de DEV, console do Symfony e geradores

O porquê e os detalhes que moravam no CLAUDE.md raiz até 18/10/2026. O CLAUDE.md guarda as regras; aqui
ficam a tabela de comandos, os comandos de humano e as armadilhas medidas.

## Comandos de humano e de depuração

```bash
docker exec -it jusprime_db_dev psql -U symfony -d saas   # psql interativo
cd e2e && npm run test:headed                            # E2E com o navegador visível
cd e2e && npm run test:ui                                # E2E no modo UI do Playwright
```

A pasta principal do repositório é só cópia do master: ela é atualizada no início de cada sessão e pelo
alias `gh merge-atualiza <PR>` (merge + atualização), ambos do dono.

## Uploads em DEV (permissão)

O container roda como `${UID:-1000}` (bind-mount do repo). Os diretórios
`app/public/uploads/*` (clientes, justificativas, chamados, pastas, perfil, tarefas) precisam ser graváveis
por esse uid. Se um upload falhar com *Permission denied* (dirs como `uid 33`/`www-data` da imagem prod),
alinhe o dono: `docker exec -u 0 jusprime_php_dev chown -R 1000:1000 /var/www/app/public/uploads`. Em
ambiente de **teste** isso não acontece (config aponta para `var/uploads-test/*`, gravável).

## Console do Symfony: pergunte ao framework antes de grepar

O `debug:*` lê o **container real** (o que o Symfony efetivamente montou). O `grep` lê o que o código
*parece* dizer. Antes de caçar rota, serviço, config, filtro Twig ou mapeamento no código-fonte, rode o
comando: é mais rápido e é autoritativo. Todos rodam via `docker exec` (ver o CLAUDE.md, seção Docker).

| Quero saber | Comando |
|---|---|
| que rotas existem · URL/controller de uma rota | `debug:router` · `debug:router <nome_da_rota>` |
| se um serviço está registrado e qual a classe | `debug:container <trecho>` |
| o que dá para injetar por type-hint no construtor | `debug:autowiring <trecho>` |
| a config **efetiva** de um bundle (após o merge) | `debug:config <bundle>` |
| filtros/funções/globais Twig disponíveis | `debug:twig` |
| quais entidades estão mapeadas | `doctrine:mapping:info` |
| se o **mapeamento** Doctrine está são | `doctrine:schema:validate --skip-sync` |
| se o **banco** está em dia com o mapeamento | `doctrine:schema:validate` |
| quem escuta um evento | `debug:event-dispatcher <evento>` |
| se Twig / YAML / container quebrou | `lint:twig templates` · `lint:yaml config` · `lint:container` |

## MCP `jusprime-prod`: consultar a produção (somente leitura)

Servidor MCP registrado no Claude Code, ligado à PROD por um usuário PostgreSQL que **só tem
`SELECT`** (a recusa é do banco, não do código). Duas ferramentas: `consultar_sql` e
`descrever_esquema`. Runbook e limitações → `docs/runbooks/mcp-investigacao-prod.md`.

**Antes de escrever SQL contra tabela que você não conhece de cor, chame `descrever_esquema` —
e leia as `chaves`, não só as colunas.** A ligação entre tabelas não é adivinhável: em cobrança
a cadeia é `cobranca_obrigacao → cobranca_caso → cobranca_objeto → cobranca_carteira`, e o
`carteira_id` mora no **objeto**, não no caso. Chutar nome de tabela dá erro na hora; chutar
**coluna que existe com outro significado** não dá erro nenhum — dá número errado com cara de
certo, que é o defeito que já mordeu este projeto.

Teto de 500 linhas por consulta: quando vier `truncado: true`, refine com agregação ou filtro,
não pagine às cegas. E lembre que o resultado traz **dado real de cliente** — não puxe PII para
a conversa sem precisar dela.

## Geradores (`make:*`)

O MakerBundle gera no layout **padrão** do Symfony (`src/Entity/`, `src/Controller/`, `src/Form/`), não em
`app/src/<Dominio>/`, e sem tenant, DTO ou UseCase. O único ajuste de namespace (`maker.root_namespace`) é
global — não há como apontá-lo para o layout de domínio. Escreva à mão seguindo a skill da camada
(`criar-*`); cada uma registra o que o gerador correspondente faria de errado. Ressalvas dos geradores de
teste → `app/tests/CLAUDE.md`.

A exceção é `make:migration`: ritual na skill `criar-migration`.

## Documentação oficial: conferir quando não há precedente aqui

O conhecimento do modelo tem data de corte e não distingue "eu sei" de "eu acho". O gatilho é objetivo, não
depende de eu me sentir inseguro: **vou usar uma API do Symfony/Doctrine que não tem precedente neste
repositório?** (atributo, opção de config, método ou serviço que um `grep` em `app/` não encontra). Se não
tem, confira na doc antes de escrever — é justamente o caso em que estou tirando da memória.

- Symfony 7.4 → `https://symfony.com/doc/7.4/<tema>.html` (`/doc/current/` aponta para a versão mais nova, que não é necessariamente a nossa)
- Doctrine ORM 3.x → `https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/`
- Comportamento de um comando → `php bin/console <cmd> --help` (autoritativo para a versão instalada aqui)

Isso vale para o **framework**. As regras **da casa** (multi-tenancy, UseCase, nomes de pasta) não estão em
documentação nenhuma — moram nos `CLAUDE.md` por camada e nas skills `criar-*`.
