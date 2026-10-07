# Inventário — PASTA · aba DOCUMENTOS (explorador `pex`) + VISUALIZADOR

Auditoria read-only de 07/10/2026 sobre o master `607f2c29`. Nenhum arquivo do repositório foi alterado.
IDs: DOC-01…DOC-90 são os da auditoria de 06/10 (`trilha-b-inventario/auditoria-documentos.md`). Os itens novos desta
rodada usam **DOC-101…DOC-139**.

## Fontes lidas (arquivo:faixa de linhas)

**Desenho.** Pacote `docs/design/claude-design-2026-10-05 (1)/`:

- `02 - EXPEDIENTES 1.2.3.dc.html`:
  - template da aba: l.2020–2297, cobrindo faixa, Organizar, Visualizar, checklist, desativar, sugeridos, adicionar, itens, filtro, barra de seleção, trilha, cabeçalho, limpeza, lista, laço, menu, toast, painel e rodapé;
  - visor: l.295–329;
  - lógica: l.3048–3160 (modos, `FI`/`fi()`, realce, colunas, `TIPOS_DOC`, `expOrgVals`), l.3714–3765 (zip, `expBaixar`, `expAbrir`), l.4048–4070 (estado do checklist, motivos), l.4441–4442 (favoritos), l.4634–4700 (`visorVals`, `expTipo`, `EXP_META`, `expLimpeza`), l.4700–4955 (`expVals` completo: seleção, menus, barra, teclado, laço, toast, renomear, limpeza, painel) e l.6328–6350 / 6530–6535 (checklist, selo e barra).
- `bj-visualizar.js` (106 linhas, todo).
- `bj-docsug.js` (135 linhas, todo).
- `doc-page.js`: l.1–30. É o *scaffold* genérico de documento paginado e não é requisito da aba.
- `design_handoff_bluejus_intelligence/README.md`: l.100–135, seções "Inteligência documental", "Atualização completa por arquivos" e "Visualização rápida e impressão".
- Capturas: `screenshots/ck-sug.jpg`, `imp-tarja.jpg` (é o resumo da pasta, não o visor), `02-visor-imp.jpg` e `02-explorer.jpg` (estas duas mostram só o cabeçalho da Pasta, sem a aba).

**Mapa.**

- `docs/specs/trilha-b-inventario/auditoria-documentos.md`, inteiro (os 90 itens).
- `docs/specs/trilha-b-documentos-arquitetura.md`, inteiro (§1–§7 e S-1…S-12).
- `docs/specs/estado-geral-claude-designer-bluejus.md`: §1, §5, §6, §9 e §10.

**Código.**

- Templates e JS:
  - `app/templates/pasta/_documentos_explorador.html.twig` (363 linhas, inteiro);
  - `_documentos_checklist.html.twig` (235, inteiro);
  - `_documentos_sugeridos.html.twig` (205, inteiro);
  - `show.html.twig` l.300–322, 385–398, 532–541 e 4230–4242;
  - `app/public/js/pasta-explorador.js`: l.1–700, 1027–1745, 1860–2600, 2600–2960, 2945–2970 e 3376–3460, mais um grep do resto;
  - `pasta-explorador-visor.js` (392, inteiro);
  - `visualizador-documento.js` l.52–160.
- CSS:
  - `app/public/css/pasta-explorador.css`: tokens l.13–497 (claro × escuro comparados por script), l.498–1420 e 1610–1712;
  - `pasta-checklist.css` (grep).
- PHP:
  - `PastaDocumentoController.php` (rotas e guardas);
  - `PastaSecaoController.php` l.100–125 e 225–260;
  - `PastaController.php` l.296–330, 375–470 e 1575–1600;
  - `UploadPecaUseCase.php` l.40–110;
  - `MontarZipDeDocumentosUseCase.php` l.345–370;
  - `SugestoesDeLimpeza.php` (constantes);
  - `PastaPendenciasOutput.php` l.35–97;
  - `MotivoDesativacaoChecklist.php`.
- `debug:router` filtrado por pasta/documento/seção/checklist/lixeira/favorito: as 34 rotas existem.

