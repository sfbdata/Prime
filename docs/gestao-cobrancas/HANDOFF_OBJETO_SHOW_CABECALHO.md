# HANDOFF — Cabeçalho e aba Responsáveis do `cobranca_objeto_show`

Redesenho pedido pelo dono do sistema a partir de uma montagem visual (2026-07-27).
**Contrato:** [`docs/specs/cobranca-objeto-show-cabecalho-responsaveis.md`](../specs/cobranca-objeto-show-cabecalho-responsaveis.md).
Leia a spec antes de escrever qualquer linha — ela registra o que foi cortado da maquete e por quê.

## Estado

| | |
|---|---|
| Branch | **`master`** (`6e93b43` == `origin/master`) — commit local, sem publicar |
| Worktree | nenhuma — trabalho direto no checkout principal |
| Migration | **nenhuma prevista**, e é decisão de projeto (ver §3.1 da spec) |
| Suíte | `tests/Cobranca/Unit` 652/652 verde ao fim da Etapa 1 |
| Publicado | **nada** |

### Por que master, e não uma branch própria

O hook `pre-commit` amarra **pasta ↔ frente**: o checkout principal só aceita commit no `master`, e
frente nova mora em worktree (`scripts/frente-abrir.sh`). Tentei abrir a branch
`cobranca-objeto-show-cabecalho` aqui e o hook recusou, corretamente.

Worktree não serve para esta frente: o `nginx.conf` fixa `root /var/www/app/public` e só a 8080 é
publicada, então **`localhost:8080` sempre serve o checkout principal** — e é lá que o dono faz o
smoke desta tela. Trabalhar em worktree exigiria subir um container de preview só para isso.

### ⚠️ O banco `saas` do dev NÃO serve para o smoke

`saas` está **à frente do master**: carrega as 4 migrations da frente canônica
(`Version20260725120000` … `180000`), que renomeiam `caso_id → objeto_id` em `cobranca_liquidacao`,
`cobranca_proxima_acao`, `cobranca_documento` e `cobranca_secao`, criam
`cobranca_obrigacao_identidade_externa` e derrubam `cobranca_vinculo_pessoa_objeto.principal`. O
mapeamento do master espera `caso_id`. Medido em 2026-07-27 com `doctrine:schema:update --dump-sql`.

**O banco compatível é `saas_ux`** (clone com as 4 revertidas, sobrou da frente de UX rápida).
Confirmado: `cobranca_liquidacao.caso_id` presente, `identidade_externa` ausente.

Para o dono conseguir smoke em `localhost:8080`, o checkout principal precisa de um `app/.env.local`
(gitignored) apontando para `saas_ux`:

```bash
# Execute manualmente no terminal externo
echo 'DATABASE_URL="pgsql://symfony:symfony@db:5432/saas_ux"' > app/.env.local
docker exec jusprime_php_dev bash -c 'cd app && php bin/console cache:clear'
```

(A credencial é a mesma do `app/.env`; só o nome do banco muda.) Para voltar ao `saas`, apague o
`.env.local`. **Não fiz isso sozinho**: `.env.local` muda o banco que a 8080 inteira lê, e outra
sessão pode estar usando o ambiente.

⚠️ **Duas worktrees vivas no repositório** (`cobranca-acompanhamento-canonico`, `cobranca-ux-rapida`).
Rode `git worktree list` antes de qualquer integração — worktree abandonada segurando uma branch faz
`git switch` recusar e os comandos seguintes rodarem em silêncio no lugar errado.

⚠️ `docs/gestao-cobrancas/cobranca-acompanhamento-canonica.md` está **untracked e é de outra frente**.
Não commite junto.

## Etapas

- [x] **1 — Fundação** (enum, tipo de evento, DTOs de leitura, calculadora de prescrição)
      — ⚠️ **escrita e testada, mas AINDA NÃO COMMITADA**; ver "Primeira coisa a fazer" abaixo
- [ ] **2 — UseCases de qualificação** (registrar + desfazer + exceção)
- [ ] **3 — Leitura da página** (totais do cabeçalho, prescrição, ficha da cobrada, vizinhos na carteira)
- [ ] **4 — Rotas** (registrar / desfazer qualificação)
- [ ] **5 — Template do cabeçalho**
- [ ] **6 — Template da aba Responsáveis + painel de qualificação**
- [ ] **7 — CSS**
- [ ] **8 — Testes** (unitários e funcionais) e suíte completa

