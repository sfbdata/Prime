# Investigação: componentes JS do Designer que não são IA × BlueJus real

Data: 05/10/2026 · Base: `master` @ `beff02fd` · Investigação somente leitura (nenhum arquivo do repo alterado)
Pacote: `docs/design/claude-design-2026-10-05 (1)/`

Classes: **A** já existe · **B** parcial · **C** dá para implementar com a infra atual · **D** exige infra interna nova, sem decisão externa · **E** depende de decisão do dono, credencial ou integração externa.
Esforço: P (≤ meio dia) · M (1–2 dias) · G (> 2 dias).

---

## ⚠️ 0. Aviso: tem outra sessão escrevendo agora na árvore principal

Durante esta investigação, a árvore do `master` passou a ter alterações **não commitadas** que implementam a
janela de 15 min (eu vi `PT24H` no primeiro grep e `PT15M` alguns minutos depois):

- novos: `app/src/Pasta/Service/JanelaDeEdicaoDeComentario.php` (`DURACAO = 'PT15M'`, `minutosRestantes()`), `app/src/Pasta/Twig/` (`JanelaDeEdicaoExtension`, função `comentario_janela_minutos`), `app/tests/Pasta/Unit/JanelaDeEdicaoDeComentarioTest.php`, `app/tests/Pasta/Functional/PastaJanelaDeEdicaoTelaTest.php`, `docs/specs/trilha-b-designer.md` (ledger da Trilha B, §2 ainda vazio);
- modificados: os 6 UseCases Editar/Excluir (Mensagem, ObservacaoDetalhes, ObservacaoFinanceira), `pasta/_dados_anotacoes`, `_detalhes_obs`, `_financeiro`, `_financeiro_obs_script`, `pasta/show.html.twig` e 9 testes.

Pela regra "um piloto de git por vez", **nenhum lote abaixo pode tocar nesses arquivos** até essa frente ser commitada.

---

## 1. Quais arquivos importam (e quais não)

| Arquivo | O que é | Serve para implementar? |
|---|---|---|
| `bj-editor.js` (125 KB, 1.000+ linhas) | Custom element `<bj-editor>` e `<bj-rich>`: editor "tipo Word" | Sim: é a referência do editor (§2) |
| `bj-link.js` | Link público do Push, montado só no navegador (`#d=` comprimido, hash FNV do código) | Só o comportamento; o mecanismo é de protótipo (§3) |
| `bj-processo.js` | Motor do "Buscar agora": dias úteis, segmentação do PDF do PJe, prazos do CPC, fase, previsão, duplicados | Só em parte; prazo jurídico é risco (§4) |
| `bj-visualizar.js` | `BJVisor.render` (PDF, imagem, TIFF, DOCX, ODT, RTF, planilha, PPTX, EML, ZIP, áudio, vídeo) e `BJImprimir` (impressão por iframe oculto) | Sim (§5) |
| `bluejus-docs.js` | "Motor Universal de Documentos" do **Chat I.A** (OCR com Tesseract, transcrição com Whisper, índice no IndexedDB, perguntas) | **Não**: é IA, fora desta investigação. Só o extrator DOCX/XLSX se repete em `bj-visualizar` |
| `bluejus-equipe.js` | "BlueJus Intelligence · Desempenho da equipe": regras fixas (sem LLM) que classificam cada pessoa como crítico, atenção, carga… | Decisão do dono (§7) |
| `doc-page.js` | Esqueleto genérico `<doc-page>` de impressão ("omelette starter"), usado só pelo `Relatorio de entregas` | **Não**: é ferramenta do Designer, não função do produto |
| `support.js` | Runtime dos `.dc.html` (React/parse) | **Não**: o próprio README avisa "não é parte do design" |
| `github.md` | Changelog do Designer | Uma correção acionável (Firefox), que já está aplicada (§8) |
| `Relatorio de entregas BlueJus.dc.html` | Relatório do Designer: "62 cérebros", 319 comandos, "≈ 270 solicitações" | **Não** é especificação. Os "próximos passos" (servidor, PJe real, IA no servidor, ligações) confirmam que tudo isso é **E** |
| `Guia visual BlueJus.dc.html` | Proposta de padronização (botões, etiquetas, regra de prazo, painéis, tipografia, Ctrl+K) | Sim, como padrão PJe (§8) |
| `design_handoff_pasta_show/README.md` | Handoff da **Pasta 1A** (26–28/08) | **Fora do escopo** (Pasta 1A). Desatualizado: pede Source Sans 3 (o dono voltou para Arial), raios de 14/9/8px (contra os 4px aprovados) e diz que `app/templates/` "não é versionado" (hoje é). Aproveito só o "continuar lendo / mostrar menos" e o "Duplicar pasta" |