## Matriz (contagem real)

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual (faixa, botões, popovers, linhas nos 8 modos, ícones, selos, estados, checklist, sugeridos, limpeza, menu, toast, barra, painel, rodapé, visor) | 72 | 80 |
| Interações (clique/Ctrl/Shift/duplo, laço, 14 atalhos, 3 menus de contexto com ~35 itens, barra, arraste de itens e de colunas, alça de largura, inline, visor e checklist) | 89 | 96 |
| Funções (persistência/backend: lote, lixeira, zip, cópia, favoritos, checklist, sugeridos, regras, metadados, formatos do visor) | 44 | 65 |
| Responsivo (<768px, toque, 375px) | 15 | 15 |
| Dark mode (tokens `pex`, checklist, sugeridos, visor, lixeira) | 6 | 6 |

Itens DOC-01…DOC-90 conferidos no código:

| Situação | Quantidade | Itens |
|---|---|---|
| F | 82 | — |
| F com resto em divergência | 4 | DOC-50, DOC-63, DOC-73, DOC-76 |
| Não feitos | 4 | DOC-39 (C), DOC-51 (C), DOC-52 (C/A), DOC-75 (C) |

Isso confirma o "82 de 87" do §5.3 do mestre.

**Divergências desta rodada: 39** (DOC-101…DOC-139).

| Classe | Quantidade |
|---|---|
| A | 21 |
| B | 4 |
| C | 11 |
| D | 0 |
| E | 3 |

## Divergências

