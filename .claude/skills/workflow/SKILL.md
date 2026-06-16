---
name: workflow
description: "Comportamento do orquestrador (o cérebro), ciclo de trabalho com subagentes read-only, regras de documentação/memória e git do jusprime. Carregue sempre no início de qualquer tarefa de implementação, refatoração ou revisão, antes de tocar em código."
---

# Workflow do projeto

## Comportamento do orquestrador (o "cérebro")

A sessão principal atua como orquestrador: entende, discute, planeja e implementa
— mas só após investigar e planejar. Delega a subagentes **read-only** a
investigação e a revisão; a escrita de código (Edit/Write/Bash) fica sempre no
orquestrador, que é o único capaz de exibir os prompts de aprovação por arquivo.

**Antes de agir:**
- Tarefa inequívoca e risco BAIXO → execute, informando o que fará.
- Ambiguidade real, decisão de arquitetura, ou risco ALTO/MÉDIO → pare, faça
  perguntas de escopo e proponha um plano antes de tocar em código.
- Honestidade técnica: se a abordagem pedida tiver problema, discorde com
  fundamento. Não dificulte por esporte; questione quando há motivo real.

**Plano (plan mode):**
- Obrigatório para mudança que toque múltiplos arquivos ou risco ALTO/MÉDIO.
- Trivial de um arquivo (risco BAIXO): pode propor e executar na sequência.

**Risco** (define o rigor acima): ALTO = ponto eletrônico, identidade
User/Tenant · MÉDIO = TenantRole/Permission/Profile · BAIXO = demais.

## Ciclo de trabalho com subagentes

O orquestrador coordena e escreve; subagentes são read-only e devolvem relatório.

1. **Investigar** → subagente investigador (read-only) mapeia impacto e devolve resumo.
2. **Planejar** → orquestrador monta o plano (ver plan mode acima). Em tarefas
   **ALTO/MÉDIO, registra a spec** em `docs/specs/` — ela é o alvo contra o qual
   a revisão confere. No **BAIXO trivial**, a descrição da tarefa basta.
3. **Implementar** → o **orquestrador** aplica as mudanças (controle por arquivo).
4. **Revisar** → `feature-review-agent` (read-only) revisa o diff contra a spec
   (ALTO/MÉDIO) ou contra a descrição (BAIXO): aponta divergências entre o pedido
   e o feito, edge cases e violações dos padrões do CLAUDE.md. Só aponta — não
   conserta. Devolve relatório.
5. **Corrigir** → o **orquestrador** aplica as correções apontadas.
6. **Conferir** → orquestrador confere. Em risco ALTO, devolve ao
   `feature-review-agent` para nova revisão antes de seguir.

**Disparo da revisão:** o passo 4 é acionado pelo comando `/review`, não por
auto-delegação. CLAUDE.md inclina o comportamento, mas não garante; em contexto
longo a auto-delegação falha. Para disciplina ALTO-risco, dispare explicitamente.

## Documentação / Memória

Subagente de docs mantém o estado do projeto (feito, pendente, urgente, prioridades):
- Documentos de estado/progresso (a Memória) → atualiza livremente.
  *(Exceção consciente à regra read-only: escrita permitida por ser risco BAIXO.)*
- Documentos de arquitetura/decisão → **propõe** a mudança ao orquestrador para
  revisão humana; nunca reescreve sozinho.

## Git

Ver regra no root CLAUDE.md. Resumo: orquestrador monta e explica todos os
comandos git, entrega em bloco prefixado com `# Execute manualmente no terminal
externo`, mostra antes, você aprova sim/não. Nunca executa git de escrita;
`block-git-writes.py` permanece ativo.
