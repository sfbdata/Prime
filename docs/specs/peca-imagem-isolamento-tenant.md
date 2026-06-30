# Spec — M5: isolamento por tenant da entrega de imagem de peça

> Achado **M5** da `auditoria-pos-remediacao-multitenant.md`. Resíduo conscientemente deferido no
> C5.1 (`uploads-fora-do-public.md`): o `PecaImagemController` (`GET /uploads/pastas/{nome}`) serve
> as imagens embutidas das peças **só com autenticação, sem isolamento por tenant**.
> **Risco MÉDIO** (exposição cross-tenant de PII). **Sem migration de schema.**

## O furo (verificado no código literal, jun/2026)

- **Como a imagem nasce:** `PeticionarController::uploadImagemEditor` (`POST /pasta/{id}/peticionar/imagem`,
  `app/src/Pasta/Controller/PeticionarController.php:227`) — já valida tenant/posse da pasta e CSRF —
  chama `UploadImagemEditorUseCase`, que grava via `ArquivoStorageService::salvar` um arquivo com nome
  `bin2hex(random_bytes(16))` (128 bits) **plano** em `%uploads_dir%` = `public/uploads/pastas`. Retorna
  `{"url":"/uploads/pastas/<hex>.<ext>"}`. **Nenhuma entidade registra a imagem** — ela existe só como
  arquivo solto + `<img src>` cru dentro do HTML da peça (que é um `PastaDocumento` `text/html`).
- **Como é servida (o furo):** `PecaImagemController::servir` (`app/src/Pasta/Controller/PecaImagemController.php:41`)
  resolve `{nome}` no diretório plano e entrega a **qualquer logado**, sem checar tenant. Anti
  path-traversal e restrição de extensão de imagem OK; **isolamento por escritório ausente**.
- **Amplitude maior que "imagens do editor":** `UploadPecaUseCase` (upload de documento — procuração/RG/
  contrato) aceita `image/png`/`image/jpeg` e grava no **mesmo** flat dir. Logo o `PecaImagemController`
  é também um **caminho paralelo não-protegido até as imagens de documento** (um RG em PNG do escritório B
  é baixável por um logado de A que souber o hex). Os documentos têm rota própria protegida
  (`pasta_documento_view`/`download`, `PastaController:1186/1205`, com `canAccessResource`); o furo é a
  rota de imagem servir os mesmos arquivos sem checagem.
- **Vetor:** o nome hex é não-enumerável (128 bits); a exposição real depende do **nome vazar** (peça
  exportada/compartilhada, referer, logs). Prod tem **1 tenant** → ainda não explorável de fato, mas deve
  estar fechado antes do 2º escritório.
- **Sem mapa reverso `nome → pasta/tenant`:** nome aleatório + dir plano + sem entidade. Isolar exige
  **introduzir** o mapa. Cadeia disponível: `PastaDocumento → Pasta → tenant` (ambas TenantAware); no
  **upload** o tenant é conhecido, no **serve** só chega `{nome}` → o serve usa o **tenant da sessão**.

## Decisão (dono): Opção A — subpasta por tenant, URL inalterada

Em vez de pasta única sem dono, cada imagem do editor passa a morar numa **subpasta do tenant dono**
(`pastas/<tenantId>/`). A entrega procura **só na subpasta do tenant da sessão** → cross-tenant 404.
**Não cria entidade, não altera schema, não reescreve o HTML salvo das peças** (a URL embutida continua
`/uploads/pastas/<hex>`; o tenant é injetado pelo controller no disco, não vai na URL).

Rejeitada a Opção B (entidade `PecaImagem` + migration + backfill por parsing de HTML): mesmo isolamento,
muito mais superfície e risco; contradiz o "sem migration".

### Mudanças de código

1. **`PeticionarController::uploadImagemEditor`** — grava na subpasta do tenant. `$tenant` já é resolvido
   ali; **fail-closed**: `$tenant === null` (super-admin sem sessão) → 403 antes do upload. Passa
   `$this->uploadsDir . '/' . $tenant->getId()` como diretório ao `UploadImagemEditorInput`.
   **Retorno inalterado:** `'/uploads/pastas/' . $output->nomeArquivo` (basename; sem tenant na URL).
   `UploadImagemEditorUseCase`/`Input`/`ArquivoStorageService::salvar` **não mudam** (o `salvar` faz
   `mkdir -p` da subpasta).

2. **`PecaImagemController::servir`** — injeta `TenantContext`; **fail-closed**: tenant da sessão null →
   404; resolve `caminho($this->uploadsDir . '/' . $tenant->getId(), $nome)`. Cross-tenant: o arquivo de
   B não está na subpasta de A → 404. Fecha de quebra o caminho paralelo às imagens de documento (que só
   ficam acessíveis pela rota de entidade protegida).

