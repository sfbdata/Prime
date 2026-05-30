# FASE2-ARQUIVOS-DRIVE.md

Documento-mãe da Fase 2 da importação do acervo. Branch:
`feature/fase2-arquivos-drive`. Atualizar no mesmo commit da mudança que
descreve.

## Objetivo

Copiar ~15 GB / ~30k arquivos do Google Drive ("01 → PROCESSO JUDICIAL - GRUPO
PRIME", 1025 pastas raiz) para as 941 pastas correspondentes em produção
(bluejus.com.br).

Regras de mapeamento (decididas em 29/05/2026):
- Pasta no Drive → pasta no sistema (match por NUP extraído do nome).
- Subpasta no Drive → seção (PastaSecao) no sistema.
- Subpasta de subpasta → arquivos achatam pra seção avó (sistema só tem 1 nível).
- Arquivo solto na raiz → Documentação Geral (sem seção).
- Todos os arquivos categorizados como CATEGORIA_DEMAIS.
- Arquivos sem extensão sobem como estão.

## Dependências (já cumpridas no deploy fa8290c)

- MIMEs ampliados no UploadPecaUseCase: text/plain, application/zip,
  application/pkcs7-signature, audio/opus.
- Limites PHP: upload_max_filesize 65M, post_max_size 70M.
- Limites nginx: client_max_body_size 70m.

## Estado por etapa

### Etapa 0 — Levantamento do Drive → EM CURSO
- Mapeamento parcial gerado em /tmp/acervo-fase2/pastas-drive.json (1025 dirs raiz).
- Amostra de 8.9k itens varridos: PDF (59%), DOCX (15%), imagens (15%), texto
  (3%). Zero arquivos nativos do Drive (gdoc/gsheet/gslides).
- Profundidade observada: 0 (raiz), 1 (subpasta) e 2 (sub-subpasta).
  ~10% dos arquivos estão em sub-subpasta.
- Volume total estimado: ~15 GB.

### Etapa 1 — Mapeamento Drive→Sistema → CONCLUÍDA (29/05/2026)
- Extrair NUP de cada nome de pasta do Drive (reusar parser AcervoNomesParser).
- Cruzar com SELECT id, nup FROM pasta WHERE tenant_id=1 em prod.
- Gerar CSV: nup, drive_folder_id, drive_name, sistema_pasta_id.
- Reportar lacunas: pastas no Drive sem match no sistema (e vice-versa).

**Resultado em 29/05/2026 (banco DEV restaurado de backup pré-deploy de prod):**
- Comando: `app:acervo:mapear` em `app/src/Command/MapearAcervoCommand.php` (commit 3c5cd9e).
- 941 pastas mapeadas (cobrem 100% das pastas do sistema).
- 84 pastas do Drive sem match: 50 nup_duplicado_drive, 23 nup_nao_extraido, 11 nup_nao_existe_no_sistema.
- 0 pastas do sistema sem match no Drive.
- Reconciliação: 941 + 84 = 1025 ✓
- Os 11 "nup_nao_existe_no_sistema" são possivelmente pastas criadas no Drive
  após a Fase 0. A revisar com Dr. Farlei depois.

### Etapa 2 — Decisão de arquitetura do pipeline → CONCLUÍDA (30/05/2026)
- **Decisão:** rclone baixa tudo para a VPS em `/opt/jusprime-acervo-download/pastas/<pasta_id>/`;
  comando Symfony lê o filesystem e grava no storage do sistema.
- Sem chamada à API do Drive em runtime — evita rate limit.
- Idempotência por par `(pasta_id, nome_original)` via query SQL antes de cada arquivo.
- Seções: lookup em memória após `findByPasta()` + comparação UPPERCASE/trim.

### Etapa 3 — Implementação do comando → EM DESENVOLVIMENTO (30/05/2026)

**Comando:** `app:acervo:copiar-arquivos`
**Arquivo:** `app/src/Command/CopiarArquivosAcervoCommand.php`

Opções:
- `--diretorio=PATH` raiz onde estão as subpastas `<pasta_id>/`
- `--tenant-id=N` tenant alvo
- `--limit=N` processar só as primeiras N pastas (teste)
- `--pasta-id=N` processar só uma pasta (debug)
- `--arquivos-grandes-csv=PATH` CSV onde arquivos > 65 MB são registrados para revisão manual

Comportamento:
- Subpastas imediatas → criam `PastaSecao` (nome normalizado UPPERCASE/trim).
- Sub-subpastas são achatadas: arquivos vão para a seção avó.
- Arquivos na raiz da pasta → sem seção (Documentação Geral).
- Todos os documentos com `categoria=DEMAIS`.
- Storage: `ArquivoStorageService::salvarConteudo(file_get_contents($path), ...)`.
- Chave de idempotência: `SELECT id FROM pasta_documento WHERE pasta_id=:p AND nome_original=:n`.
- Flush + `em->clear()` por pasta — evita acúmulo de entidades no EntityManager.
- Arquivos > 65 MB são pulados e opcionalmente registrados em CSV (`--arquivos-grandes-csv`).
- Erros por arquivo são logados e não interrompem a execução.
- Ignora `.DS_Store`, `Thumbs.db`, `desktop.ini` e qualquer dot-file.

### Etapa 4 — Teste em escala reduzida (WSL) → NÃO INICIADA

### Etapa 5 — Carga em massa em produção → NÃO INICIADA

## Decisões arquiteturais (a tomar nas próximas etapas)

(vazio — preencher durante o trabalho)

## Riscos conhecidos

- 1025 pastas raiz vs 941 no sistema: as 84 a mais correspondem às pendências
  (82) + ruído (2) da Fase 0. Mapear vai produzir um delta importante.
- API do Drive tem rate limit. Pipeline ingênuo pode ser bloqueado.
- Volume (~15 GB): se rodar via API HTTP do jusprime, vai ser lento; melhor
  rclone direto pra VPS.
- Arquivos > 65 MB não passam (limite PHP). Se houver, precisa decidir se
  pula ou se aumenta limite ainda mais. Levantar antes da carga.

## Pendências
- [x] ~~Etapa 1: gerar mapeamento Drive→Sistema (CSV).~~ — concluída em 29/05/2026.
- [x] ~~Etapa 2: definir arquitetura do pipeline.~~ — concluída em 30/05/2026.
- [ ] Etapa 3: implementar e validar comando (EM DESENVOLVIMENTO).
- [ ] Etapa 4: testar 5-10 pastas no WSL.
- [ ] Etapa 5: rodar em produção.
