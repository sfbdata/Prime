# Reauditoria C7: Documentos e Visualizador (prefixo DOC)

Auditoria somente leitura de 13/10/2026 sobre o master `76671b8a`. Só este arquivo foi escrito. O visual foi conferido
lendo template, JS e o desenho (`bj-visualizar.js` l.20-110; `02 - EXPEDIENTES 1.2.3.dc.html` l.4659). Não foi
conferido na tela. Base: `c6/reauditoria-documentos.md`.

**Commits do C6 na área.** `git log 3ff6d9b0..HEAD` filtrado pelos templates `_documentos_*`, `pasta-explorador*.js`,
`visualizador-documento.js`, `pasta-checklist.js` e `pasta-documentos-sugeridos.js` mostra só os três commits do
S6-DOC: `29fee857`, `775a7951` e `51c6b660`. Os outros commits do ciclo não tocam esses arquivos.

## Conferência (lote S6-DOC)

| Item | Commit | Veredito | Evidência |
|---|---|---|---|
| C6-DOC-01: "+ Checklist" e "Adicionar N faltante(s)" do Sugerir só para quem edita | `29fee857`, `51c6b660` | ✓ | `_documentos_sugeridos.html.twig:36` (`sugPodeEscrever = explorador.podeEditar ?? false`), `:162` e `:214`. O `data-csrf` do painel só sai para quem escreve (`:43`). `explorador.podeEditar` vem de `ExploradorDeDocumentosOutput.php:105, 294`. Teste: `PastaDocumentosSugeridosSomenteLeituraTest` (leitor, lápide, editor) |
| C6-DOC-01, resíduo: ✎, + e Modelos do checklist já no Twig | `51c6b660` | ✓ | `_documentos_checklist.html.twig:68-81`. O JS continua removendo os mesmos botões como segunda barreira (`pasta-checklist.js:38-50`). Teste: `:86-98` do mesmo arquivo |
| C6-DOC-02: HEIC e imagem que não carrega viram aviso; Imprimir recusado | `775a7951`, `51c6b660` | ✓ | `visualizador-documento.js:1376-1384`: o `error` do `<img>`, com a guarda `vivo()`. O HEIC leva o texto do desenho (`bj-visualizar.js:62`) sem a promessa de conversão. As outras imagens caem em `blocoNaoDisponivel`, que já marca `data-vd-sem-conteudo` (`:1268`). Imprimir: `pasta-explorador-visor.js:289-294`. `o.mime` é normalizado antes (`:2082`), então o `toLowerCase()` não estoura |
| C6-DOC-03: aviso de "sem visualização" com extensão e tamanho; avisos de RAR/7Z/TAR/GZ e PPT/PPS/MSG | `775a7951` | ✓ | `visualizador-documento.js:1325-1344` e `:2110`. Textos, ícones e cores batem com `bj-visualizar.js:83, 85, 87` (`#b7791f`, `#c0571d`, `#6b8494`), sem "o servidor lista/converte" (D-LIBRE). Os grupos são os do desenho (`TIPOS.compactado`, `ppt`, `msg`). PPT/MSG/RAR não são capturados antes por outro tipo: `MIMES_PPTX` e `MIMES_ZIP` não incluem `ms-powerpoint`, `x-rar` nem `gzip` |

**Placar:** 4 de 4 ✓, 0 ◐, 0 ✗.

**DOC-108** segue ◐ pelo mesmo motivo do C6: o PDF é impresso sem a tarja. Isso é a C6-DOC-04 (D), que está na fila de
decisões do Samuel. Não é pendência A.

## Regressões

**Nenhuma.**

- `visualizador-documento.js` é compartilhado com o `#previewDocModal` das outras abas. A mudança só acrescenta: um
  `error` no `<img>` e um bloco novo no ramo `!render`. Os tipos que já tinham render não passam por ele.