3. **`ExportarPecaTextoUseCase`** — a reescrita das imagens embutidas → caminho de disco passa a apontar
   para a subpasta do tenant do doc (`<tenantId> = $doc->getTenant()?->getId()`). O TinyMCE grava a URL
   **ABSOLUTA** (`/uploads/pastas/<hex>`) **ou RELATIVA** (`../../uploads/pastas/<hex>`, default
   `convert_urls`), então a reescrita usa `preg_replace_callback('#(?:\.{1,2}/)*/?uploads/pastas/#', ...)`
   apontando para `$projectDir/public/uploads/pastas/<tenantId>/`, consumindo o prefixo `./`/`../`/`/` inteiro
   e trocando TODAS as imagens da peça.
   (O `str_replace('/uploads/'...)` antigo deixava o `../..` para trás e **já quebrava** o caso relativo —
   isto é correção colateral, não regressão.) **Guard:** tenant null (degenerado/unit sem DB) → caminho sem
   subpasta. Sem a reescrita, a imagem embutida quebraria no DOCX/PDF exportado (regressão de
   funcionalidade, não de segurança).
   **Follow-up (PDF/Dompdf, pós-deploy prod):** a reescrita dá o caminho CERTO, mas o Dompdf bloqueava a
   leitura do arquivo local por segurança (sem `chroot` → imagem quebrada só no PDF; docx/odt via PhpWord
   liam o disco direto). Fix: `gerarPdf` seta `$options->set('chroot', $projectDir.'/public')`. Bug
   pré-existente (independe do tenant). Teste `testPdfEmbuteImagemDoDisco` (PDF com arquivo presente é maior
   que sem → imagem embutida; mutação remove o chroot → RED).

### Legado / deploy (sem migration; passo de DADOS em prod)

As imagens já existentes estão planas em `public/uploads/pastas/`. Precisam ir para a subpasta do tenant
dono para o **próprio** dono continuar vendo-as (o serve novo só olha `<tenantId>/`). Prod = 1 tenant `T`,
então todas pertencem a `T`. Critério **robusto e DB-backed (sem parsear HTML):**

```
mover para pastas/<T>/  ⟸  { arquivos de imagem em pastas/ } − { PastaDocumento.caminho_arquivo (todos) }
```

Isso exclui automaticamente os HTMLs de peça e as imagens **de documento** (que são `caminho_arquivo` e
continuam planas, servidas pela rota de entidade). Só sobram as imagens **órfãs do editor** → vão para
`<T>/`. Ordem **copy → deploy → cleanup** (zero janela de imagem quebrada): copia para `<T>/`, faz o
deploy do código, depois remove os planos órfãos (já inalcançáveis pelo controller novo + bloqueio nginx
de `/uploads/`). Runbook detalhado em `DEPLOY-PROD-multitenant.md`. **Em deploys novos (multi-tenant do
zero) não há legado** — toda imagem já nasce em `<tenantId>/`.

### Testes

- **`PecaImagemControllerTest`** (ajustado): arquivo criado em `<uploads_dir>/<tenantId>/`.
  - owner logado + imagem na sua subpasta → 200 inline;
  - **cross-tenant** (o vetor M5): imagem de B **na subpasta de B E também solta no flat** (resíduo
    legado), logado em A → **404** (a dupla presença faz este teste matar também a regressão "servir do flat");
  - **arquivo solto no flat** (legado não-movido), logado → **404** — documenta que o legado plano fica
    inacessível até o move do deploy (a razão do passo de runbook);
  - **sem tenant na sessão** (super-admin; o usuário comum é desviado antes p/ `/escritorio/selecionar`)
    → 404 (fail-closed);
  - inexistente → 404; extensão não-imagem não casa a rota → 404 (mantidos).
- **`PeticionarControllerTest`** (upload de imagem): loga COM tenant; assert de que o arquivo **aterrissa
  em `<uploads_dir>/<tenantId>/`** e **NÃO** no flat (URL retornada segue `/uploads/pastas/<hex>`).
- **`ExportarPecaTextoUseCaseTest`**: testes existentes verdes (HTML sem imagem + guard de null); somados
  ABSOLUTO (`/uploads/pastas/x.png` + tenant → subpasta), **RELATIVO** (`../../uploads/pastas/x.png` →
  subpasta limpa, sem `../`), **múltiplas imagens** (absoluta+relativa+`./` na mesma peça → todas trocadas)
  e null → sem subpasta, via reflection em `reescreverImagensParaDisco`.
- **Mutação 2× (confirmada):** serve flat (remover `'/' . tenantId`) → owner + cross-tenant + flat-residue
  RED; upload flat (remover `'/' . tenantId`) → `assertFileExists(<tenantId>/)` RED.

### Nota — usuário multi-tenant (correto-por-design, não vazamento)

Se um usuário vinculado a A e B grava a imagem logado em A (arquivo em `pastas/<A>/`) e depois abrir a
**mesma peça** logado em B, o serve usa o tenant da sessão (B) → 404 (imagem quebrada para o próprio dono).
É **correto-por-design**: a peça pertence ao contexto de A; abri-la em B já seria cross-tenant — e na
prática nem é alcançável, pois `Pasta`/`PastaDocumento` são TenantAware e o `TenantFilter` impede carregar
a peça de A numa sessão de B (find → 404). O serve só reforça o mesmo limite.

### Fora de escopo

- Reestruturar **todo** o `pastas/` (documentos) por tenant — os documentos já são isolados pela rota de
  entidade (`canAccessResource`); aqui só a rota de imagem precisava do escopo.
- Entidade rastreável de imagem de peça (Opção B) — descartada.