Os pontos de atenção especial (responder/destacar, Suspenso/Cancelado, cadeado, "3 de 7", Duplicar/Mover,
Imprimir resumo, Favoritos/Acompanhar, 15 min) **não estão nesses JS**. Estão no `02 - EXPEDIENTES 1.2.3.dc.html`,
e para eles cito as linhas desse arquivo.

---

## 2. Editor (`bj-editor.js`) × editor rico atual

**O que já existe:** `app/public/js/editor-rico.js` (Quill auto-hospedado em `public/js/quill/`, aplicado sobre `<textarea data-editor-rico>`).
Barra (l. 75–84): título H2/H3, negrito, itálico, sublinhado, tachado, cor (6 nomes), listas, recuo, alinhamento, citação, limpar.
Ctrl+Enter envia (l. 204–206). O servidor limpa com `App\Shared\Service\SanitizadorTextoRico` usando o sanitizador `textoRico`
(`config/packages/html_sanitizer.yaml` l. 15–38): **sem `style`, sem `<a>`, sem `<img>`, sem tabela**, e só aceita classes `ql-(align|indent|color|bg|size|font)-*`.
Limite de 5000 caracteres.
É usado em anotações, Detalhes, Financeiro, mensagens da Tarefa e anotação da Cobrança.
**O Peticionar já tem TinyMCE completo** (`public/js/tinymce`, `layout_peticionar.html.twig`, `UploadImagemEditorUseCase`, `ExportarPecaTextoUseCase`): o "modo documento" já existe para peças.

**A diferença que decide tudo:** o `bj-editor` grava **HTML com `style` inline** ("lista fechada de estilos", l. 171–201) e imagem em data URL (l. 229–230).
Isso contradiz de propósito a política do sanitizador. **Trocar Quill por `bj-editor` é decisão E, de tamanho G**, e reabre o vetor de XSS que o sanitizador fecha.
Recomendo evoluir o Quill função por função:

| Função (`bj-editor.js`) | Linha | Situação no BlueJus | Classe | Esforço | Arquivos |
|---|---|---|---|---|---|
| Desfazer/Refazer | 302–303 | Quill tem o módulo `history`, mas sem botão | C | P | `editor-rico.js` |
| Estilo do parágrafo (Normal/Título) | 305 | H2/H3 já existem | A | — | — |
| Negrito, itálico, sublinhado, tachado, cor | 307–313 | Existem (6 cores por nome) | A | — | — |
| **Realce** (cor de fundo) | 314 | O atributo `background` por classe **já está registrado** (l. 97) e o sanitizador aceita `ql-bg-*`; falta o botão | C | P | `editor-rico.js`, `css/editor-rico.css` |
| Listas, recuo, alinhamento, citação, limpar | 316–321, 345 | Existem | A | — | — |
| Autoformatação "1." / "*" + espaço | 316–317 | Já vem nos atalhos padrão do Quill | A | — | — |
| **Link** (Ctrl+K) | 322 | Fora **por decisão do dono** (comentário do YAML, l. 12) | E | P | YAML + `editor-rico.js` |
| Símbolo § e data de hoje | 323, 332 | Não existe; é só inserir texto | C | P | `editor-rico.js` |
| Linha horizontal, quebra de página | 328, 333 | `<hr>` não é aceito pelo sanitizador | C | P | YAML + JS |
| Imagem (colar, arrastar, redimensionar, contorno) | 324–326, 781–1020 | Não existe nas anotações (só no Peticionar) | E | G | sanitizador, armazenamento, JS |
| Tabela | 1021+ | Não existe nas anotações | E | G | sanitizador + JS |
| Fonte/tamanho, espaçamento, modo documento A4 (régua, cabeçalho/rodapé, zoom) | 329–331, 343, 885–945 | Existe no **Peticionar (TinyMCE)**; nas anotações não faz sentido | A (peças) / E (anotações) | — | — |
| Revisar texto (repetidas, espaço duplo, erros conhecidos) | 503–536 | Hoje só o `spellcheck` do navegador | C | M | `editor-rico.js` |
| Localizar e substituir (Ctrl+H) | 537–583 | Não existe | C | M | `editor-rico.js` |
| Autocorreção pt-BR jurídica e sugestão de palavras | 141–170, 595–690 | Não existe | C (com proposta de lista ao dono) | M | `editor-rico.js` |
| **Ditado por voz** (Web Speech) | 584–594 | O áudio vai para servidores do Google ou da Microsoft: tem impacto de LGPD | E | P | — |
| Rascunho automático (`localStorage`, chave `bjed-rascunho:ctx:path`) | 404–420 | Não existe. Atenção: texto de cliente fica gravado no navegador, mesmo em computador compartilhado | C, com ressalva (o dono decide) | P | `editor-rico.js` |
| Contagem de palavras e status "Rascunho salvo" | 410–418 | Não existe; o limite de 5000 também não aparece | C | P | `editor-rico.js` |
| Menu do botão direito no estilo Word | 691–770 | Não existe | C | M | `editor-rico.js` |
| Colar do Word/e-mail com opções | 770, 947 | O Quill cola e o sanitizador corta | B | — | — |
| "Citar no novo registro" (evento `bj-editor-inserir`) | 397–402 | Não existe | C | P | `editor-rico.js` + template |
| Modo edição com Cancelar (atributos `conteudo`, `cancelavel`) | 363–366 | Existe (edição inline monta e desmonta o editor) | A | — | — |

---

## 3. Push Compartilhado (`bj-link.js`) e notas técnicas

**O que o protótipo faz:** todo o retrato público (processo, movimentações, análise de IA liberada, validade,
dados do advogado) fica **dentro da URL** (`#d=…`, deflate + base64). O "código de acesso" é um hash FNV-1a
conferido no navegador. O endereço legível é montado com o nome do cliente (`legivel`, l. 17).
O próprio arquivo admite que no sistema real "o mesmo retrato fica no servidor e o link curto resolve para ele; revogação, renovação e contagem de acessos".

**No BlueJus:** existe `PublicacaoDjen` (com `lida` e `processo`). Não existe link público.

**Classe E, esforço G, risco ALTO.**
- É uma rota **sem login** que expõe dado de cliente.
- Precisa de tabela de token com validade e revogação, código com hash de verdade (o FNV de 32 bits se quebra por força bruta), limite de tentativas e auditoria.
- O dono precisa decidir o que pode ir no link (nome do cliente no endereço, telefone e e-mail do advogado, análise de IA).
- Não copiar o mecanismo do `bj-link`: PII na URL vaza para histórico, logs e quem recebe o link.

**Nota técnica** (o dono decidiu que entra, **ligada ao PROCESSO**). No protótipo (EXPEDIENTES l. 5389–5406),
a nota fica no `localStorage` com chave `pasta → 'mov:'+id`. Ou seja, ela pendura na **pasta e na movimentação**, não no processo.
Também tem versões guardadas a cada edição, 15 min para o autor e "o usuário primário não tem limite" (l. 5405).
- No BlueJus: **nada existe** (grep por "nota técnica" no `app/` não encontra nada).
- **Classe C, esforço M.** Entidade nova `NotaTecnica` com `tenant`, `processo` (obrigatório), `publicacaoDjen` (opcional, para pendurar na movimentação do Push), `autor`, `conteudo` limpo pelo `SanitizadorTextoRico`, `criadaEm`, `editadaEm`. Mais migration, repository com tenant, UseCases criar/editar/excluir (reaproveitando `JanelaDeEdicaoDeComentario`), controller novo e partial nas abas Processo e Push da pasta.
- Como fica no processo, a mesma nota aparece em **toda pasta que vincular aquele processo**. É coerente com a decisão do dono, mas a tela deve deixar isso claro.
- Guarda de IDOR: conferir o processo e a pasta pelo `canAccessResource('processo'|'pasta')`.
- Versões a cada edição: D (tabela de versões), pode ficar para depois.

