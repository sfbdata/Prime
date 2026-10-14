# Versionamento e releases do BlueJus

Política de versões, critérios de cada nível, fonte única do número, procedimento de release e de
rollback. Escrito em 14/10/2026, quando o versionamento foi estruturado; o registro das mudanças
fica no [CHANGELOG.md](../CHANGELOG.md).

## 1. O que existia antes (medido em 14/10/2026)

- **Tags:** `v1.0.0`, tag leve no commit `4940cc87` de 31/03/2026 ("alteração do nome da folha de
  ponto"), e `pre-refactor-identidade-global` (anotada, criada em 15/05/2026 sobre o commit
  `767e1dd7` de 07/05: "estado de prod antes do deploy da refatoração de identidade global").
  Nenhuma outra.
- **Releases no GitHub:** nenhuma.
- **Arquivo de versão, CHANGELOG, versão na interface:** nenhum. `composer.json` não tem `version`;
  nenhum template, rota ou rodapé mostra versão.
- **Histórico:** 2.027 commits entre a `v1.0.0` e o master `87f3bd2d`, quase todos antes de existir
  pull request (os primeiros PRs mergeados, #2 a #4, são de 09/10/2026).
- **Deploy:** `scripts/deploy-prod-tls.sh` faz `git pull` do master na VPS e reconstrói a imagem.
  Não registra versão; o que está no ar é "o commit do master no último deploy".

## 2. A versão inicial: 2.0.0

A próxima versão **não** deve ser `1.1.0`. Pelo critério da seção 3, ela é MAJOR, porque desde a
`v1.0.0` houve remoção de funcionalidade e de endereços que existiam:

| O que saiu | Commit | Data |
|---|---|---|
| Módulo de Pré-cadastro inteiro (telas, rotas e permissão) | `e790cf87` | 19/05/2026 |
| Página avulsa de Clientes (`/clientes`, `/clientes/novo-pf`, `/clientes/novo-pj`) e o item de menu | `c264fa06` | 04/09/2026 |
| Rotas do fluxo antigo de Tarefas (`/tarefas/nova`, `/tarefas/admin`, `encerrar`, `enviar-revisao`, `enviar-pendencia`) | `84e69c8a` | 17/04/2026 |
| Upload legado de documento da Pasta (`POST /pasta/{id}/documento/upload`) | `b575428d` | 07/10/2026 |

Medição: 15 endereços que a `v1.0.0` declarava não existem mais no master — os 6 de Pré-cadastro
(incluindo `/clientes/from-pre-cadastro/{id}`), os 3 de Clientes, os 5 de Tarefas e o de upload da
Pasta. Método: atributos `#[Route]` das duas versões, com o prefixo da classe e os parâmetros
normalizados (`{id}` e `{tenantId}` contam como o mesmo endereço). O total de rotas varia com o
método de extração (entre 85 e 87 na `v1.0.0`, entre 380 e 394 no master); a lista dos 15 não. Soma-se a troca do modelo de login
(identidade global, `1e6ffb67`, 08/05/2026), que mudou como o usuário entra no sistema.

**Decisão do dono, pendente:** confirmar 2.0.0. A alternativa `1.1.0` só se sustentaria tratando a
`v1.0.0` como marco sem valor de contrato, e aí a tag existente passaria a mentir sobre o que veio
depois. A tag `v1.0.0` fica como está: tag publicada não se apaga nem se move.

## 3. Política (SemVer: MAJOR.MINOR.PATCH)

O "contrato" do BlueJus, para efeito de versão, é **o que o usuário de um escritório usa**: as
telas e funções, os endereços que ele pode ter salvo, o jeito de entrar no sistema e o significado
dos dados que ele vê. Não há API pública para terceiros.

| Nível | Quando | Exemplos do histórico |
|---|---|---|
| **MAJOR** (X.0.0) | Algo que o usuário usava **deixa de existir ou exige ação dele**: funcionalidade removida, endereço removido ou mudado sem redirecionamento, mudança no login/acesso, regra que muda o significado de dado já existente (um saldo, um prazo, um valor). | Remoção do Pré-cadastro; identidade global; uma regra nova de ponto que recalcula o passado. |
| **MINOR** (x.Y.0) | Algo novo ou melhorado que **não tira nada** de quem já usa: módulo, tela, função, relatório, redesenho que preserva as funções. | Kanban, DJEN, Cobranças, redesenho de Pasta. |
| **PATCH** (x.y.Z) | Correção que devolve o comportamento esperado, sem nada novo: bug, correção de segurança compatível, ajuste de texto, desempenho. | A correção do `register_argc_argv` (#8). |

Regras de desempate:

- Vale o **maior** nível presente: uma release com uma remoção e dez correções é MAJOR.
- Migration de banco não decide o nível sozinha: decide o que ela muda para o usuário.
- **Não geram release** mudanças só de CI, testes, documentação ou ferramentas internas. Elas
  entram em "[Não lançado]" se forem relevantes e saem na próxima release.
- Na dúvida entre dois níveis, escolha o maior e escreva o motivo no PR de release.

## 4. Fonte única da versão

- **O número:** `App\Shared\VersaoDoSistema::ATUAL` (`app/src/Shared/VersaoDoSistema.php`). É a
  **última versão lançada**, sem o `v`. Segue o idioma de `TermoVigente` e `PoliticaPrivacidadeVigente`.
- **O conteúdo:** `CHANGELOG.md` na raiz, em Keep a Changelog, seção por versão.
- **A trava:** `app/tests/Arquitetura/VersaoDoSistemaTest` (roda no portão Qualidade de todo PR)
  exige que a constante seja a versão mais recente do CHANGELOG, que toda seção tenha formato e data
  reais, ordem decrescente e link de referência. O `scripts/release.sh validar` repete o contrato,
  e o workflow Release o roda antes de criar a tag.

**Para a futura página de Novidades:** o número vem da constante. O texto tem uma restrição de
infraestrutura: a imagem de produção copia só `app/` (`COPY app/ ./` no `Dockerfile`), então o
`CHANGELOG.md` da raiz **não está** dentro do container. A página vai precisar de uma destas duas
opções, a decidir quando ela for feita: copiar o CHANGELOG para a imagem (uma linha no Dockerfile)
ou manter um texto próprio para o usuário final, já que o CHANGELOG é escrito para a equipe.

## 5. Como lançar uma versão

1. **PR de release**, numa branch `release-X.Y.Z` cortada do master:
   - `VersaoDoSistema::ATUAL` passa a `X.Y.Z`;
   - no CHANGELOG, a seção `## [Não lançado]` ganha embaixo dela `## [X.Y.Z] - AAAA-MM-DD`, com o
     conteúdo que estava em "[Não lançado]" (reescreva o parágrafo de abertura: ele vira o corpo da
     release no GitHub). A data é a do dia em que a tag vai ser criada; se escorregar, corrija no
     próprio PR antes do merge;
   - nos links do fim: `[Não lançado]: .../compare/vX.Y.Z...HEAD` e
     `[X.Y.Z]: .../compare/vANTERIOR...vX.Y.Z`;
   - `[Não lançado]` fica só com o título, sem conteúdo: tudo o que estava ali passa para a versão;
   - conferir localmente: `bash scripts/release.sh validar X.Y.Z`.
2. **Merge** do PR, depois dos checks obrigatórios verdes ("Segredos" e "Lints e testes
   unitários", que inclui o `VersaoDoSistemaTest`).
3. **Esperar os mesmos checks verdes no commit de merge** do master (eles rodam no push).
4. **Criar a tag pelo workflow**, nunca à mão:
   ```bash
   gh workflow run release.yml --repo sfbdata/Prime --ref master -f versao=X.Y.Z
   gh run watch --repo sfbdata/Prime "$(gh run list --repo sfbdata/Prime --workflow release.yml -L 1 --json databaseId -q '.[0].databaseId')"
   ```
   O job roda no ambiente `release` e **espera a sua aprovação** na página da execução (Review
   deployments → Approve). Ele recusa se não for o master, se a constante ou o CHANGELOG não
   baterem, se a tag existir ou se os checks não estiverem verdes **naquele commit**; e só então o
   GitHub App "BlueJus Release" cria a tag `vX.Y.Z` e a release com o texto do CHANGELOG.
5. **Deploy** como hoje (`DEPLOY.md`). Antes, na VPS, conferir que o master que o script vai puxar
   é exatamente a tag; depois, que é ela que está no ar:
   ```bash
   cd /opt/jusprime && git fetch --tags origin && test "$(git rev-parse origin/master)" = "$(git rev-list -n1 vX.Y.Z)" && echo "ok: o deploy leva vX.Y.Z"
   # ... scripts/deploy-prod-tls.sh ...
   git describe --tags --exact-match HEAD   # tem que dizer vX.Y.Z
   ```
   Se o master andou depois da tag, o deploy levaria commits sem versão: pare e decida (lançar
   outra versão, ou esperar).
6. **Registrar** o que foi ao ar no checklist do `DEPLOY.md` (versão, data/hora, responsável).

Correção urgente em produção: o mesmo fluxo com PATCH (`X.Y.Z+1`), PR pequeno.

## 6. Como o CI garante que não sai tag sem teste

- Todo PR para o master passa por "Segredos" e "Lints e testes unitários" (obrigatórios na proteção
  do master, com `enforce_admins`).
- O workflow Release só cria tag num commit em que esses dois checks estejam `success` (do app
  GitHub Actions) e com `[Não lançado]` vazio, para a tag não levar mudança de outro PR mergeado
  entre o PR de release e o disparo.
- **Tags `v*` protegidas por ruleset** (criar, mover e apagar), com uma única exceção: o GitHub App
  "BlueJus Release", cuja chave só existe no ambiente `release`, que só aceita jobs do master e
  pede aprovação. Com o ruleset ligado, nem o admin cria tag `v*` à mão (ele pode desligar o
  ruleset, o que fica registrado), e um `release.yml` alterado em outra branch não recebe a chave. Desenho, configuração, testes e reversão:
  [runbooks/protecao-tags-versao.md](runbooks/protecao-tags-versao.md). **Até o ruleset ser
  ligado pelo dono, quem tem escrita ainda consegue criar tag `v*` à mão.**
- A suíte Functional (que depende do banco de dump) não roda no CI: antes do PR de release, a suíte
  completa no container continua obrigatória (`scripts/frente-testar.sh`).

## 7. Rollback

Uma versão publicada nunca é "desfeita" mexendo em tag: **tag publicada não se apaga nem se move**.
Volta-se o sistema, e a correção sai como versão nova.

- **Voltar o que está no ar (minutos):** rollback por imagem, preparado para o deploy do PR #8 em
  14/10/2026 e simulado com o Docker Compose antes dele (a volta em si não precisou ser executada
  em produção). O deploy apaga a imagem antiga
  (`docker image prune` no fim do script e o `prune -af` do cron), então ela é guardada ANTES:
  ```bash
  # antes do deploy: tag + container parado (segura contra o prune) + cópia em arquivo
  # (um `docker system prune` apaga container parado — o .tar.gz é o que sobra)
  for s in php worker; do
    img=$(docker inspect -f '{{.Image}}' jusprime_${s}_prod)
    docker tag "$img" jusprime-rollback:${s}-pre-vX.Y.Z
    docker create --name jusprime_rollback_${s}_pre_vX.Y.Z jusprime-rollback:${s}-pre-vX.Y.Z >/dev/null
  done
  mkdir -p /opt/backups/imagens && docker save jusprime-rollback:php-pre-vX.Y.Z jusprime-rollback:worker-pre-vX.Y.Z \
    | gzip > /opt/backups/imagens/jusprime-pre-vX.Y.Z-$(date -u +%Y%m%dT%H%MZ).tar.gz \
    && ls -lh /opt/backups/imagens | tail -1     # confira que o arquivo saiu com tamanho

  # para voltar: a imagem guardada assume o nome do compose e os dois serviços são recriados
  cd /opt/jusprime
  for s in php worker; do
    docker tag jusprime-rollback:${s}-pre-vX.Y.Z "$(docker inspect -f '{{.Config.Image}}' jusprime_${s}_prod)"
  done
  docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-build php worker
  ```
  Se a versão anterior tinha uma mitigação a quente (como a do `register_argc_argv`), reaplique-a
  depois de voltar: a imagem antiga não a traz.
- **Corrigir de vez:** `git revert` do merge problemático num PR, e lançar como PATCH (ou MINOR, se
  o revert remover algo novo que já foi anunciado).
- **O que NÃO funciona hoje:** fazer deploy de uma tag antiga com o script. O
  `scripts/deploy-prod-tls.sh` faz `git pull`, que falha num checkout destacado (`git checkout
  vX.Y.Z`); como o script roda com `set -e`, ele para **antes** do build e o site segue no ar — é
  uma falha segura, mas não é um caminho de rollback. Se esse caminho for desejado, é uma mudança
  no script de deploy, a avaliar à parte.
- Migration: rollback por imagem **não** desfaz migration — o código anterior volta sobre o schema
  novo. Isso só funciona quando a migration da release é compatível com o código anterior (coluna ou
  tabela nova, por exemplo). Nem sempre foi assim: a Etapa 6 da identidade global apagou 8 colunas
  (`55bfeed5`). Release com migration incompatível precisa do próprio plano de volta no PR (dump
  antes do deploy e o caminho de restauração).

## 8. Pendências

- [ ] Dono: confirmar **2.0.0** como primeira versão desta política (seção 2).
- [ ] Dono: configurar App, ambiente e ruleset das tags `v*` (seção 6 e
      `runbooks/protecao-tags-versao.md`), nessa ordem, depois do merge do workflow novo.
- [ ] Primeiro PR de release `release-2.0.0` e disparo do workflow — só com autorização.
- [ ] Página de Novidades: decidir CHANGELOG na imagem × texto próprio (seção 4).
