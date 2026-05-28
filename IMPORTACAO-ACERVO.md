# IMPORTACAO-ACERVO.md

Documento-mãe da frente de importação do acervo. Branch: `import/acervo-pastas`.
Atualizar no mesmo commit da mudança que descreve.

> **Atenção — branch mista:** além dos commits de importação, esta branch contém
> commits paralelos de pasta/peticionar (ver seção "Commits paralelos" abaixo).
> O merge para `master` exige cherry-pick ou PR separado para cada frente.

## Objetivo
Importar o acervo real do escritório do Dr. Farlei (1023 pastas + ~20GB de arquivos) do Google Drive para o jusprime, em 4 fases. Dado real em produção (bluejus) → tratado como risco MÉDIO/ALTO.

> Nota: o commit 5511918 tem label "WIP, sem review" na mensagem. Esse label está desatualizado — o conteúdo foi revisado e testado nesta sessão (correções de BOM e NUP-grudado feitas via amend no próprio commit). O commit NÃO foi reescrito.

## Estado por fase

### Fase 0 — Extração e parsing → CONCLUÍDA, validada em 5511918
- Extração via `rclone lsf --drive-root-folder-id=... --dirs-only`. Total: 1023 pastas.
- `AcervoNomesParser` + `ParsearAcervoCommand` (`app:acervo:parsear`): lê .txt, normaliza (UTF-8, `／`→`/`, `–`→`-`, espaços, NUP-grudado `^(\d+)\s*-\s*`), segrega pendências, classifica confiança, gera 3 CSVs. Reconciliação `==1023` obrigatória (FAILURE se não fechar).
- Resultado real: 941 alta_confianca / 9 revisao_manual / 73 pendencias = 1023.
- Pendências (73): 50 nup_repetido (24 NUPs), 14 pasta_vazia, 6 nup_duplo, 2 linha_removida, 1 pasta_equipe.
- Conferido à mão: litígio (cliente|contraparte|ação), NUP-grudado, cliente-invertido.

### Fase 1 — Import das pastas → CONCLUÍDA em produção (28/05/2026)
- `ImportarAcervoCommand` (`app:acervo:importar`): lê CSV (BOM consumido antes do fgetcsv, `;`, enclosure `"`, leitura por nome de coluna), mapeia nup/cliente/ação, insere via `CriarPastaUseCase`. Opções: `--csv --tenant-id --usuario-id --amostra --dry-run --pular-erros`.
- Testado em DEV: 941 inseridas; idempotência comprovada (reexecução = 0 importadas, 941 puladas, 0 erros).
- Confirmado: `CriarPastaUseCase` não cria registro em `pasta_documento`; tenant/user resolvidos em CLI via repositório.
- Falta: rodar em produção (backup feito), `--amostra=20` → conferir no bluejus → lote.

**Produção (28/05/2026):**
- Branch `deploy/acervo-import` (cherry-pick de 4 commits sobre master) deployada via `scripts/deploy-prod-tls.sh`.
- Backup pré-carga: `/var/backups/jusprime/jusprime_20260528_205704.tar.gz`.
- Sequência: dry-run amostra=20 → conferido vazio no DB (rollback OK) → import amostra=20 (20 importadas) → conferência visual no bluejus (Dr. Farlei OK) → lote completo.
- Resultado: **941 processadas / 921 importadas / 20 puladas / 0 erros** (idempotência cobriu o overlap das 20 da amostra).
- Backup pós-carga: `/var/backups/jusprime/jusprime_20260528_211913.tar.gz`.
- Achado colateral (NÃO escopo desta frente): página de listagem de pastas no bluejus não tem paginação — todas as 941 carregam de uma vez. Pendência de UX, frente própria.


### Review — commit 5511918 (feature-review-agent, 28/05/2026)

6 achados: 2 críticos, 2 altos, 2 médios.

**Corrigidos no commit 0b08483:**
- **CRÍTICO 1 — EM fechado cascateia:** com `--pular-erros`, o loop continuava após erro grave que fecha o EntityManager; todas as linhas seguintes falhavam. Fix: `isOpen()` no `catch` aborta o import imediatamente, independente de `--pular-erros`.
- **CRÍTICO 2 — dry-run não exercitava persistência:** `--dry-run` pulava o UseCase e reportava "Importadas: N" sem tocar Doctrine ou DB. Fix: dry-run persiste via transação revertida (`beginTransaction → executar → rollBack`); resumo mostra "Simuladas". Gate "amostra → conferir → lote" agora válido.
- **MÉDIO 5 — reconciliação ausente:** loop encerrava sem detectar falha silenciosa de `fgetcsv` antes do EOF. Fix: `feof()` pós-loop; FAILURE com detalhes se o arquivo não foi lido até o fim.
- **MÉDIO 6 — PII no log do dry-run:** nome de cliente e ação apareciam no scrollback/docker logs. Fix: log exibe apenas linha+NUP.