---

## 4. Dados do processo / PJe (`bj-processo.js`)

| Função | Linha | BlueJus | Classe |
|---|---|---|---|
| Capa: número, órgão, classe, assunto, valor, polos, advogados | 109–114 | `Processo` já tem `orgaoJulgador`, `classeProcessual`, `assuntoProcessual`, `dataDistribuicao`, `situacaoProcesso`, `instancia`, `nivelSigilo`, `partes`, `movimentacoes`, `datajudRaw`. O valor da causa fica na **Pasta** (`valorCausa`) | **B**: falta mostrar os campos ("Ver todas as informações / Mostrar menos", EXPEDIENTES l. 2892/2921). C, esforço P–M, só template da aba Processo |
| Calendário de dias úteis (fins de semana, recesso do art. 220 do CPC, feriados fixos no código, suspensões) | 14–26 | Já existe `FeriadoController` (feriados por escritório). Não há suspensão por tribunal | E: a lista no código está incompleta (feriado estadual e municipal, portarias), e um prazo errado é dano jurídico |
| Segmentar o PDF exportado do PJe (por "Num. … - Pág.", "Assinado eletronicamente por") | 108–136 | Não existe | E: precisa de PDF do PJe (sem integração) e de OCR no servidor |
| Determinações, prazos, termo inicial/final, cruzamento com manifestações, fase, previsão em cadeia, "conferência de previsões" | 138–356 | Não existe | **E**: é um conselho jurídico automático. Recomendo não entrar sem spec e sem decisão do dono |
| Documento possivelmente duplicado (tipo + data + 160 caracteres normalizados) | 249, 285 | Não existe | Entra no §5 (duplicados) |

---

## 5. Visualizador e duplicados (`bj-visualizar.js`)

**Já existe:** o modal `#previewDocModal` abre PDF (iframe), imagem, áudio e vídeo; o resto mostra "Pré-visualização não disponível" e o botão Baixar.
Esse JS está **copiado em 4 lugares**: `pasta/show.html.twig` (l. 2860–2910, modal na l. 2461), `cliente/show.html.twig` (l. 562), `tarefa/show.html.twig` (l. 1020) e `public/js/pasta-arquivos.js`.
As rotas `pasta_documento_view` e `pasta_financeiro_documento_view` entregam o arquivo inline, com `canAccessResource`. O servidor já tem `phpoffice/phpword` e `phpoffice/phpspreadsheet`.

| Função | Linha | Classe | Esforço | Observação |
|---|---|---|---|---|
| PDF, imagem, áudio, vídeo | 53–60, 83–84 | A | — | O pdf.js por canvas do protótipo não melhora o iframe nativo |
| **Word (DOCX)** | 63 | C | M | `mammoth` auto-hospedado (como o Quill) **ou** conversão no servidor com PhpWord (fidelidade baixa). O HTML gerado precisa ir para iframe `sandbox`, nunca `innerHTML` direto: o `limpar()` do protótipo (l. 23) é lista negra fraca |
| **Excel (XLSX/XLS/ODS/CSV) com abas** | 66–72 | C | M | SheetJS auto-hospedado **ou** writer HTML do PhpSpreadsheet no servidor (já instalado; é o mais seguro). Pôr limite de linhas |
| Texto, JSON, XML, CSV | 79 | C | P | Precisa de `fetch` + `<pre>` com escape |
| ODT, RTF, PPTX (só texto), EML, ZIP (listar conteúdo) | 64–65, 74–82 | C | P cada | Menos usados; fazer depois |
| DOC, PPT, MSG, HEIC, TIFF de várias páginas | 61–62, 87 | E/D | G | O protótipo diz "o servidor converte": precisaria de LibreOffice no container, que não existe hoje (registro na memória: "sem LibreOffice/pandoc") |
| Assinatura P7S | 86 | E | — | Validador do ITI, externo |
| `BJImprimir.imprimir` (iframe oculto, `@page A4`) | 92–100 | C | P | É a base do "Imprimir resumo" (§6) |

