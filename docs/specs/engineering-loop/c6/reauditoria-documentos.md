# Reauditoria C6: Documentos e Visualizador (prefixo DOC)

Auditoria somente leitura de 12/10/2026 sobre o master `4a98cc6b`. Só este arquivo foi escrito. O visual (cor, raio,
posição) foi conferido **lendo CSS e template, não na tela**. O comportamento de JS foi conferido lendo o fonte; não há
harness de comportamento.

## Fontes lidas

- **Desenho.** `02 - EXPEDIENTES 1.2.3.dc.html`: l.2100-2200 (checklist, painel Sugerir, duplicados), l.295-330 (visor),
  l.3080-3090 (`TIPOS_DOC`/`expGrupo`), l.3745-3765 (zip), l.4634-4700 (`visorVals`, `expTipo`, `expLimpeza`).
  `bj-visualizar.js`, inteiro (tipos, avisos, `imprimir`/`capturar`).
- **Mapa.** `inventario-documentos.md`, inteiro. Ledger `engineering-loop-fidelidade-designer.md`: §3.1 (linhas de
  DOC-*, SEC-001/004, L6) e achados l.225-282. `fila-a-restante.md`: grep DOC.
- **Código.**
  - `app/templates/pasta/_documentos_explorador.html.twig`, `_documentos_checklist.html.twig` (l.28-100) e
    `_documentos_sugeridos.html.twig` (inteiro).
  - `app/public/js/pasta-explorador.js`: l.105-200, 360-500, 560-720, 1000-1100, 1250-1320, 1600-1720, 1970-2030,
    2380-2430, 2540-2610, 2650-2870 e 3720-3740.
  - `pasta-explorador-visor.js`: l.1-80 e 236-380. `visualizador-documento.js`: l.55-215, 1200-1350, 1800-1830 e
    2000-2060. `pasta-checklist.js`: l.30-62 e 755-775. `pasta-documentos-sugeridos.js` (grep).
  - `pasta-documentos-sugeridos.css`: l.50-75 e 510-580.
  - PHP: `UploadPecaUseCase.php` l.35-110, `PastaSecaoController.php` (todas as buscas), `PastaDocumentoController.php`
    (zip, limitador, vagas), `MontarZipDeDocumentosUseCase.php` (`nomeDoZip`), `ExploradorDeDocumentosOutput.php`,
    `DocumentoSugeridoOutput.php` l.30-50, `PastaController.php` (`checklist_adicionar`) e
    `EditarPecaTextoUseCase.php:88`.
  - `config/packages/framework.yaml:77`.
- **Git.** `git log 607f2c29..HEAD` filtrado pelos arquivos da área. Os 23 commits da área são todos de 07/10. Os
  commits posteriores (até `4a98cc6b`) não tocam nenhum arquivo de Documentos/visor. Tocam `pasta-show.js/.css`,
  `pasta-dialogos.js` e `pasta-timeline.js`, sem efeito no explorador (ele usa `confirm()` próprio, D-DOC-S3).

## Conferência dos feitos