| ID | Elemento | Fonte (arquivo:linha) | Dim | Divergência (desenho × código) | Classe | Prior. | Evidência no código | Esf. | Proposta (se A) |
|---|---|---|---|---|---|---|---|---|---|
| DOC-119 | Rotas de seção e mover arquivo: `pasta_secao_renomear`, `_excluir`, `_mover` e `pasta_documento_mover_secao` | §9 #1 do mestre | F | `em->find()` por id cru, sem tenant (inclusive a seção de destino). Só o TenantFilter e o UseCase protegem; não há defesa em profundidade no controller | A | P0 | `PastaSecaoController.php:105, 140, 181, 228, 246` | P | Trocar por `findByIdAndTenant` (repos de `PastaSecao`/`PastaDocumento`) e devolver 404. Sem migration. **Teste:** functional cross-tenant das 4 rotas (seção ou documento de outro escritório → 404, sem efeito), incluindo `secao_id` de destino de outro tenant |
| DOC-101 | Lixeira: nota do modal e texto do `confirm()` | D-DOC-RET (§10.1: sem purga automática) | F | O código diz "Itens ficam 30 dias na lixeira. Depois disso são apagados de vez." e, no confirm, "Os itens ficam 30 dias na lixeira e podem ser restaurados". A constante comenta "o cron roda". Não há cron nem purga automática: é promessa falsa em tela | A | P1 | `pasta-explorador.js:222, 2452, 2606` | P | Remover `DIAS_NA_LIXEIRA`. Nota: "Os itens ficam na lixeira até serem restaurados ou apagados por um administrador." Confirm: "… Vai para a lixeira e pode ser restaurado." Sem migration. **Teste:** teste de leitura do JS (padrão `VisualizadorDocumentoArquiteturaTest`) exigindo a ausência de "30 dias" e de "apagados de vez". Não citar prazo enquanto D-DOC-RET (prazo) estiver aberta |
| DOC-117 | Zip: disponibilidade | §9 #5 | F | Não há limitador. Com `pm.max_children=5`, 5 zips simultâneos ocupam todos os workers do PHP-FPM | A | P1 | `PastaDocumentoController.php:366+`; `rate_limiter` já configurado em `config/packages/framework.yaml` | P | Limiter `zip_documentos` por usuário (ex.: 1 simultâneo + 6/min) em `zip()`, devolvendo 429. Sem migration. **Teste:** functional com N+1 pedidos → 429 e JSON com mensagem |
| DOC-108 | Visor: Imprimir | `bj-visualizar.js:96-102` (`capturar`/`imprimir`), dc l.4659-4663; README l.130-131 | F/V | **Desenho:** imprime o conteúdo renderizado de qualquer tipo (DOCX, planilha, texto, ODT, RTF, PPTX, EML, lista do ZIP; o PDF vira imagem por página) com a tarja "BlueJus · Pasta N · nome · impresso em …". **Código:** só PDF (o arquivo cru, sem tarja) e imagem (com tarja); nos demais tipos o botão fica `aria-disabled` com "Baixe o arquivo para imprimir" | A | P2 | `pasta-explorador-visor.js:21-24, 73-77, 216-296` | M | `VisualizadorDocumento` expõe o HTML sanitizado que já injeta no `srcdoc` (DOCX, ODT, RTF, planilha, PPTX, EML, texto). O visor imprime esse HTML num iframe oculto `sandbox="allow-modals"` (sem scripts), com a mesma CSP e a tarja. Sem migration. **Teste:** teste de arquitetura (sandbox sem `allow-scripts`; CSP presente). Impressão real só no smoke do dono |
| DOC-115 | Anexar: tipos aceitos | dc `TIPOS_DOC`/`expTipo` (l.3082-3087, 4675-4680); `bj-visualizar.js` | F | O desenho trata ODT, ODS, ODP, RTF, PPTX, EML, MSG, CSV, GIF, WEBP, HEIC e TIFF como documentos da pasta. **Recusa:** o `accept` e o `MIME_LIMITS` não aceitam ODT, ODS, ODP, RTF, PPTX, EML, CSV, GIF, WEBP, TIFF nem HEIC, embora o visor abra vários deles. **Incoerência:** `.p7s` é aceito no servidor (`pkcs7-signature`), mas falta no `accept` | A | P2 | `_documentos_explorador.html.twig:84`; `UploadPecaUseCase.php:47-67` | P/M | Ampliar `MIME_LIMITS` com os MIMEs OpenDocument, RTF, PPTX, `message/rfc822`, `text/csv`, `image/gif\|webp\|tiff\|heic` (validação por `finfo` já é a do `getMimeType()`) e alinhar o `accept`. Sem migration. **Teste:** functional de upload, um por tipo novo, e recusa de executável renomeado |
| DOC-118 | Controles de escrita para quem só VÊ a pasta | §1.4.3 do mestre ("nada fake") | F/V | Nova pasta, Anexar, Renomear, Recortar/Colar, Mover, Excluir, Editar…, Restaurar e +/✎/interruptor do checklist aparecem para usuário só com permissão de ver. O servidor recusa com 403/toast. Não há flag de edição no `#pexDados` | A | P3 | `PastaController.php:313` (só VIEW); `PastaDocumentoController.php:113, 179, 213`; `ExploradorDeDocumentosOutput` sem `podeEditar` | M | `podeEditar` (`canAccessResource … edit`) no `#pexDados` e no template. O JS não renderiza ações de escrita (menus, barra, F2/Del/Ctrl+X/V, arraste, Sortable, upload por soltar). Ver D-DOC-RO (DOC-139). Sem migration. **Teste:** functional com usuário só leitor (`#pexNovaPasta`/`#pexAnexar` ausentes; `podeEditar=false` no JSON) |
| DOC-106 | Sugerir documentos: "Possíveis arquivos duplicados" | dc l.2158-2160; `bj-docsug.js:107-109` | F/V | No desenho, o painel lista os pares de nome ≥85% parecidos ("a ≈ b (N% parecido; confira se é o mesmo documento)"). No código esse dado só aparece como "Nome parecido" no painel de detalhes de cada arquivo, por nível | A | P3 | `_documentos_sugeridos.html.twig` (sem a seção); `pasta-explorador.js:1183-1188` | M | Seção `bi-files` "Possíveis arquivos duplicados" no painel, com os pares de `SimilaridadeDeNomes` (já existe) sobre todos os arquivos da pasta, como o `bj-docsug` faz. Sem migration. **Teste:** unit do serviço (par ≥0,85; nomes iguais fora) + functional de arranjo (`#documentosSugeridos .ds-sug-dup`) |
| DOC-111 | Visor: `.doc` (Word antigo) | `bj-visualizar.js:68` (grupo `doc`: extrai o texto no navegador com aviso) | F | O desenho mostra o texto com a faixa "Formato antigo do Word (.doc): mostrando só o texto". O código cai em "sem visualização" (`tipoDe` → `outro`). O mestre §5.4 põe DOC em D-LIBRE, mas o desenho tem um caminho sem servidor | A | P3 | `visualizador-documento.js:98, 137-158` | P | Tipo `doc` com extração de texto em JS puro (dentro do teto de bytes do zip-bomb), exibida no sandbox com o aviso do desenho. Sem migration. **Teste:** teste de arquitetura e fixture `.doc`; o render é do smoke |
| DOC-112 | Visor: TIFF multipágina | `bj-visualizar.js:66` (UTIF, todas as páginas) | F | TIFF chega como `image/*` → `<img>`, que o Chrome e o Firefox não abrem. No desenho, cada página vira um canvas | A | P3 | `visualizador-documento.js:142` | M | Vendorizar UTIF em `public/vendor/` (como o mammoth e o xlsx; nada de CDN) e criar o tipo `tiff`. Sem migration. **Teste:** arquitetura (lib local) + smoke |
| DOC-116 | Zip: nome do arquivo e textos | dc l.3756-3758 | V | **Nome:** o desenho usa "Pasta {n} - {subpasta} - dd-mm-aaaa.zip"; o código, `documentos-<NUP>.zip`. **Toast:** o desenho diz "Preparando N itens para download…" e depois "Baixando <nome> · N arquivos, M pastas"; o código, "Preparando o .zip: N arquivos · X" | A | P4 | `MontarZipDeDocumentosUseCase.php:360-369`; `pasta-explorador.js:~2332` | P | `nomeDoZip`: "Pasta <NUP> - <subpasta se a seleção é de um nível> - dd-mm-aaaa.zip", com `filename*` UTF-8 e fallback ASCII. Toast com o texto do desenho. Sem migration. **Teste:** unit do nome (com e sem subpasta, NUP vazio) + functional do `Content-Disposition` |
| DOC-102 | Faixa de limpeza: nota do Revisar | dc l.2232 | V | O desenho diz "Nada é excluído sem você confirmar. A exclusão pode ser desfeita."; o código, só a 1ª frase. A 2ª já é verdade (lixeira + Desfazer) | A | P4 | `_documentos_explorador.html.twig` (`.pex-limpeza-nota`) | P | Completar o texto. **Teste:** arranjo |
| DOC-103 | Limpeza: confirmação e toast | dc l.4952 (`limpExcluir`) | V | O desenho confirma com "Excluir N arquivo(s) sugerido(s)? Você pode desfazer logo em seguida." e avisa "N arquivo(s) excluído(s) · X liberados". O código usa o confirm genérico ("Excluir N itens? … 30 dias…") e o toast "N itens excluídos" | A | P4 | `pasta-explorador.js:1271-1276, 2463-2509` | P | `excluirItens(itens, aoExcluir, {confirmacao, toast})` só para a limpeza. **Teste:** leitura do JS |
| DOC-105 | Vazio dentro de pasta | dc l.4872 | V | O desenho diz "Pasta vazia. Arraste arquivos para cá ou use Colar (Ctrl+V)."; o código, "…ou clique em Anexar." (o Colar existe) | A | P4 | `pasta-explorador.js:~681` (`renderizar`) | P | Texto do desenho. O vazio da raiz, que o desenho omite, segue a convenção. **Teste:** leitura do JS |
| DOC-107 | Sugerir documentos: rótulo do bloco | dc l.2131 ("Contexto do processo") | V | O código diz "Contexto da pasta" | A | P4 | `_documentos_sugeridos.html.twig:~80` | P | Usar o texto do desenho; se a troca foi intencional (a fase vem da pasta), registrar e ratificar com o Samuel. **Teste:** arranjo |
| DOC-109 | Visor: zoom no ODP | dc `temZoom` l.4652 (`pptx` cobre ODP no `BJVisor`) | I | O ODP abre sem o grupo de zoom | A | P4 | `pasta-explorador-visor.js:72` | P | Pôr `odp` em `TIPOS_COM_ZOOM`. **Teste:** leitura do JS |
| DOC-110 | Visor: data na meta | dc l.4651 (`f.data` = a mesma data da lista) | V | O visor mostra a data do upload (`carregadoEm`); a lista e o painel mostram Modificado (`modificadoEm ?? carregadoEm`) | A | P4 | `pasta-explorador.js:1573` | P | Usar `formatarData(dataModificacao(a))`. **Teste:** leitura do JS |
| DOC-123 | `modificado_em` na edição da peça de texto | §9 #8 | F | A peça editada no Peticionar não atualiza "Modificado". Ressalva: no **mover**, NÃO atualizar está certo (Windows e desenho: mover não muda a data); o §9 #8 deve ser corrigido nesse ponto | A | P4 | `EditarPecaTextoUseCase.php` (sem `marcarModificadoEm`); `PastaDocumento.php:331` | P | Chamar `marcarModificadoEm(now)` na edição do texto. Sem migration. **Teste:** unit do UseCase |
| DOC-114 | Visor: P7S/P7M | `bj-visualizar.js:84` (aviso "Envelope de assinatura digital… validador oficial do ITI") | V | O código mostra o genérico "sem visualização" | A | P5 | `visualizador-documento.js:158` | P | Bloco próprio com o texto do desenho (sem promessa de "no sistema aparece…"). **Teste:** leitura do JS |
| DOC-120 | Menu de uma PASTA: link | dc l.4813 ("Compartilhar link" também para pasta) | I | S-12 trouxe "Copiar link" interno só para arquivo; a subpasta não tem link | A | P5 | `pasta-explorador.js:1937-1942` | M | Link `pasta_show#documentos` + `?pex=<secaoId>` lido no carregamento para abrir o nível (respeita a permissão da pasta). Sem migration. **Teste:** functional do parâmetro ignorado para seção de outra pasta |
| DOC-121 | Espaço sobre pasta | dc l.4911 (Espaço só visualiza **arquivo**) | I | O código entra na pasta | A | P5 | `pasta-explorador.js:~1657` | P | Espaço numa pasta não faz nada (ou seleciona). **Teste:** leitura do JS |
| DOC-124 | Upload legado `pasta_documento_upload` | §9 #7 | F | Os formulários estão num bloco morto `{% if false %}`, junto com o JS legado (no-op). A rota segue viva e não grava `enviado_por` | A | P5 | `show.html.twig:319-~600, 395, 539, 4123-4240`; `PastaController.php:1626+` | M | Remover o bloco `if false`, o JS legado e a rota, se `grep` e testes confirmarem que não há consumidor (o Cliente tem rota própria). **Teste:** `debug:router` + suíte |
| DOC-104 | Faixa de limpeza: rótulo e ícone | dc l.2219-2220 (`bi-stars`; o texto começa em "Ganhe espaço:") | V | O código troca `bi-stars` por `bi-hdd` e acrescenta o título "Sugestões de limpeza — por regras". É uma extensão do D-DOC-S4, que só cobre "Sugerir documentos" | C | P4 | `_documentos_explorador.html.twig` (`.pex-limpeza-faixa`) | P | Ratificar com o Samuel como parte do D-DOC-S4 |
| DOC-125 | Escopo da busca | dc l.4719-4721 (filtra o NÍVEL aberto) | F | A busca do código é global, em todas as subpastas, com o caminho de cada resultado (função do sistema §16.3). Nunca houve decisão do Samuel; "busca" consta como fiel no §5.3 | C | P4 | `pasta-explorador.js:558-570` | — | Ratificar: manter global (recomendado, não perde função) ou seguir o desenho |
| DOC-113 | Visor: DOC com formatação, PPT, MSG, HEIC e RAR/7Z | `bj-visualizar.js:5, 67, 81-85` | F | Conversão no servidor | C (D-LIBRE) | P3 | — | — | — |
| DOC-126 | Desativar checklist: notificar a controladoria e lembrete de 30 dias (DOC-73, parte) | dc l.4067-4068 | F | Só o estado e o motivo existem; o texto foi neutralizado | C (D-DOC-S6) | P3 | `_documentos_checklist.html.twig:20-25` | — | — |
| DOC-127 | Cobrança automática e selo "cobrado Nx" (DOC-75) | dc l.3996-4199, 6337 | F | Não existe | C (D-DOC-S6) | P3 | — | — | — |
| DOC-128 | Pendência da aba "documento cobrado e ainda não anexado" (DOC-76, parte) | dc l.5242 | F | Só a parte "marcado sem anexo" existe | C (D-DOC-S6) | P3 | `PastaPendenciasOutput.php:93-97` | — | — |
| DOC-129 | Soltar arquivo no visor (DOC-51) | dc l.320, 4665 | I | Não é renderizado; o arraste é engolido | C (D-DOC-S7) | P4 | `pasta-explorador-visor.js:331-333` | — | — |
| DOC-130 | "Encaminhar via Chat I.A" (DOC-39) | dc l.4817 | I | Fora do menu | C (D-CONNECT/D-IA) | P4 | `pasta-explorador.js:1886` | — | — |
| DOC-133 | Retenção da lixeira (prazo e purga manual) | §10.3 #19 | F | A lixeira só cresce; a purga é só por comando | C (D-DOC-RET) | P5 | — | — | — |
| DOC-134 | Purga × arquivo no Drive | §10.3 #18 | F | — | C (D-DOC-DRIVE) | P5 | — | — | — |
| DOC-139 | Pasta excluída (lápide): botões de escrita visíveis no explorador | §10.3 #21 | F/V | O checklist já os esconde; o explorador mostra Nova pasta, Anexar e as ações, e o servidor recusa | C (D-DOC-RO tela) | P4 | `_documentos_explorador.html.twig:45` (`data-pasta-excluida` só usado pela lixeira) | — | Junto com DOC-118 se o Samuel aprovar |
| DOC-131 | Camada de IA do "Sugerir documentos" (`refinarIA`, `analiseProcesso`) | `bj-docsug.js` (cabeçalho); README l.100-106 | F | Só regras | B (D-IA) | P4 | — | — | — |
| DOC-138 | Leitura do PDF do processo e da "capa do PDF" (confiança Alta) | `bj-docsug.js:processoDe`, `conf` | F | O código lê só o teor do Push (confiança média/baixa) | B (D-PJE/OCR) | P4 | `_documentos_sugeridos.html.twig:~75-80` | — | — |
| DOC-132 | Páginas (DOC-65) em produção | §5.3 | F | O código está pronto, mas falta o backfill `app:documentos:calcular-hash --paginas` em prod (operação do dono). Sem ele, a regra "cópia do processo >100 páginas" só age pelos 50 MB | B (operação) | P4 | `UploadPecaUseCase.php:40-42` | — | — |
| DOC-135 | Renomear documento → Drive | §9 #33 | F | Só a pasta propaga ao Drive | B (Drive em 403) | P5 | — | — | — |
| DOC-122 | Ctrl+Espaço / Ctrl+setas (foco sem selecionar) | dc `tecla` l.4904-4931 não trata | I | Responde a §9 #13: o desenho **não pede** | E | P5 | — | — | Não fazer nesta rodada |
| DOC-136 | "Atualização completa por arquivos e processos grandes" | README l.108-114 | F | É do Push ("Atualizar com arquivos do processo") e depende de pdftotext/OCR. Não é da aba Documentos | E | — | — | — | — |
| DOC-137 | `doc-page.js` | l.1-30 | — | *Scaffold* genérico de documento paginado para impressão. Não descreve tela da aba | E | — | — | — | — |