**Antes de mexer:** juntar as 4 cópias num `public/js/visualizador-documento.js` (refatoração P–M). Sem isso, DOCX/XLSX teria de ser feito quatro vezes.

**Duplicados de arquivos:**
- No protótipo há três regras: nome parecido em % ("A ≈ B (N% parecido)", EXPEDIENTES l. 4043, vindo de `bj-docsug`), conteúdo igual (`bj-processo` l. 249) e o selo "CPF duplicado" (l. 1382).
- `PastaDocumento` **não tem hash** (campos: `titulo`, `categoria`, `caminhoArquivo`, `nomeOriginal`, `mimeType`, `tamanhoBytes`, `carregadoEm`, …).
- Nome parecido ou mesmo tamanho dentro da pasta: **C, P** (consulta e aviso no `fm`).
- Duplicado de verdade: **D, M**. Coluna `sha256` calculada no upload, mais um comando de preenchimento que lê o armazenamento (≈ 23 mil arquivos / 26 GB medidos no P0 do R2), mais o aviso no upload ("este arquivo já existe em …") e na lista.
- Isso conversa com a frente do R2: combinar a ordem com ela.

---

## 6. Pontos de atenção especial (fonte: `02 - EXPEDIENTES 1.2.3.dc.html`)

| Ponto | Desenho | BlueJus hoje | Classe | Esforço / arquivos / risco |
|---|---|---|---|---|
| **Edição de comentário em 15 min** | l. 5888–5894: "Master sem restrição; primário sem limite de tempo; autor só nos 15 minutos"; aviso "Disponível por mais N minutos" | **Em andamento na árvore** (§0): `JanelaDeEdicaoDeComentario::DURACAO='PT15M'` nos 6 UseCases da Pasta e nos templates via `JanelaDeEdicaoExtension`. Antes eram 6 `const JANELA = 'PT24H'` (`Editar*/Excluir*{MensagemPasta,ObservacaoDetalhes,ObservacaoFinanceira}UseCase`) mais `date('-24 hours')` nos templates (`_dados_anotacoes` l. 84, `_financeiro` l. 183, `show.html.twig` l. 3138) | A (o autor) / **E (primário e Master)** | O que falta: "primário sem limite" e "Master". Não existe esse conceito (§7). **Fora da Pasta:** `EditarTarefaMensagemUseCase` (só o autor, **sem janela**), `KanbanComentario` (`ComentarioOutput::podeEditar` = só o autor, sem janela) e a anotação da Cobrança (`EventoHistorico::JANELA_EDICAO = 'PT48H'`). O dono precisa dizer se os 15 min valem para essas três |
| **Mostrar mais/menos** | l. 6479 "continuar lendo (N parágrafos)" / "mostrar menos" (3 parágrafos); l. 2892 "Ver todas as informações / Mostrar menos" (processo) | Só existe na **lista** (`visiveis = 4` + "anteriores", `_dados_anotacoes`). Não existe por texto | C | P. CSS (`pasta-show.css`) e um JS pequeno que conta os `<p>`. Toca nos mesmos templates do §0: **esperar** |
| **Responder** | l. 1311, 5954: resposta recuada ("Resposta a X", @menção), botão direito "Responder" | `PastaMensagem` não tem pai | C | M: `parent_id` em `pasta_mensagem` (migration), UseCase, timeline (`PastaTimelineAssembler`) e templates. O @menção que notifica é outra frente (Notificacao existe) |
| **Destacar comentário** | l. 254, 4344 (`DEST_CORES` com 13 cores), 5958 | Não existe | C + **E** (o destaque é da equipe ou só de quem marcou? No protótipo é `localStorage`, ou seja, só de quem marcou) | P–M: coluna `destaque_cor` (equipe) ou tabela por usuário |
| **Suspenso/Cancelado com carimbo** | l. 5860–5876: Ativo / Suspenso ("Pausada temporariamente") / Cancelado ("Encerrada sem conclusão") / Arquivado ("Sai das listas de trabalho") | `Pasta::situacao` VARCHAR(20) com `Assert\Choice([ativo, arquivado])` (l. 30–53). **27 usos** de `SITUACAO_*` ou `'arquivado'` em `PastaRepository`, `PastaController`, `EditarPastaUseCase`, `PastaType`, `PastaTimelineAssembler`, `_filtros`, `_cabecalho`, `demandas`, `show` | C técnico, **E de regra** | M. Não precisa migration de coluna (VARCHAR, sem CHECK). Precisa de decisão: suspensa/cancelada fica nas listas de trabalho, nos alertas de prazo e no Dashboard? Fica somente leitura como a arquivada? Risco: filtro de lista errado esconde pasta viva |
| **Cadeado / acesso restrito por pessoa** | l. 432–471 + 5802–5804: modos "pessoas/todos", níveis por pessoa (Ocultar/Visualizar/Editar), "exclusivo do usuário primário"; "a equipe enxerga só o nome do cliente e o CPF"; sugestão `pasta_acesso {pasta, modo, niveis}` aplicada em **todas** as rotas | `ResourceAccess` só **soma** permissão (passo 4 do `canAccessResource`, `docs/AUTORIZACAO.md` §5b). Não existe "negar" por item. As listagens não filtram por `ResourceAccess` | **E + D** | G, risco MÉDIO→ALTO (permissão). Inverte o modelo (negar por padrão para o item) e precisa cobrir listagem, busca, Dashboard, Push, documentos, download e sync. Exige spec em `docs/specs/` e a definição de "primário" |
| **"3 de 7" nas setas** | l. 5792 `pastaPos: (i+1)+' de '+L.length` | As setas existem: `PastaRepository::vizinhasNoAcervo` (l. 771), chave composta (prefixo do NUP, NUP, id), `PastaVizinhasOutput`, `PastaController` l. 394. Já está registrado como C na Trilha A §5 | C | P. Dois `COUNT` com a mesma expressão de comparação (`CASE … CAST_INT_PREFIXO`) e o mesmo filtro de tenant (posição = quantas vêm antes + 1; total = pastas do escritório, incluindo arquivadas e lápides, que é o conjunto das setas). Arquivos: `PastaRepository`, `PastaVizinhasOutput`, `PastaController::show`, `_cabecalho.html.twig` e testes. Risco baixo; precisa de teste cross-tenant |
| **Duplicar pasta** | l. 1097, 4324: "a cópia leva cliente, ação, responsável e checklist; documentos, metas e financeiro não são copiados", "aguardando número" | Existem `CriarPastaUseCase`, `GerarNumeroDePasta`, `AplicarChecklistModeloUseCase` | C | M. `DuplicarPastaUseCase` novo + controller novo + item no ⋮ do `_cabecalho`. Pontos a confirmar: a cópia nasce **com número novo** (o "aguardando número" do protótipo não existe aqui) e **cria pasta no Drive** (sync está em 403) |
| **Mover para outra carteira** | l. 1100, 4333: carteiras "Contencioso cível, Condominial, Família…" | **A Pasta não tem carteira nem área** (a carteira é da Cobrança) | **E** | Precisa de conceito novo (área de atuação). Não implementar |
| **Imprimir resumo** | l. 1105, 2930 (`gmImprimir`, tabela do resumo) | Dompdf já é usado (folha de ponto, política de privacidade). Não há resumo da pasta | C | P–M. Rota nova `pasta_resumo_imprimir` (controller novo, `canAccessResource view`) + template `pasta/resumo_impressao.html.twig` com `@media print` ou Dompdf. Item no ⋮ |
| **Favoritos** | l. 4270, 4440 (por usuário e pasta; "sobem para o topo") | Não existe | C | M. Tabela `pasta_preferencia_usuario(user, pasta, favorito, acompanhar)` com tenant, toggle e ordenação no Expediente (`PastaRepository::aplicarOrdenacao`) |
| **Acompanhar alterações** | l. 1113 (switch, padrão LIGADO, l. 4271) | `Notificacao` existe, mas não há assinatura por pasta | D | M–G. Mesma tabela + listener que notifica quem acompanha (mensagem, documento, Push). Ligado por padrão = barulho: o dono decide o padrão |
| **Padrão PJe (Guia visual)** | Botões de 32px em 5 níveis (Primário `#0f6fc4`, Secundário, Discreto, Destrutivo, Sucesso), "no máximo 1 Primário por área", etiquetas iguais em todas as telas, **uma** regra de prazo (vencido / ≤ 3 dias vermelho / ≤ 7 âmbar / acima cinza), Arial em 5 tamanhos, ícones de 14/16px, animações de 180/320ms, busca global Ctrl+K | A Trilha A aplicou 4px e as faixas de prazo na Pasta e no Dashboard (spec §5) | B | M por tela. **Divergência dentro do próprio Guia:** o texto diz "raio 8px", mas o código do Guia usa `border-radius:4px`. Vale 4px (decisão de 05/10). A busca global Ctrl+K é C, esforço G (consulta de Cliente, Pasta e Processo com tenant) |

