# Spec — Cobranças Etapa 8 / Onda 8C: Importação visual + Documentos (file-manager)

> Risco: **MÉDIO/ALTO** (camada HTTP multi-tenant com upload de arquivo, importação em lote e file-manager).
> Base: SPEC §15/§16/§21/§22/§24 + invariáveis §23 (25 = documento sem Pasta) · PLAN §9 · `cobranca-etapa8-telas-ux.md` (Onda 8C).
> Back-end pronto e testado (E6 documentos, E7 importação). Esta onda é **só a camada HTTP** — controllers finos reusando UseCases existentes; nenhuma regra de negócio nova.
> **Escritor ÚNICO (orquestrador).** As duas frentes reusam infraestrutura de front compartilhada (`pasta-arquivos.js`, modais, CSRF stateless) → single-writer (não paraleliza), conforme a lição da 8B.

## Objetivo

Fechar a camada visual da Etapa 8:
- **8C-A Importação visual** de um relatório TOPLIFE para dentro de uma Carteira: `upload → prever (dry-run) → confirmar`, com relatório de importados/atualizados/rejeitados/ignorados e motivos.
- **8C-B Documentos do Caso**: religar o file-manager (`public/js/pasta-arquivos.js`, sem editar o JS) na aba "Documentos" do detalhe do Caso, ligando os UseCases de documento/seção da Etapa 6.

## Decisões INTOCÁVEIS herdadas (não reabrir)

- **E7:** linha só-encargos/honorários sem principal é **REJEITADA** (sem Obrigação principal-zero). O adapter já decide isso; a UI só exibe o motivo. Não criar/alterar regra.
- **INV-25:** documento vive no **Caso**, nunca na Pasta. Ao judicializar, documentos **permanecem** (não migram/duplicam).
- **§22 (autorização):** módulo `cobrancas` em TODA rota; capacidade de papel via `hasPermission` (NÃO per-item ACL). Documentos e importação usam `resources.cobranca.gerenciar`.
- **Dinheiro** = int centavos; saída via `|centavos`. (Relevante ao exibir valores no preview.)
- **Isolamento físico por tenant:** disco em `cobrancas/<tenantId>/<hash>` (bind `$cobrancasUploadsDir`, test→`var/uploads-test/cobrancas`). Contrato congelado da E6.

---

## 8C-A — Importação visual

### Fluxo (stateful de 2 passos)
O adapter lê de um **caminho de arquivo** (`TopLifeInadimplenciaAdapter::ler(string $caminho): ResultadoLeitura`), e o UseCase expõe `prever(int $carteiraId, ResultadoLeitura, Tenant): ResultadoImportacao` (dry-run, não persiste) e `confirmar(int $carteiraId, ResultadoLeitura, Tenant, User): ResultadoImportacao` (transacional, idempotente).

Como `ResultadoLeitura` não é trivialmente serializável, o preview e a confirmação **re-leem o mesmo arquivo**. O arquivo enviado é guardado num **diretório temporário por tenant** entre os dois passos, e o ponteiro fica na **sessão** (por-usuário → sem IDOR):

1. **GET** `/cobrancas/carteiras/{id}/importar` — tela de upload (form `.xlsx`) da carteira `{id}`.
2. **POST** `/cobrancas/carteiras/{id}/importar/prever` — valida upload (Form + `#[Assert\File]` mimetypes xlsx/xls + limite), move o arquivo para `${cobrancasUploadsDir}/import-tmp/<tenantId>/<token>.xlsx`, roda `adapter->ler()` + `useCase->prever()`, guarda na sessão `['cobranca_import'][carteiraId] = {token, nomeOriginal}` e renderiza a **prévia** (ResultadoImportacao + form de confirmação com CSRF).
3. **POST** `/cobrancas/carteiras/{id}/importar/confirmar` — lê o ponteiro da sessão (valida que é da carteira `{id}`), re-lê o arquivo temporário, roda `useCase->confirmar()`, **apaga o arquivo temporário + limpa a sessão**, renderiza o **relatório final** (mesma view do preview, marcada como confirmada). Idempotência do UseCase cobre reenvio.

### Segurança (toda mutação)
- Gate: `tenantComCapacidade('resources.cobranca.gerenciar')` → null = `semAcesso()`.
- Carteira resolvida por `findOneByIdDoTenant($id, $tenant)` → 404 (anti-IDOR) **antes** de qualquer efeito.
- CSRF: Symfony Form (token stateless `submit`, same-origin) nos POSTs de prever e confirmar.
- Upload: validação de mimetype+tamanho no **DTO/Form** (`#[Assert\File(mimeTypes: xlsx/xls, maxSize)]`), nunca salvar no controller sem validar; arquivo temporário isolado por tenant; **token do arquivo gerado no servidor** (nunca caminho vindo do cliente → sem path traversal).
- Preview **não persiste** (dry-run honesto). Importação sempre dentro de UMA Carteira explícita (§21); nunca importador universal (§24).
- Diretório temporário fora do controle do cliente; limpo após confirmar. (Follow-up opcional: coletor de temporários órfãos.)