- O `{% if explorador.podeEditar ?? false %}` do checklist está aninhado corretamente dentro de `{% if checklistAtivo %}`
  (`_documentos_checklist.html.twig:57-82`). O checklist só é incluído pelo explorador
  (`_documentos_explorador.html.twig:170`), e o Sugerir só pelo checklist (`:129`). Por isso `explorador` está sempre
  no contexto e o `?? false` não esconde nada do editor por engano.
- Sem os botões, o `pasta-documentos-sugeridos.js` lê `data-csrf` nulo (`:31`). Esse valor só seria usado nos
  cliques `.ds-sug-add-*` (`:159-170`), que não existem para quem só lê.

## Pendências A

**Nenhuma.** Os 21 A do inventário e os 3 A novos do C6 estão feitos e conferidos. Continuam abertos, e nenhum deles é
A: C (DOC-104, 113, 125-130, 133, 134, 139, o teto do DOC-106b, o teto do DOC-112b e o sandbox do DOC-108), B
(DOC-115b, 131, 132, 135, 138), D (C6-DOC-04) e E (DOC-122, 136, 137).

## Novas

Não há divergência nova de classe A.

Observações abaixo de A, registradas para não serem reabertas como novidade:

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C7-DOC-01 | C | P5 | P | não | Imprimir do visor depois do HEIC quebrado | `dc l.4659` (o desenho só confere se há conteúdo; não desabilita o botão) | `pasta-explorador-visor.js:290-291`: põe `aria-disabled` e `title`, mas não o `aria-label` "Imprimir (indisponível): …" que o fluxo normal põe (`:178`). Além disso, o botão só fica indisponível depois do primeiro clique, e não quando o erro acontece | Repetir a linha do `aria-label`. Opcional: avisar o visor no `error` | Leitura de JS |
| C7-DOC-02 | C | P5 | P | não | Tamanho nos avisos de "sem visualização" fora do explorador | `bj-visualizar.js:83-87` (o visor do desenho tem o tamanho do blob) | `ligarModal` (`visualizador-documento.js:2155-2162`) não repassa `tamanho`. No `#previewDocModal` das outras abas, o aviso sai "(RAR)" sem o tamanho, o mesmo comportamento do P7S desde o DOC-141 | Repassar `data-tamanho` dos botões que abrem o modal, se o dono quiser | — |
| C7-DOC-03 | C | P5 | P | não | Interruptor "Ativo", painéis Modelos/Adicionar e tokens do checklist para quem só vê | omisso | `_documentos_checklist.html.twig:40, 43, 85, 137-140, 170`: saem do servidor para o leitor que não está na lápide. Só o JS os remove (`pasta-checklist.js:38-50, 761-765`). O servidor recusa a escrita (`PastaChecklistEstadoController`: 403), então não há furo | Mesmo `if` do C6-DOC-01, se o dono quiser HTML limpo. Não muda o que aparece na tela | Functional (opcional) |

## Matriz

Contagem sobre a do C6 (247/266). Foram fechados C6-DOC-02 e C6-DOC-03 (Visual), C6-DOC-01 e o resto do DOC-118
(Interações). Os três itens novos acima são C e não entram no denominador.

| Dimensão | OK | Total | Delta |
|---|---|---|---|
| Visual | 81 | 82 | +2 (C6-DOC-02, C6-DOC-03). Aberto: DOC-104 (C) |
| Interações | 94 | 97 | +2 (C6-DOC-01 e o resto do DOC-118). Aberto: DOC-122 (E), 129 e 130 (C) |
| Funções | 55 | 66 | = (DOC-108 ◐ pela C6-DOC-04; o resto é B/C/E) |
| Responsivo | 15 | 15 | = |
| Dark | 6 | 6 | = (as cores dos avisos são as do desenho, sobre o fundo do visor, como o P7S) |
| **Total** | **251** | **266** | **94,4%** (era 92,9%) |

## Resumo

- Conferidos: 4 de 4 ✓ (C6-DOC-01, o resíduo do checklist, C6-DOC-02 e C6-DOC-03).
- Regressões: 0. Pendências A: 0. Novas A: 0. Novas C: 3 (C7-DOC-01 a 03).
- A acionáveis: nenhum.