---

## 7. Permissões, Master/primário, abas, zerar, equipe

- **"Usuário primário":** não existe no código. O candidato natural é `Tenant::criadoPor` (`src/Entity/Tenant/Tenant.php` l. 89). O admin do escritório hoje é o `TenantRole.isSystem()` ("Administrador do Escritório"), que **pode ter várias pessoas**. **E:** o dono escolhe entre "quem criou o escritório", "um campo novo `responsavelPrincipal`" ou "todo admin".
- **"Master único":** o equivalente mais próximo é o `ROLE_SUPER_ADMIN` (da plataforma, atravessa escritórios). Dar a ele "editar comentário de qualquer escritório sem restrição" é sensível. **E.**
- **Ocultar abas por pessoa** (EXPEDIENTES l. 265, 4411): **D + E**, MÉDIO. Precisa de tabela, guarda **no servidor** em cada aba ou fragmento (esconder só no Twig não protege as rotas XHR) e decisão de quem pode ocultar.
- **Zerar relatório** (Dashboard l. 705–709, 780: "Zerar relatório de todos" / por pessoa): **E.** Zerar contagem de metas apaga ou esconde histórico de desempenho. Precisa de semântica (marco de início por pessoa? auditável?) e de decisão.
- **`bluejus-equipe.js` (classifica pessoas como "crítico", "necessita acompanhamento" etc.):** as regras são fixas e o cálculo é viável. Ele pede o "período anterior" (`hist`), ou seja, rodar `ObterDadosDashboardUseCase` duas vezes. Mesmo assim é **E**: rotular colaborador é decisão de RH e do dono. A spec da Trilha A deixou o Intelligence de fora.

