---
description: Revisa as mudanças não commitadas via feature-review-agent (read-only). Usa o contexto da sessão como alvo; aceita spec/descrição opcional. Não conserta — só aponta furos.
argument-hint: (opcional) caminho de spec em docs/specs/ ou descrição
---

Acione o subagente `feature-review-agent` para revisar as mudanças atuais.
Você (orquestrador) NÃO revisa nem conserta aqui — apenas delega, recebe o
relatório e o repassa.

**Alvo da revisão:**

1. Defina o alvo nesta ordem de prioridade:
   - Se $ARGUMENTS for um caminho para arquivo em `docs/specs/`, use-o como spec.
   - Se $ARGUMENTS for um texto, trate como descrição da tarefa.
   - **Se $ARGUMENTS estiver vazio (caso comum), use o contexto desta sessão**:
     a tarefa que acabamos de discutir e implementar É o alvo. NÃO peça ao
     usuário para redigitar o que ele já descreveu. Só peça uma descrição se a
     sessão não tiver contexto algum sobre o que foi mudado.

2. Determine o risco da mudança pela tabela do CLAUDE.md
   (ALTO = ponto eletrônico, identidade User/Tenant · MÉDIO = TenantRole/
   Permission/Profile · BAIXO = demais). Em ALTO/MÉDIO deveria existir uma spec
   em `docs/specs/`; se não houver, registre isso como achado.

3. Despache o `feature-review-agent` para executar no contexto isolado dele,
   passando o alvo do passo 1. O agente já sabe seu protocolo (rodar
   `git status`/`git diff`, ler o conteúdo literal, buscar prova antes de
   afirmar, os 14 princípios). Reforce apenas o foco desta revisão:
   - Divergência entre o que foi pedido (alvo) e o que o diff faz.
   - Edge cases não tratados — atenção a tenant isolation / IDOR em qualquer
     query que carregue tenantId vindo de rota (padrão B-route: lookup por
     repositório, nunca getCurrentTenant()).
   - Violações dos padrões do CLAUDE.md e das skills de camada (UseCase,
     1 controller/domínio, padrões A/B/C/D).
   - Só apontar, com arquivo:linha e severidade. NÃO conserta.

4. Repasse o relatório do subagente para mim sem alterá-lo. Em seguida, proponha
   as correções (que você, orquestrador, aplicará no próximo passo do ciclo —
   não agora). A decisão de aprovar é minha, não sua nem do subagente.

Se o risco for ALTO, lembre no fim que o ciclo exige re-revisão (`/review`)
após as correções, antes de seguir.
