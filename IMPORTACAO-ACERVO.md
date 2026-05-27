# IMPORTACAO-ACERVO.md

Documento-mãe da frente de importação do acervo. Branch: `import/acervo-pastas`.
Atualizar no mesmo commit da mudança que descreve.

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

### Fase 1 — Import das pastas → mecânica VALIDADA em DEV; produção PENDENTE
- `ImportarAcervoCommand` (`app:acervo:importar`): lê CSV (BOM consumido antes do fgetcsv, `;`, enclosure `"`, leitura por nome de coluna), mapeia nup/cliente/ação, insere via `CriarPastaUseCase`. Opções: `--csv --tenant-id --usuario-id --amostra --dry-run --pular-erros`.
- Testado em DEV: 941 inseridas; idempotência comprovada (reexecução = 0 importadas, 941 puladas, 0 erros).
- Confirmado: `CriarPastaUseCase` não cria registro em `pasta_documento`; tenant/user resolvidos em CLI via repositório.
- Falta: rodar em produção (backup feito), `--amostra=20` → conferir no bluejus → lote.

### Fase 2 — Cópia dos ~20GB → NÃO INICIADA
- Acesso ao Drive hoje via link compartilhado (frágil para 20GB).
- Barra fullwidth `／` no nome original importa para o path.

### Fase 3 — Organização dos documentos → NÃO INICIADA
- Nota: o sistema já tem `PastaSecao` (seções estruturais com FK secao_id).

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
- Repositório PÚBLICO (SaaS jurídico). Acervo .txt confirmado NÃO vazado (untracked, movido p/ `tmp/acervo/`). Repo público = risco de governança em aberto.
- Unique constraint de NUP é GLOBAL, não `(nup, tenant_id)` — bloqueará import de um 2º escritório. Dívida técnica registrada.
- Acesso Drive por link compartilhado frágil para Fase 2.
- Command loga 941 linhas `[pulada]` na reexecução — ruído; resumir no futuro.

## Pendências
- [ ] Commitar este doc em `import/acervo-pastas`.
- [ ] Import em produção (amostra 20 → lote → smoke no bluejus).
- [ ] Tratar manualmente as 82 pastas (9 revisão + 73 pendências).
- [ ] Decisão sobre o repo público.

## Em aberto / não decidido
- Caminho final do CSV na VPS (evitar `/tmp` compartilhado).
- Quando/como tratar a dívida do constraint global de NUP.