### Entrada na UI
Botão "Importar relatório" na **visão da Carteira** (`carteira/show.html.twig`), gated por `resources.cobranca.gerenciar`, apontando para a tela de upload. Reusa `|centavos` para exibir valores quando aplicável.

### Arquivos (8C-A)
- `src/Cobranca/Controller/ImportacaoController.php` (novo).
- `src/Cobranca/DTO/ImportarRelatorioInput.php` + `src/Cobranca/Form/ImportarRelatorioType.php` (upload).
- `templates/cobranca/importacao/{upload,preview}.html.twig` (novos).
- edição pontual em `templates/cobranca/carteira/show.html.twig` (botão de entrada).
- `tests/Cobranca/Functional/ImportacaoVisualControllerTest.php`.

---

## 8C-B — Documentos do Caso (file-manager religado)

### Contrato de reuso (o JS `pasta-arquivos.js` NÃO é editado)
O JS é genérico: ancora em `#fileManager` e lê TODAS as URLs/tokens de `data-*`. Para religar em Cobrança, renderiza-se o **mesmo markup** (mesmos ids/classes/`data-*`) apontando `path()` para rotas de Cobrança, provê-se `window.enviarArquivoComProgresso` global, e os controllers devolvem o **mesmo formato de resposta por ação**:

| Ação | Rota (nova) | Método | CSRF token id | Resposta esperada |
|---|---|---|---|---|
| Upload | `cobranca_documento_upload` `/cobrancas/casos/{id}/documentos` | POST | `cobranca_documento_upload_{casoId}` | JSON `{success:true, documento:{id,...}}` (JS recarrega a página) |
| Criar seção | `cobranca_secao_criar` `/cobrancas/casos/{id}/secoes` | POST | `cobranca_secao_criar_{casoId}` | JSON 201 `{id, nome, ordem, csrfUpload, csrfRenomear, csrfExcluir}` |
| Renomear seção | `cobranca_secao_renomear` `/cobrancas/secoes/{secaoId}/renomear` | POST | `cobranca_secao_renomear_{secaoId}` | JSON `{ok:true, nome}` |
| Excluir seção | `cobranca_secao_excluir` `/cobrancas/secoes/{secaoId}/excluir` | POST | `cobranca_secao_excluir_{secaoId}` | JSON `{ok:true}` |
| Mover doc | `cobranca_documento_mover` `/cobrancas/documentos/{docId}/mover` | POST | `cobranca_doc_mover_{docId}` | JSON `{ok:true}` |
| Reordenar docs | `cobranca_documentos_reordenar` `/cobrancas/casos/{id}/documentos/reordenar` | POST | `reordenar_docs_cobranca_{casoId}` | JSON `{ok:true}` (fire-and-forget) |
| Reordenar seções | `cobranca_secoes_reordenar` `/cobrancas/casos/{id}/secoes/reordenar` | POST | `reordenar_secoes_cobranca_{casoId}` | JSON `{ok:true}` (fire-and-forget) |
| Excluir doc | `cobranca_documento_excluir` `/cobrancas/documentos/{docId}/excluir` | POST | `delete_documento_cobranca_{docId}` | redirect 302 p/ caso (form HTML, não-AJAX) |
| Download | `cobranca_documento_download` `/cobrancas/documentos/{docId}/download` | GET | — | serve o arquivo (attachment) |

Chaves de erro espelham o contrato: upload usa `{success:false, error}`; seção/mover/reordenar usam `{erro}` + status HTTP (403 sem permissão, 400 CSRF, 404 não encontrado, 422 validação).

### Reordenar (paridade — 2 UseCases novos)
O file-manager tem drag-reorder (Sortable) que chama `reordenar-docs`/`reordenar-secoes`. Para não editar o JS e manter o file-manager coerente, adicionam-se **2 UseCases** espelhando os da Pasta:
- `ReordenarDocumentosCasoUseCase::executar(CasoCobranca $caso, Tenant $tenant, list<int> $ids): void` — guard tenant + só reordena documentos do próprio caso/tenant (ids estranhos são ignorados; nunca toca doc de outro caso/tenant).
- `ReordenarSecoesCasoUseCase::executar(CasoCobranca $caso, Tenant $tenant, list<int> $ids): void` — idem para seções.
Repositórios ganham helper de reordenação escopado por caso+tenant (atualiza `ordem` sequencial). São mutações → gate + CSRF + tenant + anti-IDOR como as demais.