**Dívidas aceitas (decisão consciente, sem correção):**
- **ALTO 3 — `--usuario-id` sem validação de pertencimento ao tenant:** aceito para operação manual com supervisão direta, 1 tenant em prod, IDs conhecidos. Mitigação: operador confirma IDs reais antes de executar em prod.
- **ALTO 4 — `findOneBy(['nup'])` sem filtro de tenant; UNIQUE global no schema:** idem entrada em "Riscos conhecidos". Aceito com 1 tenant em prod; torna-se bloqueante ao entrar 2º tenant.

### Fase 2 — Cópia dos ~20GB → NÃO INICIADA
- Acesso ao Drive hoje via link compartilhado (frágil para 20GB).
- Barra fullwidth `／` no nome original importa para o path.

### Fase 3 — Organização dos documentos → NÃO INICIADA
- Nota: o sistema já tem `PastaSecao` (seções estruturais com FK secao_id).
  O commit 8bdfa3c (frente paralela) já usa essa infraestrutura no peticionar
  (upload + editor), validando o modelo antes da fase de organização massiva.

## Decisões arquiteturais
- Extração por `rclone` (fiel, sem reinterpretar encoding).
- Parsing por código determinístico, não LLM (auditável, reproduzível).
- CSVs: UTF-8 com BOM, delimitador `;`, campos entre aspas, CRLF.
- Confiança: NUP sempre extraível; cliente suspeito (vazio/doc-type) → revisão; ação opcional (best-effort), nunca força fronteira.
- Prioridade de campos: NUP obrigatório, cliente importante, ação opcional, parte_contraria DESCARTADA (sistema só tem 3 campos).
- Inserção pela porta da frente (`CriarPastaUseCase`), tenant=1 (Farlei), usuario=1 (Dr. Farlei, criadoPor).
- Idempotência por NUP (único dentro das 941; repetidos segregados em pendências).
- CSVs NÃO commitados (repo público + PII) → transferir à VPS por scp/docker cp.

## Riscos conhecidos
- **Branch mista (importação + commits paralelos de pasta):** merge direto para `master`
  entrega tudo junto. Separar em PRs distintos antes do merge.
- Repositório PÚBLICO (SaaS jurídico). Acervo .txt confirmado NÃO vazado (untracked, movido p/ `tmp/acervo/`). Repo público = risco de governança em aberto.
- Unique constraint de NUP é GLOBAL, não `(nup, tenant_id)` — bloqueará import de um 2º escritório. Dívida técnica registrada.
- Acesso Drive por link compartilhado frágil para Fase 2.
- Command loga 941 linhas `[pulada]` na reexecução — ruído; resumir no futuro.
- **Lacuna de tenant em UseCases legados de pasta (defesa em profundidade):** ver
  `REFATORACAO-DOMINIOS.md` (pendência registrada lá). O fluxo do peticionar
  (upload + editor) foi parcialmente fechado em 8bdfa3c.

## Commits paralelos nesta branch (fora da frente de importação)

- **3641e9e** `feat(pasta): pre-seleciona Demais Documentos no upload`
  `app/templates/pasta/show.html.twig` — funções JS `buildSelectOpcoes()` e
  `buildOpcoesSecao()` passam `selected` quando `valor === 'DEMAIS'`.

- **8bdfa3c** `feat(peticionar): adiciona selecao de secao no upload e editor`
  Permite associar documento a uma `PastaSecao` ao fazer upload ou criar texto
  no peticionar. Inclui `findByIdAndPastaAndTenant()` no repositório e validação
  de tenant em `UploadPecaUseCase` + `SalvarPecaTextoUseCase`.

- **65ae6da** `fix(pasta): remove duplicacao visual de doc com secao em geral`
  `PastaController` (agrupamento): documentos com `secao != null` são omitidos da
  área "Documentação Geral". Teste funcional em `PastaShowDocumentosControllerTest`.

- **770644f** `chore: remove sidecars :Zone.Identifier e ignora o padrão`
  Housekeeping: remove arquivos `*:Zone.Identifier` gerados pelo Windows/WSL e
  adiciona o padrão ao `.gitignore`.

## Pendências
- [ ] Manter este doc atualizado NO MESMO commit das mudanças que descreve.
  Regra violada nos 4 commits paralelos (3641e9e, 8bdfa3c, 65ae6da, 770644f) —
  esta atualização é débito retroativo via commit `docs()` separado. Corrigir
  prospectivamente.
- [x] ~~Testar os 4 fixes em DEV~~ — validados em 28/05/2026 (fix 2, 4, 6 em runtime; fix 1, 3 por inspeção).
- [x] ~~Import em produção~~ — concluído em 28/05/2026 (ver Fase 1).
- [ ] Tratar manualmente as 82 pastas (9 revisão + 73 pendências).
- [ ] Decisão sobre o repo público.

## Em aberto / não decidido
- Caminho final do CSV na VPS (evitar `/tmp` compartilhado).
- Quando/como tratar a dívida do constraint global de NUP.
