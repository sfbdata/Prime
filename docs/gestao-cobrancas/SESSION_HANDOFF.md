# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-08.

---

## Estado atual
- **Branch:** `djen-integracao` ⚠️ (a feature está empilhada sobre o módulo DJEN — ver "Problemas conhecidos")
- **HEAD:** `f6362f0 Adicionar UseCase CriarPessoa (Cobranças)`
- **Etapa:** 1 (Núcleo de cadastro)
- **Onda:** 2 (fan-out dos UseCases) — **parcial**
- **Parou em:** piloto de fan-out concluído (2 de 7 UseCases da Etapa 1 integrados). Faltam 5 UseCases + Onda 3 (validação/cross-tenant).

## O que foi concluído nesta sessão
- Executado e **aprovado** o piloto de fan-out autônomo (2 `feature-implementer` em worktree → revisão → cherry-pick individual → teste).
- Integrados os UseCases **`CriarCarteira`** (`454bbf2`) e **`CriarPessoa`** (`f6362f0`), com testes verdes.
- Criado o sistema de execução autônoma/handoff: `EXECUTION_STATUS.md`, `SESSION_HANDOFF.md`, `AUTONOMOUS_EXECUTION_PROTOCOL.md`, `NEW_CHAT_PROMPT.md`.
- Removidas as worktrees do piloto.

## O que ficou parcialmente concluído
- **Onda 2 da Etapa 1 (5 UseCases restantes):**
  - Estado: pendente. Arquivos ainda não existem.
  - A criar: `EditarConfiguracaoCarteira`, `CriarObjeto`, `SugerirPessoasDuplicadas`, `VincularPessoaAObjeto`, `EncerrarVinculo` — cada um com Input DTO + UseCase + teste unitário em `app/src/Cobranca/{DTO,UseCase}/` e `app/tests/Cobranca/Unit/`.
  - Branch/worktree: nenhuma aberta (as do piloto foram removidas).
  - Commit: não há.
  - Falta: implementação + testes + integração + teste cross-tenant real do vínculo.
  - Riscos: `VincularPessoaAObjeto` precisa de guard **same-tenant** (pessoa, objeto e tenant coerentes) — não confiar nos defaults das factories; `SugerirPessoasDuplicadas` precisa de query no `PessoaRepository` e, para performance, de índice (migration do orquestrador).

## Git
- **Branch atual:** `djen-integracao`
- **Último commit:** `f6362f0`
- **Commits criados nesta sessão (na branch):** `454bbf2` (CriarCarteira), `f6362f0` (CriarPessoa) — via cherry-pick dos commits de worktree `761ffd1`/`b42f11b`.
- **Commits integrados:** os dois acima. Diff consolidado: 7 arquivos novos, 400 inserções, nenhum contrato congelado alterado.
- **Worktrees existentes:** só `.worktrees/sincronizacao-drive` (outra feature, não mexer). As do piloto foram removidas.
- **Branches ainda abertas (sobra do piloto):** `worktree-agent-a51e5965e2ec13991`, `worktree-agent-a916f4ddf111afc9a` — apontam para os commits originais já cherry-pickados. **Limpeza recomendada pelo humano:** `git branch -D worktree-agent-a51e5965e2ec13991 worktree-agent-a916f4ddf111afc9a` (branch -D é bloqueado para o Claude).
- **`git status` (ao encerrar):** working tree limpo, exceto os 4 docs de handoff (que este commit registra).

## Testes
- Comandos executados nesta sessão (container `jusprime_php_dev`):
  - `php bin/phpunit --filter CriarCarteiraUseCaseTest` → **OK (2 testes, 17 assertions)**
  - `php bin/phpunit --filter CriarPessoaUseCaseTest` → **OK (2 testes, 21 assertions)**
  - `php bin/phpunit tests/Cobranca` → **OK (4 testes, 38 assertions)**
- Testes falhando: nenhum (no escopo Cobrança).
- Falhas conhecidas: a suíte GLOBAL do projeto NÃO foi rodada nesta sessão (só o domínio Cobrança). Rodar `php bin/phpunit` completo antes de fechar a Etapa 1.

## Decisões tomadas (ainda não plenamente incorporadas em SPEC/PLAN/mapa)
- Pipeline de fan-out autônomo **aprovado** e formalizado em `AUTONOMOUS_EXECUTION_PROTOCOL.md`.
- Regra operacional nova: `git cherry-pick` recebe **apenas o hash** (sem `2>&1`/flags), senão o hook bloqueia.
- Limpeza de branches de worktree fica para o humano (Claude não faz `branch -D`).

## Problemas conhecidos
- ⚠️ **Branch:** Gestão de Cobranças empilhada sobre DJEN em `djen-integracao`. Se `djen-integracao` for para o `master`, leva a feature incompleta junto. Decidir: branch dedicada `gestao-cobrancas` a partir do HEAD, ou aceitar o empilhamento. Nenhuma operação de branch feita.
- Ressalvas menores herdadas do piloto: `CriarPessoaInput.email` sem `#[Assert\Length(max:255)]`; `CriarCarteira` não impõe "forma de honorários exige percentual" (decidir Form vs `Assert\Expression`).
- Índices de dedup ausentes (adicionar via migration do orquestrador quando dedup/import chegarem).
- Permissões `cobrancas` só em dev/test (fixture); prod precisa de data-migration no deploy.

## Próxima ação exata
> Retomar a **Onda 2 da Etapa 1**: implementar os 5 UseCases restantes (`EditarConfiguracaoCarteira`, `CriarObjeto`, `SugerirPessoasDuplicadas`, `VincularPessoaAObjeto` com guard same-tenant, `EncerrarVinculo`) via fan-out (2 frentes: Carteira/Objeto × Pessoa/Vínculo), seguindo `AUTONOMOUS_EXECUTION_PROTOCOL.md`. Depois: teste cross-tenant real do vínculo, `php bin/phpunit tests/Cobranca`, `tenant-safety-review`, e apresentar o commit final da Etapa 1 ao humano.

## Ordem de retomada
1. Ler `NEW_CHAT_PROMPT.md` e seguir a checagem de estado.
2. Confirmar HEAD = `f6362f0` (ou posterior) e working tree limpo.
3. Decidir com o humano a questão da branch (⚠️) antes de novos commits de feature — se ele confirmar seguir em `djen-integracao`, prosseguir.
4. Fan-out dos 5 UseCases restantes (protocolo).
5. Onda 3: cross-tenant + suíte + `tenant-safety-review` + commit final da Etapa 1.
6. Atualizar `EXECUTION_STATUS.md` e este arquivo ao fim.