### Segurança (toda mutação de documento/seção)
- Endpoints AJAX: gate módulo + `resources.cobranca.gerenciar`; em falha → **JSON 403 `{erro}`** (não redirect — é AJAX).
- Entidade resolvida por `findOneByIdDoTenant($id, $tenant)` → 404/JSON 404 (anti-IDOR) antes de qualquer efeito. Nunca `find()` puro.
- CSRF nomeado por ação (stateful, fora do `stateless_token_ids`), espelhando o file-manager das Pastas.
- Upload: reusa `EnviarDocumentoUseCase` (whitelist MIME + limite + isolamento físico por tenant). Controller fino; UseCase flusha.
- Seção de destino / seção informada precisa ser do mesmo caso+tenant (guardas já nos UseCases da E6).
- **Caso encerrado:** documentos permanecem gerenciáveis (arquivamento de comprovantes finais é legítimo; a decisão de "caso encerrado não aceita mutação" é sobre mutação **operacional/financeira** — obrigação/tentativa/revisão/pagamento — não sobre acervo documental). Decisão registrada; sem guard de status nos documentos.

### Wiring na tela
- `templates/cobranca/caso/_documentos.html.twig` (novo): markup do `#fileManager` (root `data-*` com `path()`/`csrf_token()` de Cobrança), cartões de seção, linhas de documento (download link + mover + excluir; **sem** os botões peticionar-específicos preview/editar), modais próprios do FM (`#fmInputModal`, `#fmMoverModal`), input de upload, e `<script>` com `window.enviarArquivoComProgresso` (copiado de `pasta/show.html.twig`). Sem entidades Doctrine cruas → arrays/DTO.
- `templates/cobranca/caso/show.html.twig`: substituir o placeholder da aba por `include('_documentos.html.twig')`; adicionar `id="documentos-tab"` ao botão da aba e renomear `id="casoTabs"`→`id="pastaTabs"` (para o restore de aba pós-reload do JS); carregar `pasta-arquivos.css` (stylesheets) e `sortablejs` + `pasta-arquivos.js` (javascripts).
- `CasoController::show`: passar `secoes` (com seus documentos) + documentos "geral" ao template, mapeados em arrays simples via `CobrancaSecaoRepository::secoesDoCaso`/`CobrancaDocumentoRepository::documentosDoCaso`. Só monta o file-manager de escrita para quem tem `resources.cobranca.gerenciar` (leitor puro vê a lista sem ações de mutação).

### Arquivos (8C-B)
- `src/Cobranca/UseCase/ReordenarDocumentosCasoUseCase.php` + `ReordenarSecoesCasoUseCase.php` (novos) + métodos de repo.
- `src/Cobranca/Controller/DocumentoCobrancaController.php` (novo).
- `templates/cobranca/caso/_documentos.html.twig` (novo) + edição de `caso/show.html.twig`.
- `src/Cobranca/Controller/CasoController.php` (wiring do `show`).
- `tests/Cobranca/Unit/Reordenar*UseCaseTest.php` + `tests/Cobranca/Functional/DocumentoCobrancaControllerTest.php`.

---

## Testes obrigatórios (orquestrador)
- **Unit** dos 2 UseCases de reordenar (happy + guard tenant + ids estranhos ignorados).
- **Functional Importação:** GET upload (200/gate); POST prever (preview sem persistir; rejeitados com motivo exibido); POST confirmar (persiste; idempotência no reenvio); sem capacidade → sem acesso; carteira de outro tenant → 404; CSRF inválido barra; arquivo inválido (mimetype) rejeitado sem persistir.
- **Functional Documentos:** upload (JSON success + doc no caso, sem Pasta); criar/renomear/excluir seção (JSON contrato); mover doc (JSON ok); reordenar (2xx); excluir doc (redirect); download (200 conteúdo); **IDOR**: doc/seção/caso de outro tenant → 404/403; **CSRF** inválido → 400; sem capacidade → 403 JSON.
- Suíte `tests/Cobranca` verde + suíte global verde + `tenant-safety-review` sem bloqueante.

## Fora do escopo (8C)
- Etapa 9 (Dashboard / central de alertas global).
- Preview/edição server-side de documento (peticionar) — não aplicável ao acervo de Cobrança.
- Coletor de temporários de importação órfãos (follow-up).
- Versionamento/OCR de documento; importador universal (§24).