## Itens F (agrupados, com evidência)

Os 82 itens do DOC-01…90 estão conferidos no código. Os 4 parciais aparecem nas divergências acima.

**Estrutura.** DOC-01, 02, 03, 04, 05 e 06.

- Cartão e faixa: `.pex-cab` em `pasta-explorador.css:498`, com tokens l.15-22.
- Checklist como faixa em qualquer nível: `_documentos_explorador.html.twig:174` (S-8).
- Rodapé com os textos do desenho: `pasta-explorador.js:759-772`.

**Toolbar.** DOC-07, 08, 09, 10 e 11.

- Placeholder "Buscar arquivos e pastas…", ⊗ e Esc: `_documentos_explorador.html.twig:66-70`; `pasta-explorador.js:2953-2965`.
- Nova pasta neutra e ANEXAR com `bi-paperclip`: `pasta-explorador.css:571-585`.
- **Organizar:**
  - Ordem das colunas, a nota, Classificar (com "Manual" 1º, S-5), Colunas Categoria (S-2), Tipo de documento com contagem/desabilitado e Restaurar padrão: template l.87-141;
  - "Lixeira (N)" é extra; o desenho é omisso.
- **Visualizar:** 8 `menuitemradio` mais o Painel: template l.144-170.

**Navegação.** DOC-12, 13 e 14.