| Item (ledger) | Commit | Veredito | Evidência |
|---|---|---|---|
| DOC-101 lixeira sem "30 dias" | `034f6ff1` | ✓ | `pasta-explorador.js:2542` ("vão para a lixeira e podem ser restaurados"), `:2699` ("Nada é apagado de vez automaticamente"); grep "30 dias"/"apagados de vez" = 0 |
| DOC-102 nota do Revisar | `5038bbdf` | ✓ | `_documentos_explorador.html.twig:259` |
| DOC-103 confirm/toast da limpeza | `5038bbdf` | ✓ | `pasta-explorador.js:1312-1313` |
| DOC-105 vazio dentro de pasta | `5038bbdf` | ✓ | `pasta-explorador.js:709` |
| DOC-109 zoom no ODP | `5038bbdf` | ✓ | `pasta-explorador-visor.js:75` |
| DOC-110 data Modificado no visor | `5038bbdf` | ✓ | `pasta-explorador.js:1606` (`dataModificacao`) |
| DOC-114 / DOC-141 aviso P7S com tamanho | `5038bbdf`, `047fd224` | ✓ | `visualizador-documento.js:1278-1284`; sem a promessa do desenho, como no precedente |
| DOC-121 Espaço em pasta não entra | `5038bbdf` | ✓ | `pasta-explorador.js:1701-1704` |
| DOC-123 `modificado_em` na peça | `5038bbdf` | ✓ | `EditarPecaTextoUseCase.php:88` |
| DOC-119 / SEC-001 tenant nas rotas de seção | `d9846179` | ✓ | `PastaSecaoController.php:112, 149, 190, 237`, destino `:257` (`findByIdAndPastaAndTenant`) |
| DOC-117 / SEC-004 limitador do zip + vagas | `2fd0a672`, `688674e1`, `95819293` | ✓ | `framework.yaml:77`; `PastaDocumentoController.php:441, 452`; `VagasDeZipDoEscritorio.php:44` |
| DOC-140 recusa do zip fora de XHR | `7a7ea58b`, `d4366e55` | ✓ | `PastaDocumentoController.php:394` (doc da página HTML mínima) |
| DOC-115 Anexar novos formatos | `6863247c` | ✓ | `_documentos_explorador.html.twig:82` (`accept` com `.p7s`); `UploadPecaUseCase.php:73-89, 102-107` |
| DOC-108 imprimir qualquer conteúdo, com tarja | `af6800a4` | **◐** | Conteúdo desenhado e imagem saem com a tarja (`pasta-explorador-visor.js:240-245, 343-370`). **O PDF imprime o arquivo cru, sem a tarja** (`:325-330`: `fr.src = a.viewUrl`). O desenho imprime o PDF como imagem por página, com a tarja (`bj-visualizar.js:96-102`; dc l.4659). Ver C6-DOC-04 |
| DOC-108b `poster`/`background` | `7f801ef2` | ✓ | `visualizador-documento.js:1210-1220` |
| DOC-106 possíveis duplicados | `77e83988`, `523464af` | ✓ | `_documentos_sugeridos.html.twig:170-185`, na ordem do desenho (antes de "Organização"; dc l.2157-2161) |
| DOC-106b teto de 20 pares + "e mais N" | `4c438e1b` | ✓ (teto a ratificar, C) | `_documentos_sugeridos.html.twig:182-184` |
| DOC-116 nome do zip e toasts | `19f3ada0` | ✓ | `MontarZipDeDocumentosUseCase.php:399-413`; `pasta-explorador.js:2390, 2420` (= dc l.3755-3758) |
| DOC-118 sem escrita para quem só vê | `b7a074d5` | ✓ (só explorador e checklist; o Sugerir ficou de fora, ver C6-DOC-01) | `pasta-explorador.js:195-200, 678, 1693, 2025, 3448, 3588, 3688`; `pasta-checklist.js:38-50, 760-764` |
| DOC-118b/c menu marcado na origem; lixeira só para quem o GET atende | `43d50135`, `5dcdb37c` | ✓ | `pasta-explorador.js:2025` (`o.escrita`); `ExploradorDeDocumentosOutput.php:57, 121` (`podeVerLixeira`) |
| DOC-120 link de subpasta + `?pex` | `40ec0828` | ✓ | `pasta-explorador.js:1976, 2162-2167, 3727-3737` |
| DOC-107 "Contexto do processo" | `0e09587e` | ✓ | `_documentos_sugeridos.html.twig:82` |
| DOC-111 / 111b `.doc` só texto, com a faixa; teto da FAT | `806eae4a`, `98239842`, `89a34c25` | ✓ | `visualizador-documento.js:126, 1816-1819, 892-896` |
| DOC-112 / 112b TIFF multipágina, UTIF local; teto de 150 Mpx | `3d14e368`, `98239842`, `45e7b3b7` | ✓ (teto a ratificar, C) | `visualizador-documento.js:61-64, 90, 97, 175`; `public/js/vendor/utif/` (UTIF.js, pako, VERSION, LICENSE) |
| DOC-124 rota legada removida | L6 `fd011225..b575428d` | ✓ | grep `pasta_documento_upload` em `src/ templates/ config/ public/js` = 0; `{% if false %}` em `show.html.twig` = 0 |

**Placar:** 27 confirmados, 1 parcial (DOC-108), 0 regredidos.

## Regressões

