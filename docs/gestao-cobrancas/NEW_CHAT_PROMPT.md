# NEW_CHAT_PROMPT — Gestão de Cobranças

> Cole o bloco abaixo como primeira mensagem de um chat NOVO do Claude Code (sem contexto anterior) para retomar a feature com segurança.

---

```
Você vai continuar a feature "Gestão de Cobranças" do projeto JusPrime. NÃO confie em nenhum resumo; confirme tudo no repositório.

1) Leia integralmente, NESTA ordem (todos em docs/gestao-cobrancas/, exceto os CLAUDE.md):
   1. FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md  (a SPEC — fonte de verdade das regras; §23 invariáveis)
   2. PLAN.md                                  (10 etapas de implementação)
   3. PARALLELIZATION_MAP.md                   (ondas, paralelização, lição da 8B: template compartilhado força single-writer)
   4. EXECUTION_STATUS.md                       (panorama VIVO: onde parou, checklist 0–9, histórico, próxima ação exata)
   5. SESSION_HANDOFF.md                        (memória da última sessão: git, testes, próxima ação, ordem de retomada, padrão estabelecido)
   6. AUTONOMOUS_EXECUTION_PROTOCOL.md          (o modo de fan-out autônomo aprovado — papéis, integração, proibições)
   7. docs/specs/cobranca-etapa8-telas-ux.md    (a spec da Etapa 8; a Onda 8C é o alvo)
   8. Os CLAUDE.md aplicáveis: raiz /CLAUDE.md, app/src/CLAUDE.md, app/src/Controller|Entity|Repository|Shared/CLAUDE.md conforme a camada, app/templates/CLAUDE.md, app/tests/CLAUDE.md, docs/AUTORIZACAO.md.

2) Carregue a skill "workflow" antes de tocar em código.

3) Verifique o ESTADO REAL do repositório (não assuma):
   - git branch --show-current   (esperado: gestao-cobrancas)
   - git status --short          (limpo, salvo untracked .claude/worktrees/ e arquivos gitignorados/resíduos de smoke .png)
   - git log --oneline -10       (topo esperado: docs 8B `69faab4` / cadeia 8B `936408a`→`642a9ef`, OU POSTERIOR)
   - docker start jusprime_db_dev jusprime_php_dev jusprime_nginx_dev   (subir dev se preciso)
   - docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'   (esperado: 343/343)
   - (opcional) suíte global: bin/phpunit → esperado 1624/1624

4) COMPARE a documentação com o repositório. Se divergirem (commits a mais/menos, testes falhando, working tree suja, branch diferente), PARE e reporte a divergência antes de agir — corrija o entendimento a partir do Git, não dos docs.

5) A branch já está resolvida: trabalhe em gestao-cobrancas (master ficou só com DJEN; a feature vive só nesta branch). Não faça switch/rebase/move de commits por conta própria. Lineage: DJEN `b044c0c` na BASE é inofensivo (ver memória).

6) ESTADO: a Etapa 8 (Telas/UX) está em ondas. **Onda 8A (LEITURA) e Onda 8B (ESCRITA — TODAS as mutações/forms) estão CONCLUÍDAS, testadas e revisadas** (HEAD `69faab4`, `tests/Cobranca` 343/343, GLOBAL 1624/1624; 2 revisões adversariais + tenant-safety SEM bloqueantes). A 8B ligou: obrigação (registrar/reconhecer), encerrar caso, próxima ação (definir/concluir), tentativa, revisão (gerar/resolver), acordo (criar/romper/cancelar/cumprir), **pagamento (registrar/corrigir sem estorno)**, **liquidação**, **carteira (criar/configurar)**, **objeto (criar)**, **pessoa (criar)**, **vínculo (vincular/encerrar)**, **abrir caso**, **judicializar**, **alterar pessoa cobrada**. **Decisão de negócio já aplicada e mantida: caso encerrado NÃO aceita mutação — guard no SERVIDOR em reconhecer-valor/tentativa/gerar-revisão (não só na UI); nova inadimplência = NOVO caso.** NÃO refaça o que está pronto.

7) **PRÓXIMA AÇÃO EXATA = Onda 8C**:
   (a) **Importação visual** da carteira: fluxo upload→prever(dry-run)→confirmar sobre o `ImportarRelatorioCarteiraUseCase` (Etapa 7, idempotente). **A decisão da Etapa 7 é INTOCÁVEL: linha só-encargos/honorários sem principal é REJEITADA (sem Obrigação principal-zero).** Provável rota/aba na Carteira. Preview antes do commit real.
   (b) **File-manager de documentos do Caso**: religar `public/js/pasta-arquivos.js` (+ css) na aba "Documentos" do `app/templates/cobranca/caso/show.html.twig` (hoje é placeholder). Entidades `CobrancaDocumento`/`CobrancaSecao` e UseCases `EnviarDocumento`/`ExcluirDocumento`/`MoverDocumento`/`CriarSecao`/`RenomearSecao`/`ExcluirSecao` já existem (Etapa 6). Contrato `data-*` 1:1 com o gerenciador de arquivos das Pastas.
   NÃO antecipe a Etapa 9 (Dashboard/central de alertas) — continua pendente.
   Siga o AUTONOMOUS_EXECUTION_PROTOCOL.md; continue autonomamente sem pedir aprovação a cada passo — pare apenas para: (a) commit obrigatório antes de fan-out, (b) decisão de negócio bloqueante, (c) inconsistência grave SPEC/PLAN/código, (d) conclusão de onda/etapa, (e) ~90% de contexto (entrar em modo handoff).

8) CUIDADOS DUROS da 8C (uploads/importação/documentos/segurança):
   - **UPLOAD**: validar mimetype+extensão+tamanho no DTO (`#[Assert\File]`) ou UseCase; NUNCA salvar no controller; usar `ArquivoStorageService`. **Isolamento físico por tenant** já é padrão: `cobrancas/<tenantId>/<hash>` (parâmetro `cobrancas_uploads_dir`; test→`var/uploads-test/cobrancas`). Em DEV, se upload falhar com Permission denied, alinhar dono: `docker exec -u 0 jusprime_php_dev chown -R 1000:1000 /var/www/app/public/uploads`.
   - **IMPORTAÇÃO**: dentro de uma Carteira EXPLÍCITA (§21); NÃO é importador universal (§24); dedup CPF/CNPJ só intra-tenant (índices funcionais da E7); idempotência já garantida pelo UseCase (índice parcial único). Preview NÃO persiste.
   - **TENANT/IDOR**: TODA rota resolve entidade por `findOneByIdDoTenant($id,$tenant)`→404 ANTES de qualquer efeito; NUNCA `find()`/`findOneBy(['id'=>...])` sem tenant. Documentos/seções idem. Selects escopados = `Repository::opcoesDoTenant($tenant)`+`ChoiceType` (nunca `EntityType`).
   - **CSRF**: Symfony Form (automático) ou `isCsrfTokenValid('nome_'.$id, _token)` manual em ação sem campos. **CSRF é STATELESS** (`config/packages/csrf.yaml`, `stateless_token_ids:[submit]`): valida same-origin por Referer/Origin — em TESTE nunca usar `HTTP_REFERER` externo (o BrowserKit já põe o referer interno).
   - **AUTORIZAÇÃO**: módulo `cobrancas` em TODA rota; mutação exige capacidade via `hasPermission` (`resources.cobranca.gerenciar` p/ documentos/importação; `resources.cobranca.movimentacao_financeira` só p/ dinheiro). Padrão no `AutorizacaoCobranca::tenantComCapacidade`.
   - **ARQUIVOS/JS**: o `pasta-arquivos.js` é compartilhado — religar por `data-*` sem editar o JS (padrão da E6/gerenciador de pastas). Controller fino; UseCases flusham internamente.