- Trilha com ↑ "(Backspace)" e chevrons de 10px: template l.215-219; JS l.738-757.
- Backspace/Alt+←/↑: `pasta-explorador.js:1626-1630`.
- Clique seleciona e duplo clique abre: l.1452-1537.

**Itens.** DOC-15 a 24.

- Pastas amarelas com sombra: token `--pex-pasta`.
- Linha do Detalhes `minmax(140px,1fr) 150 90 110`, gap 12: `pasta-explorador.css:953`.
- Tipo pela extensão: `TIPOS` (`pasta-explorador.js:390-428`).
- Ícone Office ≥52px: `FI` (l.432-456).
- Estados hover, seleção, alvo e Idêntico: CSS l.1033-1037 e 1220.
- ⋮ só em `(hover:none)` (S-1): CSS l.1082-1090.
- Nome em `<a>`: l.973-985.
- Nº/descrição só no Conteúdo e no painel (D-DOC-SUB).
- Favoritos persistidos: `pasta_documentos_favorito` (`PastaDocumentoController.php:305`) e toasts do desenho (l.2363-2366).
- Contagem "N arquivos"/"Vazia": l.859-863.

**Modos, colunas e painel.** DOC-25 a 29.

- Tamanhos de ícone por modo: CSS l.1400-1418.
- Colunas móveis e redimensionáveis com limites do `COL_LIM`: JS l.211-213, 2989-3109.
- Primeiro clique de data/tamanho decrescente: l.234.
- Cabeçalho 11px/700: CSS l.956+.
- Painel com as propriedades do desenho e o extra do sistema: l.1039-1125.