O dono faz o smoke no navegador dele. **Não abra o Playwright.**

---

## Primeira coisa a fazer

O repositório ficou parado na branch `cobranca-objeto-show-cabecalho` (criada por engano antes de o
hook explicar a regra pasta↔frente) com **tudo da Etapa 1 já em staging, sem commit**. O Claude não
pode `git switch` — é operação do humano.

```bash
# Execute manualmente no terminal externo
git switch master
git branch -d cobranca-objeto-show-cabecalho
git status --short          # confere que o staging da Etapa 1 continua lá
```

As duas branches apontam para o mesmo commit, então o staging atravessa a troca intacto. Depois disso
o Claude commita a Etapa 1 normalmente.

**Não inclua** `docs/gestao-cobrancas/cobranca-acompanhamento-canonica.md` no commit — é untracked e
pertence a outra frente.

---

## Etapa 1 — FEITA (falta commitar)

Arquivos novos:

| Arquivo | O quê |
|---|---|
| `app/src/Cobranca/Enum/QualificacaoContato.php` | 3 cases + `label()`, `icone()`, `doPainel()`, `tentarDe()` |
| `app/src/Cobranca/DTO/QualificacaoContatoOutput.php` | uma linha da listinha; `podeDesfazer` decidido no servidor |
| `app/src/Cobranca/DTO/PrescricaoOutput.php` | 4 severidades como constantes |
| `app/src/Cobranca/Service/CalculadoraPrescricao.php` | `PRAZO_PADRAO_ANOS = 5`, faixas 90/180 dias |

Arquivos alterados:

| Arquivo | O quê |
|---|---|
| `app/src/Cobranca/Enum/TipoEventoHistorico.php` | case `QualificacaoContato`; `ehTrabalhoDeCobranca() => true` |
| `app/src/Cobranca/Entity/EventoHistorico.php` | `JANELA_DESFAZER_QUALIFICACAO = 'PT5M'` + `podeSerDesfeitaPor()` |
| `app/tests/Cobranca/Unit/TipoEventoHistoricoTrabalhoTest.php` | o tipo novo na lista literal da spec |
| `docs/specs/cobranca-central-acompanhamento.md` | idem, na §5.1 |

### O que a Etapa 1 já ensinou

**O teste guardião funcionou.** `TipoEventoHistoricoTrabalhoTest` quebrou sozinho ao ver o case novo —
é literalmente o defeito que ele existe para pegar (tipo novo entrando na Central sem classificação).
Corrigir era atualizar **as duas** listas: a do teste e a da spec da Central, que é a fonte que o teste
copia de propósito para poder discordar do código.

**`podeSerDesfeitaPor` cobre 3 das 4 condições, não as 4.** A quarta — ser a qualificação mais recente
do caso — não cabe na entidade, porque um evento não conhece os irmãos. Ela é da Etapa 2, no UseCase.
Quem esquecer disso vai deixar desfazer qualquer qualificação dos últimos 5 minutos, não só a última.

---

## Etapa 2 — PRÓXIMA

Criar:

- `app/src/Cobranca/Exception/QualificacaoNaoDesfazivelException.php`
- `app/src/Cobranca/UseCase/RegistrarQualificacaoContatoUseCase.php`
  - espelhe `RegistrarAnotacaoUseCase`: resolve o caso por `findOneByIdDoTenant`, recusa
    `CasoEncerradoException`, grava via `RegistrarEventoHistorico::registrar(..., flush: true)`
  - `descricao` = `$qualificacao->label()`; `dados = ['qualificacao' => $qualificacao->value]`
- `app/src/Cobranca/UseCase/DesfazerQualificacaoContatoUseCase.php`
  - as **4** condições da spec §3.5, sendo a 4ª (mais recente) verificada aqui
  - remove o evento de verdade (`EventoHistoricoRepository::remover`), como a exclusão de anotação faz

O relógio: use a mesma fonte do resto da página (`EncargosVivos::agora()`). **Nada de
`new \DateTimeImmutable()` no caminho** — sem relógio injetado o teste da janela de 5 minutos não tem
como fixar o tempo.

## Comandos

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca/Unit'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'   # suíte completa, só na Etapa 8
```
