# Prompt para o novo chat — Central de Acompanhamento (Fatia 1)

Copie o bloco abaixo inteiro como primeira mensagem do chat novo.

---

Implementar a **Fatia 1 da Central de Acompanhamento da Cobrança** (aba Atividade) no JusPrime.

**Leia primeiro, nesta ordem:**

1. `docs/specs/cobranca-central-acompanhamento.md` — a spec validada com o dono. É o contrato: o que
   está lá é o alvo, o que está no §12 ("O que NÃO fazer") é proibido mesmo que pareça fácil.
2. `CLAUDE.md` (raiz) e `app/src/CLAUDE.md` — padrões do projeto.
3. `app/tests/CLAUDE.md` — DAMA, Foundry v2, atributos PHPUnit.

**Contexto de estado (importante):**

- Branch atual: `cobranca-ajustes-pos-taxa-exec`. Ela carrega 9 ajustes + correções **ainda não
  publicados** (push/merge/deploy = humano). Confirme a base antes de qualquer commit:
  `git status`, `git branch -vv`, `git log --oneline -5`, `git worktree list`.
- Crie **branch nova a partir dela** para esta feature (ex.: `cobranca-central-atividade`) — não
  commite a central junto dos ajustes pós-taxa.
- O banco de **dev foi zerado** em 2026-07-22 e repovoado por importação do relatório TOP LIFE II
  (~587 obrigações). Backup em `~/backup-cobrancas-dev-20260722-0956.sql`.
- Suíte hoje: `tests/Cobranca` **978** · global **2349**. Tem de continuar verde.
- Banco `saas_test` é **compartilhado** com outros chats: não rode a suíte completa em paralelo com
  outra sessão.

**O que construir (resumo — a spec manda):**

Rota `/cobrancas/central` com o esqueleto de 4 abas (só **Atividade** preenchida; as outras exibem "em
construção"). A aba Atividade é uma tabela por pessoa, com total do setor no topo, e colunas:
Contatos · Falou com o devedor · Acordos fechados · Baixas registradas · Última ação. Clicar numa linha
abre o detalhe (desfechos em pastilhas + lista dos eventos). Filtros de período e carteira.

Fonte única: `cobranca_evento_historico` agregado **no banco** (`GROUP BY usuario_id` +
`COUNT(*) FILTER`), nunca em PHP sobre a coleção. O desfecho do contato vem do payload JSON
(`dados->>'resultado'`, com o `value` do enum `ResultadoContato`).

**Ordem de trabalho (o projeto exige testes antes):**

1. Escreva o teste unit do `MontarAtividadeEquipeUseCase` (casos do §10 da spec) — veja falhar.
2. Implemente Repository → UseCase → DTO → Controller → Twig.
3. Escreva o functional do `CentralController`, incluindo **isolamento cross-tenant** e o 404 de
   carteira de outro tenant (anti-IDOR). Isso não é opcional: multi-tenancy é inegociável no projeto.
4. Migração do índice `(tenant_id, ocorrido_em)` — aplique em `saas_test` e no dev; **produção é do
   humano**.
5. Rode `tests/Cobranca` e a suíte global.
6. Smoke real no dev (login `jusprime.samuel@gmail.com` / `Prime123!`), tema claro **e** escuro.
7. `/review` com o `feature-review-agent` contra a spec, corrija o que aparecer, commit local.

**Cuidados específicos desta feature:**

- Quem não trabalhou aparece **zerado**, não some da lista — é a informação que o gestor foi buscar.
- Evento com `usuario_id` nulo vai para a linha "Sem responsável"; nunca atribua a alguém.
- Não crie score/ranking/semáforo, não some R$ por funcionária, não mexa no registro de contato
  (congelado por decisão do dono), não use `audit_log` como fonte de produtividade.
- A tabela não pode gerar rolagem horizontal; detalhe fica sob demanda, em rota própria.

**Pendências que podem mudar o desenho (não bloqueiam começar):**

O dono está respondendo o questionário `docs/gestao-cobrancas/PERGUNTAS_CENTRAL_COBRANCAS.md`. Se a
resposta 3.4 for "quero acompanhar promessa de pagamento", isso é **feature de escrita nova** (os tipos
`NovoPrazo`/`Negociacao` existem no enum mas nenhum código os grava) e passa na frente das abas 2–4.
Se as respostas chegarem durante a implementação, pare, leia e reavalie as colunas antes de seguir.

---

## Anexo — decisões já fechadas (não reabrir sem o dono)

| Tema | Decisão |
|---|---|
| Estrutura | 4 abas: Atividade · Resultado · Pendências · Extrato do devedor |
| Métrica | volume e efetividade lado a lado; sem nota única, sem ranking |
| Acesso | todos que têm o módulo `cobrancas` veem tudo (dentro do tenant) |
| Saída | tela agora; PDF/Excel em fatia futura |
| Abordagem | leitura pura do que já se registra; nenhuma escrita nova |
| Cadastros atualizados | **fora** da Fatia 1 — a auditoria não sustenta (40 % sem autor, `entity_id` nulo em creates) |
| Registro de contato | permanece como está (modal com campos classificados) |