**Seleção, teclado e laço.** DOC-30 a 33.

- Teclado: l.1599-1730.
- Laço com rolagem automática a 40px: l.1731-1835.
- Barra com os 6 botões na ordem do desenho e o rótulo do Baixar por contagem: l.1380-1425.

**Menus e toast.** DOC-34 a 38 e DOC-40.

- Ordem do desenho mais "Mover para…" e "Editar…" (D-DOC-EDITAR, D-DOC-BARRA): l.1896-1963.
- "Copiar link" interno (S-12) e "Copiar caminho" "BlueJus › Pasta N › …": l.2119-2137.
- Toast de 4,2 s com Desfazer: l.2077-2111; CSS l.1354-1393.

**Upload e download.** DOC-41 a 46.

- Barra de progresso, inserção sem recarregar e duplicado no escritório: l.3376-3456.
- Zip no servidor: 500 arquivos / 1 GB, `LEIA-ME.txt`, auditado.

**Visor.** DOC-47, 48 e 49.

- Tela cheia `#525659`, cabeçalho `#0a7aad`, "i de N", zoom de 50 a 200% de 10 em 10, Esc/←/→: `pasta-explorador-visor.js`.

**Criar, renomear, mover e excluir.** DOC-53 a 58.

- Inline com Enter/Esc/blur e extensão preservada: l.2819-2940.
- Arraste nativo pasta→pasta.
- Cópia com "(cópia n)".
- Lixeira por lápide com Desfazer.
- Confirmação mantida (D-DOC-S3).

