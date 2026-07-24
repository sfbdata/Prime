# Handoff — Ponto: contagem da folha pelo 1º registro (continuar ajustes)

Estado em 2026-07-24. Para retomar os ajustes do ponto em outro chat.

## TL;DR do que já foi feito

1. **Correção de dado da EDLUCIA (user 11, tenant 1)** — meio período mal cadastrado como integral. **TUDO aplicado em prod:** abonos Grupo A (`batch edlucia-ajuste-jornada-2026-05`) + Grupo B (`batch edlucia-ajuste-grupob-2026-05`) + `user_tenant.data_admissao='2026-05-18'`.
2. **Fix de código da folha** — duas versões:
   - ❌ **v1 "ignora dias pré-admissão"** (`bb0125f`): foi a prod e **REGREDIU** — veteranos admitidos antes do sistema existir ganharam centenas de horas negativas.
   - ✅ **v2 "contar a partir do 1º registro"** (merge `977d7c1`): corrige a v1, **deployado 2026-07-23 à noite** (VPS, sem migration).

## A REGRA ATUAL (v2)

A folha conta **a partir do registro de ponto mais antigo do colaborador naquele escritório** — batida real **ou** justificativa já **abonada**. Daí em diante.

- **Admissão e data de cadastro (`created_at`) NÃO entram no cálculo.** Só aparecem no cabeçalho da folha/XLSX (exibição).
- **Sem nenhum registro → não conta nada** (saldo 0, sem débito fantasma).
- **Buraco depois do início → falta** (débito real; só o "antes do 1º registro" é ignorado).
- **Janela do abono = 30 dias** (`InicioContagemResolver::JANELA_ABONO_DIAS`): um abono só puxa o início pra trás se cair até 30 dias antes da 1ª batida — senão um abono retroativo antigo reabriria o fantasma. Sem batida alguma, o abono mais antigo abre a contagem (sem janela).

### Por que a v1 falhou (não repetir)
`dataAdmissao ?? createdAt` respondia à pergunta errada. `data_admissao` = "desde quando é funcionário"; `created_at` = "desde quando o cadastro existe". Nenhuma responde "desde quando há **controle de ponto** para esta pessoa". O 1º registro responde, com dado real.

## Arquivos (branch já integrada e apagada; tudo no `origin/master`)

- `app/src/Ponto/Service/InicioContagemResolver.php` — **NOVO**. `resolver(User, Tenant): ?\DateTimeImmutable`. Centraliza a regra (batida × abono × janela). Injetado nos 2 controllers.
- `app/src/Ponto/Service/FolhaPontoBuilder.php` — `buildRows` + `calcularSaldoAteMes` + `calcularSaldoAnual` ganharam `$inicioContagem`. **Sentinela `\DateTimeInterface|null|false`: omitir LANÇA `InvalidArgumentException`** (porque `null` = "sem registro"/"não conta nada" — a semântica do omitido se inverteu). Chave de linha nova: `antesDoPrimeiroRegistro`.
- `app/src/Ponto/Repository/RegistroPontoRepository.php` — `findDataPrimeiraBatida(user, tenant)`.
- `app/src/Ponto/Repository/JustificativaPontoRepository.php` — `findDataPrimeiraAbonada(user, tenant, ?aPartirDe)` (só `status='abonado'`; `aPartirDe` é o piso da janela).
- Chamadores (6): `PontoController` (folha, saldo anual, PDF, XLSX, saldo anterior via `montarDadosFolha`) + `TenantController` (folha na visão do escritório).
- Specs: `docs/specs/ponto-folha-inicio-da-contagem.md` (atual) e `docs/specs/ponto-folha-ignora-dias-pre-admissao.md` (marcada SUPERADA — registro do que falhou).
- Testes: `FolhaPontoBuilderTest`, `InicioContagemResolverTest`, `PontoIsolamentoRepositoryTest` (SQL real, tenant/status/janela). Suíte da frente 2479/2479; master verde.

## ⏳ ÚNICO ITEM ABERTO: smoke em produção

Deploy feito, mas **falta olhar a tela** (a revisão exigiu — `montarDadosFolha` virou parâmetro obrigatório e o builder LANÇA; erro de fiação só aparece em runtime). Conferir em **bluejus.com.br**:

- **FARLEI** (user 1): a folha dele devia ter perdido as **~512h negativas fantasma**. Prova o caso mais dramático.
- **YLKA** (user 12): 1ª batida 18/05 é anterior ao cadastro 21/05 → contagem **antecipa 3 dias**; olhar 18–20/05.
- **Cadastros sem batida** (MARIANA, RODRIGO, MARIA-TESTE): devem estar zerados (não mais acumulando desde 01/01).
- Se alguma tela abrir com **erro 500** → é fiação; mandar a mensagem para corrigir.

Impacto medido em prod (2026-07): 7 pessoas com fantasma (37–90 dias cada). Query de comparação início-antigo × novo em `docs/specs/ponto-folha-inicio-da-contagem.md` §Impacto.

## Follow-ups / dívidas conhecidas

- `RegistroPontoRepository::findByUserAndCompetencia` (alimenta o saldo) segue **sem filtro de tenant explícito** (só o TenantFilter, que fica desligado em CLI) — dívida pré-existente (revisão B-2). Empregado compartilhado: início por tenant, mas batidas dependem do filtro estar ligado.
- `JANELA_ABONO_DIAS = 30` é constante — se o dono quiser outro número, é 1 linha + ajustar o teste.
- Resíduo: banco de teste `saas_testponto-contagem-primeiro-registro` (drop opcional: `scripts/frente-fechar.sh ponto-contagem-primeiro-registro`).

## Como retomar (fluxo de frentes NOVO — 2026-07-24)

- Abrir frente: `scripts/frente-abrir.sh <nome>` (worktree de `origin/master` + vendor + uploads + **banco de teste clonado**). Registrar em `docs/frentes-ativas.md`.
- Testar: **`scripts/frente-testar.sh <nome>`** — NUNCA `cd app && php bin/phpunit` (testa o repo principal = verde falso).
- Fechar: `scripts/frente-fechar.sh <nome>`. `.frente` + hook pre-commit recusam commit na branch errada.
- Ponto é **risco ALTO** → ciclo: investigar → spec em `docs/specs/` → TDD → implementar → `feature-review-agent` → corrigir → **re-revisar** → humano faz merge/push/deploy.