**Nenhuma.** Nenhum commit posterior a `45e7b3b7` (07/10) toca os arquivos da área. Os commits do loop que tocam
arquivos compartilhados vizinhos foram conferidos: `pasta-dialogos.js` (`2aa69c6f`), `pasta-show.js/.css`
(`54ac9e23`, `092338fd`, `207dd2fd`) e `pasta-mencoes.js` (`ab78b61f`). O explorador não consome esses trechos. A
exclusão segue no `confirm()` próprio (`pasta-explorador.js:2580`, D-DOC-S3), não no `confirmarPs`.

## Pendências A

**Nenhuma do inventário.** Os 21 A do inventário (DOC-101 a 103, 105 a 112, 114 a 121, 123 e 124) estão marcados como
feitos no ledger e foram conferidos acima. Só o DOC-108 está parcial, e o resto dele vira a C6-DOC-04, que não é A.

Seguem abertos e **não são A**:

- **C, ratificar:** DOC-104, 113, 125 a 130, 133, 134 e 139 (este fechado na prática pelo DOC-118 para o explorador),
  o teto do DOC-106b, o teto do DOC-112b e o sandbox do DOC-108.
- **B:** DOC-115b (MSG), 131, 132, 135 e 138.
- **E:** DOC-122, 136 e 137.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-DOC-01 | A | P3 | P | não | Sugerir documentos: "+ Checklist" e "Adicionar N faltante(s) ao checklist" para quem só vê a pasta e na lápide | dc l.2152 (`it.podeAdd`), l.2163 (`ckc.addTodos`); a regra "nada fake" de DOC-118 | `_documentos_sugeridos.html.twig:157, 209` sempre renderizados. `pasta-documentos-sugeridos.js` não confere permissão (grep "pode" = 0; cliques em l.159-170). O servidor recusa: `PastaController.php` (`checklist_adicionar`, `canAccessResource … 'edit'`) e o listener da lápide. O DOC-118 cobriu explorador e checklist, mas não este painel. **Resíduo do mesmo item:** ✎, + e Modelos do checklist vêm do servidor e só o JS os remove (`_documentos_checklist.html.twig:66-77`; `pasta-checklist.js:38-50`) | Condicionar os dois botões a `explorador.podeEditar` no Twig. O partial já é incluído dentro do explorador (`_documentos_explorador.html.twig:170` → checklist:125). Fazer o mesmo com ✎/+/Modelos em `_documentos_checklist.html.twig:66-77`. Não toca arquivo compartilhado | Functional com usuário só leitor: `.ds-sug-add-um` e `#btnDocumentosSugeridosFaltantes` ausentes; com editor, presentes. Mutação: tirar o `if` derruba |
| C6-DOC-02 | A | P2 | P | não | Visor: HEIC (aceito no Anexar desde o DOC-115) | `bj-visualizar.js:62` (grupo `heic`: tenta `<img>` e, se falhar, mostra o aviso "Imagem HEIC (iPhone). Este navegador não abre esse formato…") | `visualizador-documento.js:178` (`image/*` → `imagem`) e `:1318-1326` (`renderImagem` sem `onerror`). No Chrome e no Firefox fica a imagem quebrada. A impressão também falha (`pasta-explorador-visor.js:372-375`). O upload aceita `image/heic`/`image/heif` (`UploadPecaUseCase.php:76-77`) | Em `renderImagem`, `img.onerror` troca pelo bloco de aviso (ícone `bi-image`; texto "Imagem HEIC (iPhone). Este navegador não abre esse formato. Use Baixar.", sem a promessa de conversão no servidor, como no P7S) e marca `data-vd-sem-conteudo` para o Imprimir desabilitar. Vale para qualquer imagem que não carrega. Toca `visualizador-documento.js`, compartilhado com o `#previewDocModal` das outras abas, mas só acrescenta | Teste de leitura do JS (padrão `VisualizadorDocumentoArquiteturaTest`): `renderImagem` registra `error` e usa `data-vd-sem-conteudo`. O render real fica para o smoke do dono |
| C6-DOC-03 | A | P5 | P | não | Visor: avisos por tipo sem visualização (genérico, RAR/7Z, PPT/PPS e MSG) | `bj-visualizar.js:83, 85, 87`: "Este tipo de arquivo (EXT, tamanho) não tem visualização. Use Baixar." e avisos próprios com ícone e cor para compactado e formato antigo da Microsoft | `visualizador-documento.js:1259-1270`: um texto só, "Pré-visualização não disponível para este tipo de arquivo.", sem extensão nem tamanho | `blocoNaoDisponivel` recebe `nome`/`tamanho` e monta o texto do desenho com EXT e tamanho (o tamanho já chega no visor, como no DOC-141). Para RAR/7Z e PPT/PPS/MSG, ícone e texto do desenho **sem** a frase "no sistema, o servidor…" (D-LIBRE), no padrão do P7S. Mesmo arquivo compartilhado; só texto | Teste de leitura do JS: o texto contém a extensão e não contém "o servidor converte"/"lista o conteúdo" |
| C6-DOC-04 | D | P3 | M | não | Visor: imprimir PDF com a tarja | `bj-visualizar.js:47-52` (pdf.js, uma página por canvas) e `:96-102` (`capturar` vira imagem por página; tarja "BlueJus · Pasta N · nome · impresso em …", dc l.4659) | `pasta-explorador-visor.js:325-330`: o iframe carrega o PDF cru e imprime pelo leitor do navegador, sem tarja. `visualizador-documento.js:1309-1316`: o PDF é exibido pelo leitor nativo, não pelo pdf.js | Ter a tarja exige vendorizar o pdf.js e rasterizar cada página para imprimir (custo alto em autos de 400 a 1.000+ páginas; memória do navegador), ou carimbar no servidor. É escolha técnica de arquitetura → Farlei. Enquanto isso o DOC-108 fica ◐ | — |