**Busca e filtro.** DOC-59, 60 e 61.

- Dobra acentos, trata `_ - .` como espaço, realce `<mark>`, "N resultados para …" e vazio do desenho: l.474-495, 666-679.
- Chip "Mostrando somente": template l.187-189.

**Duplicados e limpeza.** DOC-62, 64 e 66.

- Por nível (D-DOC-IDENT); o 0 byte do Drive fica fora (D-DOC-VAZIO).
- `SugestoesDeLimpeza.php:40-46`: as quatro regras e os limiares do desenho.
- A cláusula de categoria "processo" do desenho não tem lastro: não há categoria com esse nome.

**Checklist e sugeridos.** DOC-67 a 72, 74 e 77 a 82.

- Selo e barra nas cores do desenho: `pasta-checklist.css:37-44, 90-94`.
- Os quatro motivos idênticos: `MotivoDesativacaoChecklist.php:24-27`.
- "sem anexo" por regra.
- Sugeridos dentro do cartão com Atualizar, conflito CNJ, chips, grupos com 🔴/🟠/🟡/⚪/🟢/⚫, "Origem" que abre o Push, Organização por fase e "Adicionar N faltante(s)".
- Rótulo neutro (D-DOC-S4).
- Exigido pelo juízo por regex sobre o teor do Push, com prazo só explícito (D-DOC-PRAZO).