---

## 8. Outros

- **Correção do calendário duplicado no Firefox** (`github.md` + `design_handoff_dashboard/correcao-filtro-data.css`): **A**, já aplicada em `app/public/css/filtro-tabela.css` l. 103–122 (`@supports (-moz-appearance: none)`).
- **Relatórios em PDF:** Dompdf (`dompdf/dompdf ^3.1.5`), PhpWord e PhpSpreadsheet já estão no `composer.json`. O PDF do Dashboard (o protótipo usa `window.print`) é **C, esforço P** com `@media print` em `dashboard.css`. O "Relatório geral de processos" (`spPDF`, que no protótipo usa html2pdf do CDN) é **C, esforço M** com Dompdf no servidor.
- **Chat/Central e Connect** (`bluejus-central.js`) não estavam na minha lista. As ligações dependem de "servidor de chamadas" (o próprio Relatório de entregas diz isso): **E**.

---

## 9. IMPLEMENTAR PRIMEIRO (lotes sem arquivo em comum)

> Regra: nenhum lote toca nos arquivos da frente de 15 min **até ela ser commitada** (§0).
> Os lotes 1 a 4 podem rodar em paralelo, cada um em sua worktree.

**Lote 1: "3 de 7" nas setas** (C, P, risco baixo)
`app/src/Pasta/Repository/PastaRepository.php` (método `posicaoNoAcervo`, reaproveitando a expressão da `vizinha`) · `app/src/Pasta/DTO/PastaVizinhasOutput.php` · `app/src/Controller/PastaController.php` (só o `show`, l. ~394) · `app/templates/pasta/_cabecalho.html.twig` · testes (unit do repo + functional com caso cross-tenant e NUP repetido).