Nenhuma das quatro toca `base.html.twig`, `pasta-show.css`, `show.html.twig`, `pasta-show.js` ou o CSS global. A 02 e a
03 tocam `visualizador-documento.js`, que o `#previewDocModal` das outras abas também usa.

## Matriz

Contagem sobre a do inventário (226/262). Somei os itens fechados por dimensão e acrescentei ao denominador os itens
novos (DOC-140 e C6-DOC-01 a 04; a C6-DOC-04 já estava dentro do DOC-108).

| Dimensão | OK | Total | Delta |
|---|---|---|---|
| Visual | 79 | 82 | +7 (DOC-102, 103, 105, 107, 110, 114/141, 116). Aberto: DOC-104 (C), C6-DOC-02, C6-DOC-03 |
| Interações | 92 | 97 | +3 (DOC-109, 120, 121). Aberto: DOC-122 (E), 129, 130 (C), C6-DOC-01 e um resto do DOC-118 de antes |
| Funções | 55 | 66 | +11 (DOC-101, 106, 111, 112, 115, 117, 118, 119, 123, 124, 140). Aberto: DOC-108 ◐, 113, 126, 127, 128, 131, 132, 133, 134, 135, 138 |
| Responsivo | 15 | 15 | = (nenhum elemento novo sem regra ≤768px; conferido por leitura de CSS) |
| Dark | 6 | 6 | = (`.ds-sug-dup` usa os tokens `--ds-roxo-texto*`, que têm par escuro em `pasta-documentos-sugeridos.css:60-75`) |
| **Total** | **247** | **266** | **92,9%** (era 86,3%) |

## Resumo

- **Feitos:** 27 confirmados, 1 parcial (DOC-108: o PDF imprime sem a tarja), 0 regredidos.
- **Pendências A do inventário:** 0.
- **Novas:** 4. São 3 A (C6-DOC-01 P3, C6-DOC-02 P2, C6-DOC-03 P5) e 1 D (C6-DOC-04). Nenhuma exige migration.
- **A acionáveis:**
  - **C6-DOC-01:** esconder "+ Checklist" e "Adicionar N faltante(s)" do Sugerir (e ✎/+/Modelos já no Twig) para
    quem só vê e na lápide.
  - **C6-DOC-02:** HEIC (e imagem que não carrega) mostra o aviso do desenho em vez da imagem quebrada; o Imprimir
    desabilita.
  - **C6-DOC-03:** avisos de "sem visualização" com extensão e tamanho, mais os avisos próprios de RAR/7Z e PPT/MSG,
    sem promessa de servidor.