**Transversal.** DOC-83 a 90.

- Vazio de busca do desenho.
- `alert()` só como fallback.
- Responsivo ≤768px e toque: CSS l.1084, 1195, 1456, 1624-1657 e 1875.
- Escuro: 202 de 203 tokens `--pex-*` com par; a exceção, `--pex-fonte-num`, é neutra. Checklist, sugeridos, visor e lixeira também têm par.
- Arial nos números.
- Raios 4/3/999.
- Editor de peças preservado.
- Desempenho: render só do nível aberto e um modal único.

**Divergências intencionais aprovadas** (contadas como F):

- D-DOC-IDENT, D-DOC-S3, D-DOC-S4, Copiar desabilitado só com pastas, D-DOC-RET (cron), D-DOC-VAZIO;
- S-1, S-2, S-5, S-8, S-11, S-12;
- D-DOC-SUB, D-DOC-ORD, D-DOC-PRAZO, D-DOC-EDITAR, D-DOC-BARRA;
- textos do desativar/desativado sem "BlueJus IA" nem cobrança (S-4/S-6);
- caixas de seleção pedidas pelo Samuel (`230a2c43`).

## Observações (riscos, conflitos Designer × sistema, dúvidas para o Farlei)

1. **O §9 #7 do mestre está impreciso.**
   - O formulário legado de `pasta_documento_upload` AINDA está no `show.html.twig` (l.395, 539) e no JS (l.4240), mas dentro de `{% if false %}` e de um bloco no-op. "Nenhum template usa" vale na prática, mas o código morto continua lá (DOC-124).
2. **O §9 #8 está meio certo.** Não atualizar "Modificado" ao mover é o comportamento correto (Windows e desenho); só a edição da peça de texto falta (DOC-123).
3. **O §5.4 do mestre põe DOC e TIFF multipágina em D-LIBRE, mas o `bj-visualizar.js` os abre no navegador.**
   - DOC: só o texto, com aviso.
   - TIFF: via UTIF.
   - Fazer isso é autônomo (DOC-111/112). Só a formatação fiel do DOC, PPT, MSG e HEIC exige servidor.
4. **Limites do Anexar × regras de limpeza.**
   - O Anexar recusa PDF acima de 10 MB e ZIP/vídeo acima de 50 MB. As regras "cópia do processo >50 MB" e "muito grande >100 MB" só pegam arquivos vindos do Drive ou de importação.
   - Isso não é defeito, mas explica por que a faixa quase nunca aparece para upload manual.
5. **Permissão de leitura (DOC-118).** O desenho não tem conceito de permissão. Esconder escrita para quem só lê segue a regra "nada fake". Vale confirmar com o Farlei se é o padrão desejado para a Pasta inteira, não só para Documentos.
6. **Testes.**
   - Toda a interação (laço, teclado, menus, visor, impressão) segue sem harness de comportamento de JS; só há testes de leitura do JS e de arranjo.
   - As propostas A acima dão o teste possível. O visual e a impressão são do smoke do dono.
7. **A captura `imp-tarja.jpg` é do "Imprimir resumo" da pasta (EI1), não do visor.** O visor não tem captura de impressão no pacote; a referência é o código de `BJImprimir`/`capturar`.