**Lote 2: Nota técnica no processo** (C, M, risco baixo/médio)
Arquivos novos: `app/src/Processo/Entity/NotaTecnica.php`, `Repository/NotaTecnicaRepository.php`, `UseCase/{Criar,Editar,Excluir}NotaTecnicaUseCase.php`, `Controller/NotaTecnicaController.php`, migration, partial novo `app/templates/processo/_notas_tecnicas.html.twig`, testes.
Usa `JanelaDeEdicaoDeComentario` **só como dependência** (sem editar o arquivo). A inclusão do partial nas abas Processo e Push da pasta fica para depois do §0, porque toca no `show.html.twig`, ou entra num partial próprio das abas.

**Lote 3: Visualizador Word/Excel/texto** (C, M, risco baixo)
Arquivo novo `app/public/js/visualizador-documento.js` + bibliotecas auto-hospedadas (`public/js/vendor/mammoth`, `public/js/vendor/xlsx`) **ou** um endpoint novo `PastaDocumentoPreviewController` com o HTML do PhpSpreadsheet. Ligar **primeiro** em `cliente/show.html.twig` e `tarefa/show.html.twig`. A `pasta/show.html.twig` (l. 2860–2910) só entra depois do §0. Renderizar em iframe `sandbox`.

**Lote 4: Editor, sem decisão pendente** (C, P–M, risco baixo)
Só `app/public/js/editor-rico.js` e `app/public/css/editor-rico.css`: realce (`background`), desfazer/refazer, § e data, contagem de caracteres/palavras com o limite de 5000, localizar e substituir.
Link, imagem, `<hr>`, ditado e rascunho no navegador **ficam para depois da decisão do dono**.

**Lote 5 (depois do §0 e do Lote 1, porque divide o `_cabecalho` e o `show`):** Imprimir resumo (controller e template novos, mais o item do ⋮) e "mostrar mais/menos" por texto (`pasta-show.css` + os templates de comentário).

**Lote 6 (depois do Lote 5):** Duplicar pasta (`DuplicarPastaUseCase` e controller novos, item no ⋮). Antes, o dono confirma o número novo e a criação no Drive.

**Lote 7 (independente):** Favoritos (tabela de preferência + ordenação no Expediente). O Acompanhar vem depois, com o listener de notificação (D).

**Lote 8 (independente, D):** hash SHA-256 de `PastaDocumento` + comando de preenchimento + aviso de duplicado. Combinar com a frente do R2.

**Para o dono decidir (E, não implementar ainda):**
1. Quem é o "primário" e o "Master" (destrava o "primário sem limite", o cadeado, ocultar abas e zerar).
2. Se os 15 min valem também para Tarefa, Kanban e Cobrança (48h).
3. Regra de lista para Suspenso/Cancelado.
4. Se o destaque de comentário é da equipe ou de cada pessoa.
5. Cadeado: modelo de negar por item (precisa de spec MÉDIO/ALTO).
6. Link público do Push: o que expor, validade, código, LGPD.
7. Editor: link, imagem, ditado, rascunho no navegador. Trocar o Quill pelo `bj-editor` (não recomendado).
8. "Mover para outra carteira" (o conceito não existe na Pasta).
9. Zerar relatório e classificação de equipe (`bluejus-equipe`).
10. Motor de prazos do `bj-processo` (conselho jurídico automático).
11. DOC/PPT/MSG pelo servidor (exige LibreOffice no container).