9) Regras duras de Git: sem push/deploy/produção; sem Git destrutivo (merge/rebase/reset/branch -D); cherry-pick só com um hash (hook `block-git-writes.py`). Commits locais permitidos. Ao aproximar ~90% de contexto, reescreva SESSION_HANDOFF.md e atualize EXECUTION_STATUS.md, deixe a working tree limpa e os commits locais criados, e encerre de forma controlada.
```

---

**Observações para quem cola o prompt:**
- O prompt não faz `push` nem deploy — só trabalho local e commits locais.
- **Estado atual (2026-07-10): Etapas 0–9 CONCLUÍDAS — a IMPLEMENTAÇÃO da feature está COMPLETA** (HEAD `3cd426a`; `tests/Cobranca` 398/398; global 1679/1679). A Etapa 9 (Dashboard `/cobrancas/painel` + Central de Alertas `/cobrancas/alertas`) foi entregue, testada e revisada (SEM bloqueante). O próximo chat **não retoma implementação**: resta só o **preparo de deploy/homologação** (data-migration de permissões `cobrancas` p/ prod + semear grafo no dev + smoke de navegador + deploy), tudo detalhado no `SESSION_HANDOFF.md` §"PRÓXIMA AÇÃO". Não iniciar domínio Financeiro (fora do MVP, §19/§24).
- O passo 7 do prompt abaixo (retomar por 8C) está **desatualizado** — 8C e 9 já foram concluídas; siga o `SESSION_HANDOFF.md`, que é a fonte viva.
- Mantenha este arquivo e os demais versionados; eles são a ponte entre chats.
