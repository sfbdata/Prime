# Spec — Gerenciador de Arquivos da Pasta (redesign das Seções)

**Risco:** BAIXO (UI/UX; sem tocar identidade, ponto, permissões).
**Branch:** `gerenciador-arquivos-pasta` (só para teste/avaliação).
**Objetivo:** transformar as "Seções de documentos" (`PastaSecao`) — hoje cards
azuis hardcoded numa coluna estreita, sem tema escuro — num **file manager
profissional e confortável**, em largura total, dentro de uma aba dedicada.

## Decisões aprovadas pelo usuário
1. **Escopo:** file manager profissional com UX confortável.
2. **Layout:** aba **"Documentos"** em **largura total** (6ª aba do `#pastaTabs`
   existente em `app/templates/pasta/show.html.twig`).
3. **Corpo:** **navegável (drill-down)** estilo Drive — raiz mostra pastas +
   arquivos gerais; clicar numa pasta entra nela com breadcrumb.
4. **Sem migration** nesta branch. Cor/ícone por pasta, favorito e "quem subiu"
   ficam como fase 2 opcional.

## Estado atual (do levantamento)
- `PastaSecao`: `nome` (UPPERCASE), `ordem` (existe, mas **sem UI/rota** de
  reordenar), `criadaEm`, `pasta`, `tenant`, `documentos`. Sem cor/ícone/tipo.
- `PastaDocumento` **já persiste** metadados ricos: `titulo`, `categoria` (7),
  `descricao`, `nomeOriginal`, `mimeType`, `tamanhoBytes`, `carregadoEm`,
  `numero`, `secao` (nullable = geral), `ordem`. Sem "quem subiu", sem flags.
- Ícone de arquivo hoje é **fixo** (`bi-file-earmark-text`). Modelo de ícone por
  tipo existe em `app/src/Entity/ServiceDesk/ChamadoAnexo.php::getIcone()`.
- Página **já usa abas** Bootstrap (`#pastaTabs`, 5 abas). Documentos ficam fora,
  no `col-lg-4` (`show.html.twig:749–1069`). CSS/JS de docs/seções é **inline**.
- Rotas de seção existentes em `PastaSecaoController`: criar, renomear, excluir,
  mover documento entre seções, reordenar **documentos**. Drag-drop: SortableJS.

## Entregas
### Back-end (sem migration)
- **`ReordenarSecoesUseCase`** — recebe a pasta + lista ordenada de IDs de seção,
  valida tenant/propriedade, grava `ordem`. Espelha `ReordenarDocumentosUseCase`.
- **Rota** `pasta_secoes_reordenar` (POST `/pasta/{id}/secoes/reordenar`) no
  `PastaSecaoController`, JSON + CSRF + `PermissionChecker(pasta, edit)`.
- **Teste** unit do UseCase + functional da rota (incl. isolamento cross-tenant).

### Twig helper de ícone por tipo
- Extensão Twig `arquivo_icone(nomeOriginal, mimeType)` (snake_case, como as demais
  em `src/Twig/`) que devolve `{icone, cor, rotulo}` — PDF, Word, imagem, planilha,
  zip, etc. Modelada em `ChamadoAnexo::getIcone()`. Fallback genérico. Também um
  filtro `formatar_bytes`.

### Front-end
- **`public/css/pasta-arquivos.css`** — estilos do file manager, **tema-aware**
  (variáveis `--bs-*`), reaproveitando `.btn-action*`. Substitui os azuis
  hardcoded (`#5b9bd5`/`#3f7bbf`/`#ced4da`).
- **`public/js/pasta-arquivos.js`** — comportamento: navegação drill-down (raiz ↔
  dentro da pasta) + breadcrumb; alternância **lista/grade**; **ordenar** por
  nome/tamanho/data; drag-drop (arrastar arquivo sobre cartão de pasta = mover;
  arrastar dentro = reordenar); reordenar pastas; criar/renomear/excluir seção;
  upload por pasta; estados vazios. Reaproveita rotas existentes + a nova.
- **`show.html.twig`**: adiciona a 6ª aba "Documentos" (badge de contagem);
  a faixa de abas passa a `col-12` (largura total) e o `col-lg-4` de documentos é
  **removido**, com o markup recortado para o novo `tab-pane`; conteúdo das
  outras abas mantido confortável (não esticar formulários). Liga o CSS/JS novos.

## Fora de escopo (fase 2, só se pedido)
Cor/ícone por pasta (migration), favorito/principal, registro de quem fez upload,
subpastas aninhadas (mais de um nível), miniaturas de imagem na grade.

## Ajustes pós-revisão (feature-review-agent)
- **I-1 (multi-tenant):** `ReordenarDocumentosUseCase` passou a filtrar por tenant
  (`findBy(pasta, tenant)`), fechando IDOR de escrita cross-tenant na rota irmã
  `pasta_documentos_reordenar` que o drag de arquivos aciona. Rota nova de seções
  já era segura.
- **I-2:** testes de isolamento cross-tenant para reordenar seções **e** documentos.
- **I-3:** ordenação **"Manual"** (por `ordem`) adicionada; arrastar arquivo agora
  fixa a ordem no DOM + ativa o modo manual, senão o re-sort apagava o arraste.
- **Menor:** lista de arquivos passou a vir de `pasta.documentos` (mostra também
  documentos de categoria fora do `documentTypeOptions`, ex.: CONTRATO geral);
  `accept` do upload alinhado à whitelist de MIME do `UploadPecaUseCase`.

## Critério de sucesso
Aba "Documentos" em largura total; navegação por pastas com breadcrumb; ícones
por tipo; lista/grade + ordenação; mover/renomear/excluir/reordenar pastas e
arquivos funcionando; **tema escuro correto**; suíte verde; isolamento
multi-tenant preservado (rota nova filtra por tenant).
