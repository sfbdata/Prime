# Etapa 3 da Assinatura de documentos: levantamento (09/11/2026)

Leitura apenas. Nada foi editado no repositório.

- Desenho: `docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (475 linhas, inteiro). Números de linha abaixo são deste arquivo (abreviado "dc:NNN").
- Apoio: `bj-assina-pdf.js` (só etapa 5), `bj-central-docs.js` (BJDocs.registrar, Central), `support.js`.
- Código: worktree `.claude/worktrees/assinatura-desenho` (branch `assinatura-desenho`), caminhos relativos a `app/`.
- Specs: `docs/specs/assinatura-documentos.md` (1.6, 1.9, 3.5, 3.6, Etapa 3, 5.2, 5.4, 6, 8), `assinatura-documentos-etapa-2.md` (D, 4.2), `assinatura-documentos-etapa-7.md`.
- Inventário bruto: `python3 -I .claude/skills/desenho-fiel/scripts/inventario_dc.py` sobre o desenho.

Travessões do desenho que precisam ser reescritos (regra do projeto): `'(travessão)'` como e-mail ausente no manifesto (dc:418) e o `'(travessão)'` de dado ausente em geral. Proposta: "não informado". Nenhum outro texto da etapa 3 tem travessão.

---

## a) Inventário por região

### A1. Folha A4 final (função `pdf(d, modo)`, dc:392-427)

Base: W=1000, H=1414 em unidades do desenho; escala S=2.48 (página de 2480 x 3507 px, 300 dpi); fonte Arial; azul NV `#0b3550`. A4 final 595.28 x 841.89 pt, uma imagem JPEG (qualidade .9) por página.

| Região | Item | Valor do desenho (linha) | Estado(s) |
|---|---|---|---|
| Página | hash usado no fundo | `hs = d.hash \|\| sha(id + nome)` (dc:392) | enviado: hash do envio; rascunho: hash provisório |
| Página | código impresso | `cod = d.cod \|\| 'RASCUNHO'` (dc:392) | rascunho: "RASCUNHO" |
| Fundo | gradiente íris, linhas onduladas, rosácea, marca d'água, molduras, borda ondulada, microtexto, faixa lateral | ver seção b (dc:409-410) | `d.limpa` (folha branca): fundo branco nas páginas do conteúdo |
| Cabeçalho | logo | (92,60), altura 64, largura até 280 (dc:410) | sem logo: marca "Blue" NV + "Jus" `#0a6fa0`, bold 34, y 102 |
| Cabeçalho | nome do escritório | bold 12.5, alinhado à direita em (882,84) | sempre (exceto duas colunas) |
| Cabeçalho | endereço e telefone | 11.5, `'end · tel'`, y 101 | idem |
| Cabeçalho | régua | `fillRect(92,136,790,1.6)` NV | idem; desenhado também na folha branca |
| Conteúdo (página inteira) | margens | X=92, L=790, TOP=170, BOT=1220, FS=19, LH=28.5 (dc:396) | |
| Conteúdo | título (t) | bold 26, entrelinha 36, centralizado, espaço depois +2 | |
| Conteúdo | subtítulo/cabeçalho/rótulo (s/h/l) | bold 20; depois de s +22, de h +4 | |
| Conteúdo | parágrafo (p) | justificado, última linha à esquerda; depois +12 | |
| Conteúdo | data (d) | alinhada à direita | |
| Conteúdo | linha de base | `o.y + 18` | |
| Conteúdo | bloco de assinatura (a) | altura 112; rubrica h54 w até 240; linha em yy+60, meia largura 200, espessura .9; nome bold 15 em yy+78; "Assinado em {data}" bold 12.5 `#1e7b45` em yy+95 | pendente: sem rubrica nem "Assinado em" |
| Conteúdo | página de PDF enviado | página nova, imagem cabe em L x (BOT-TOP) a partir de TOP-16, contorno `rgba(11,53,80,.3)` | documento por upload |
| Duas colunas | margens | XL=104, XR=930, GAP=30, CW=398, BT2=H-164 (dc:399) | layout `duas` |
| Duas colunas | texto lateral | girado -90 graus em (92, H/2-20), 40px reduzindo até caber em 1180 (mínimo 18), `#44546a` | se houver lateral |
| Duas colunas | logo | cabe em 180 x 80, em (XL, 92 - h/2) | |
| Duas colunas | número | 21px, à direita em (XR,72) | |
| Duas colunas | título | bold 17.5 reduzindo até 12, centralizado, y 112 | |
| Duas colunas | introdução | começa em yy=142 | |
| Duas colunas | corpo | FS de 17.5 a 9.5, passo .25, até caber em uma página; LN=FS*1.17, G=FS*.62 | não coube a 9.5: página vazia (`ops.push(r2 \|\| [])`, dc:403) |
| Duas colunas | assinatura mini | altura FS*4.7; rubrica 34; linha +38; nome 13.5 em +52; "Assinado em" bold 11 em +66; meia largura CW*.42 | |
| Rodapé (todas as páginas) | QR | caixa q0 = 116 (x .8 no mini), em (x0+4, y0+4); y0 = H-172 (mini H-142) (dc:411) | |
| Rodapé | rótulo | bold 11 `#4a6373` "CÓDIGO DE VALIDAÇÃO · PÁGINA n DE total" em y0+14 | |
| Rodapé | código | caracteres bold (20 + i*.85) Courier `#a3232b`, espaçamento +1.5, y0+42 | |
| Rodapé | hash | 10.5 Courier `#34505f`: "SHA-256 " + 32 primeiros em y0+60; 8 espaços + resto em y0+73 | |
| Rodapé | instrução | 11 `#243845` "Confira em bluejus.com.br/assinar/validar ou leia o QR Code." y0+94 | |
| Rodapé | emissão | 10 "Emitido em {data e hora pt-BR}. Vale somente com registro correspondente no BlueJus." y0+110 | |
| Rodapé | selo | em (R0-62, y0+60), raio 58 (dc:411-412) | concluído: visto verde; senão "PENDENTE" |
| Arquivo | nome do download | `'assinado_' + nome normalizado + '.pdf'`; modo assinar: `'para_assinar_'` (dc:425) | |
| Arquivo | metadados | `/Title (Documento BlueJus {cod})`, `/Producer (BlueJus)` (dc:424) | |
| Aviso | toast | "PDF com selo gerado." (dc:426) | só no JS |

Textos da prévia HTML que diferem do PDF: a prévia diz "Assinado eletronicamente em" (já no código, `translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml:191`); o PDF diz "Assinado em" (dc:398, 405). Ver lacuna L2.

### A2. Manifesto (dc:417-420)

Gerado sempre, exceto quando `limpa && !cod` (folha branca em rascunho). Página própria, com fundo e cabeçalho completos.

| Região | Item | Valor do desenho | Estado(s) |
|---|---|---|---|
| Título | "MANIFESTO DE ASSINATURAS" | bold 24 NV, yy=170 | sempre |
| Título | nome do documento | 15px `#243845`, +30 | |
| Cartão por signatário | caixa | X, yy, L, 118; fundo `rgba(255,255,255,.72)`, borda `rgba(11,53,80,.3)` .7; avanço 128 | |
| Cartão | barra de 4px à esquerda | assinado `#1e7b45`, recusado `#a3232b`, outro `#7a5200` | 3 estados |
| Cartão | nome | bold 16, +25 | |
| Cartão | linha de dados | 12.5 `#243845`, +45: "{papel} · CPF ***.XXX.XXX-** · {e-mail mascarado}"; CPF ausente "não informado"; e-mail = 2 primeiros caracteres + "***" + "@domínio"; e-mail ausente `'(travessão)'` (reescrever: "não informado") | |
| Cartão | tipo | +63 "Tipo: {lei} · {método}"; lei por nível: Simples "Eletrônica simples", Reforçada "Eletrônica com múltiplos fatores", gov.br "Avançada (gov.br)", ICP "Qualificada (ICP-Brasil)" (dc:292) | método: "E-mail e código" ou "E-mail e código, selfie, localização {lat, lng}" |
| Cartão | situação | bold 12.5 na cor da barra, +83: "Assinado em {data}" / "Recusou em {data}: {motivo}" / "Assinatura pendente" | 3 estados |
| Cartão | nota | 11.5 `#5b7383`, +101 "IP e dispositivo registrados na trilha do servidor." | |
| Cartão | rubrica | à direita, h66, w até 200, em (X+L-w-14, yy+26) | só assinado |
| Trilha | título | +6, "TRILHA DE EVENTOS" bold 13 NV, depois +20 | |
| Trilha | eventos | 12px, do mais antigo ao mais novo; quantidade = max(3, floor((BOT-yy)/18)); "{data}  {texto até 96 caracteres}", passo 18 | |
| Rodapé do miolo | nota legal | 11.5 `#5b7383` em BOT-2: "Classificação das assinaturas conforme a Lei nº 14.063/2020. Este manifesto é parte integrante do documento." | |
| Rodapé | código, QR, selo | rodapé completo (não mini) | |

Selfie: o desenho não põe a imagem no manifesto. Spec etapa 7 previu `SelfieDoSignatario::abrir` para a etapa 3; ver lacuna L16.

### A3. Folha branca (`d.limpa`)

| Item | Valor | Estado(s) |
|---|---|---|
| Fundo das páginas do conteúdo | branco (`limpaP(pn) = d.limpa && pn <= ops.length`, dc:408) | |
| Cabeçalho | desenhado mesmo na folha branca (dc:410) | |
| Manifesto | mantém fundo e rodapé | |
| Rodapé com QR nas páginas do conteúdo | mantido (o desenho só troca o fundo) | |
| Nota no editor | "Se for enviado para assinatura, o manifesto final mantém o código e a trilha de eventos." (dc:181) | já é texto da etapa 2? conferir na tela do editor |
| Botão do detalhe | "Baixar PDF" (sem "com selo") (dc:462) | |

### A4. Duas colunas

Coberto em A1 (linhas "Duas colunas"). Particularidades: sem cabeçalho do escritório (`semCab`), rodapé mini (k=.8), prévia HTML já encolhe de 12.6 a 8.6 passo .2 (`public/js/assinatura-folha.js:241`), o PDF encolhe de 17.5 a 9.5 passo .25 (escala diferente, mesma ideia).

### A5. "Baixar PDF com selo" (quatro lugares)

| Região | Item | Valor do desenho | Estado(s) |
|---|---|---|---|
| Lista (linha) | botão ícone | `bi-file-earmark-pdf`, aria-label "Baixar PDF", dica "Baixar PDF com selo" (dc:149) | em todas as linhas, inclusive rascunho e Lixeira |
| Menu de contexto | item | "Baixar PDF com selo" `bi-file-earmark-pdf` (dc:443) | sempre, seleção única |
| Detalhe | botão primário `.bjaP` | `bi-file-earmark-pdf`; rótulo `dBaixarR` (dc:462): "Baixar manifesto com selo" (presencial/gov.br/ICP, etapa 5), "Baixar PDF" (folha branca), senão "Baixar PDF com selo" (dc:216-224) | sempre |
| Tela verde do signatário | botão | "Baixar PDF com selo", altura 44, fundo `#fff`, cor `#145a32`, 15px bold, `bi-file-earmark-pdf` (dc:267-273) | depois de assinar |
| Tela verde | "Concluir" | transparente, borda `rgba(255,255,255,.7)` | |
| Tela verde | caixa do código | bold 15 monospace, letter-spacing 1px, padding 7px 12px, borda 1px `rgba(255,255,255,.55)`, margin-top 6 | |
| Tela verde | texto | 16px: "Todas as assinaturas foram coletadas." / "Aguardando os demais signatários." | 2 estados |
| Etapa 5 | "Baixar para assinar" | `baixarPara: pres ? presOrig : pdf(dA,'assinar')` (dc:466) | fora desta etapa; o montador deve aceitar o modo |

### A6. Cartão do código (detalhe, dc:216-224)

| Item | Valor | Estado(s) |
|---|---|---|
| Caixa | `.bjaCard`, padding 12px 14px, coluna, gap 8 | |
| Rótulo | "CÓDIGO DE VALIDAÇÃO" 12px bold `#4a6373` | |
| Valor | bold 17px monospace `#a3232b`, letter-spacing 1px; `dCod = cod \|\| 'Gerado no envio'` | rascunho: "Gerado no envio" (já existe: `public/js/assinatura.js:858`) |
| Botões | "Continuar e enviar" (rascunho); Baixar (sempre, A5); "Vincular à pasta" `.bjaB` h38 `bi-folder-symlink` (`dPodeVinc`); "Cancelar envio" `.bjaB.bjaR` h38 | |
| Vínculo | link 13px bold `#145a32`, `bi-folder-check`, `dPastaTxt` | só com vínculo |

### A7. Aba Validar (dc:159-163, 432, 457)

| Região | Item | Valor | Estado(s) |
|---|---|---|---|
| Abas | terceira aba | `['val','Validar','bi-shield-check']` (dc:432) | hoje só Documentos e Modelos (`templates/assinatura_eletronica/index.html.twig:48-51`) |
| Contêiner | | padding 18px 16px, largura máxima 640 | |
| Título | "Validar documento assinado" | `b` 15px, margin-bottom 4 | |
| Texto | "Informe o código impresso no PDF ou leia o QR Code. Só tem validade o documento com registro correspondente." | 13px `#5b7383`, margin-bottom 10 | |
| Campo | `.bjaF`, aria-label "Código de validação", placeholder "BJA-XXXX-XXXX-XXXX" | flex 1 1 240px, bold 15px monospace, maiúsculas, letter-spacing 1px | |
| Botão | `.bjaP` `bi-shield-check` "Validar" | | |
| Linha 2 | `.bjaP` "Ler QR Code com a câmera" `bi-qr-code-scan` | flex 1 1 220px, h46, 15px, fundo `#0b3550`, margin-top 10 | |
| Linha 2 | `label.bjaB` "Enviar foto do QR Code" `bi-image` | h46, 14px; input file `accept="image/*" capture="environment"` escondido | |
| Resultado ok | caixa | margin-top 12, padding 12px 14px, borda 1px `#9fd0b2`, borda esquerda 4px `#1e7b45`, fundo `#e8f5ee`, cor `#145a32`, 13.5px, entrelinha 1.55 | código achado |
| Resultado ok | conteúdo | "**Registro localizado: {nome}**", "Situação: {st}", "Hash do documento: `{hash}`" (code 12px, quebra em qualquer ponto) e uma linha por signatário: "{nome}: assinado em {data}" ou "{nome}: {situação}" | situação crua no desenho; ver L14 |
| Resultado erro | caixa | borda `#f1c4c9`, esquerda 4px `#a3232b`, fundo `#fdecee`, cor `#7f1c23`, 13.5px: "**Código não localizado.** Confira a digitação." | |
| Regra | comparação | código aparado, maiúsculo, igualdade exata (dc:457); hash mostrado = `d.hash` (o do envio) | |
| Câmera (modal) | fundo | fixo, inset 0, z 2147483646, `#0b3550`, aria-label "Ler QR Code" (dc:275) | |
| Câmera | título | "Aponte a câmera para o QR Code", clamp(20px,3.6vw,26px) | |
| Câmera | quadro | quadrado min(82vw,58vh,420px), fundo `#072538`, vídeo cover; mira inset 14%, borda 3px `#59d98e` | |
| Câmera | mensagem | 14px `#c7dce9`, largura máx. 420: "Procurando o QR Code...", "QR Code lido, mas não é de um documento BlueJus.", "Câmera indisponível aqui. Use Tirar foto." (dc:338-343) | 3 estados |
| Câmera | botões | `label.bjaB` h40 fundo `#fff` `bi-camera` "Tirar foto"; "Cancelar" transparente, borda `rgba(255,255,255,.6)` | |
| Câmera | leitura | `getUserMedia` facingMode environment, ciclo 180ms, `BarcodeDetector` primeiro, senão jsQR em largura 640; regex `/BJA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/i` | |
| Câmera | ao achar | para a câmera, vai à aba Validar, som de dois tons (1568 e 2093 Hz), vibra 40ms, valida | |
| Foto | leitura | tenta larguras 1600, 2400, 900; toasts "QR Code do documento não encontrado na imagem." e "Não foi possível ler a imagem." (`#a3232b`) | |

### A8. Rota pública de validação

O desenho não tem tela própria; o QR aponta para `https://www.bluejus.com.br/assinar/validar/{cod}` (dc:350, 407) e o rodapé manda "Confira em bluejus.com.br/assinar/validar". Pela regra "omisso: convenção do sistema", a página pública usa o cabeçalho da página do signatário (`templates/assinatura_eletronica/publica.html.twig`) e o mesmo componente da aba Validar (A7). Spec 3.6: nunca mostrar CPF nem e-mail na página pública.

### A9. Vínculo à pasta

| Região | Item | Valor | Estado(s) |
|---|---|---|---|
| Lista | texto do vínculo | 12px bold `#145a32`, gap 4, margin-top 3, `bi-folder-check`; "Na pasta {nup} · {cliente}" ou "Irá para a pasta {nup} · {cliente}" (dc:149, 430); link para a pasta | feito / pendente |
| Lista | botão ícone | `.bjaB.bjaI` `bi-folder-symlink`, aria e dica "Vincular à pasta de Expediente"; `podeVinc = !vinc && (concluido \|\| aguardando)` (dc:430) | sem filtro de Lixeira (ver L11) |
| Menu | item "Vincular à pasta" | `bi-folder-symlink`; `!vinc && !lix && (concluido \|\| aguardando)` (dc:443) | |
| Detalhe | botão e link | A6 | |
| Modal | fundo | z 2147483100, `rgba(11,53,80,.55)`, padding 14 (dc:277) | |
| Modal | caixa | `.bjaCard` largura máx. 560, aria "Vincular à pasta" | |
| Modal | cabeçalho | `.bjaHd` `#0a6fa0` `bi-folder-symlink` "Vincular à pasta de Expediente" | |
| Modal | corpo | padding 12px 16px, gap 8 | |
| Modal | informação | 13px `#34505f`: "{nome}: o PDF com selo será salvo na pasta escolhida." (concluído) / "{nome}: será salvo na pasta quando todos assinarem." (dc:447) | 2 estados |
| Modal | busca | aria "Buscar pasta", placeholder "Buscar por NUP, cliente ou ação" | |
| Modal | lista | altura máx. 300, borda `#e6edf2`; item padding 8px 10px, 13.5px `#12242f`, hover fundo `#3b8fc2` texto branco; `b` largura mín. 64 (NUP), `span` flex 1 (cliente), `span` 12px (ação); até 60 resultados | |
| Modal | vazio | padding 14, 13px `#5b7383`: "Nenhuma pasta encontrada." / "A lista carrega após abrir a aba Expediente. Você também pode digitar o NUP." | ver L6 |
| Modal | manual | `.bjaB` "Vincular à pasta {q}" quando não há resultado e q casa `^\d{2,8}$` | ver L6 |
| Modal | rodapé | fundo `#f7f9fb`, `.bjaB.bjaN` "Fechar" | |
| Execução | concluído | gera o PDF, nome `'assinado_' + nome normalizado (até 60) + '.pdf'`, registra na Central (DOC), histórico da pasta "vinculou documento assinado: {nome} ({cod})" (dc:336-337) | |
| Execução | eventos | "Documento assinado vinculado à pasta {nup}" / "Definido o vínculo à pasta {nup} ao concluir" | |
| Execução | toasts | "Documento salvo na pasta {nup}." / "Será salvo na pasta {nup} quando concluído." / erro "Não foi possível vincular o arquivo." | |
| Conclusão | automático | ao concluir, se `vinc && !feito`, executa o vínculo (dc:386-390) | |

Textos só do JavaScript desta etapa (todos já listados acima): "PDF com selo gerado.", toasts do vínculo e da foto, mensagens da câmera, eventos da trilha, "Gerado no envio", "RASCUNHO", vazios do modal.

---

## b) Como o protótipo desenha o fundo, o selo e o QR (para portar a GD)

Tudo em canvas 2D com coordenadas W=1000 x H=1414, escalado por S=2.48. Na GD: trabalhar em 2480 x 3507 (multiplicar tudo por 2.48), ou desenhar em 2x e reduzir com `imagecopyresampled` para antisserrilhado (GD não tem antisserrilhado em linhas grossas; `imageantialias` só vale para espessura 1).

### b1. Semente e sorteio (dc:409)

```
sem = (parseInt(hs.slice(0, 8), 16) || 7) + pn * 1117
rnd(): sem ^= sem << 13; sem ^= sem >>> 17; sem ^= sem << 5; return (sem >>> 0) / 4294967296
ordem dos sorteios: p1 = rnd*6; f1 = .012 + rnd*.012; f2 = .03 + rnd*.02; fs = rnd*3; mm = 9 + floor(rnd*5)
```
No PHP: mascarar com `& 0xFFFFFFFF` depois de cada passo, `>>>` = deslocamento lógico sobre o valor sem sinal; o `<<` do JS trunca em 32 bits com sinal, mas como o resultado final é `>>> 0`, basta manter tudo em 32 bits sem sinal. Teste de paridade: fixar 3 hashes e comparar os 5 sorteios com valores tirados do JS (dá para rodar o trecho no Node).

### b2. Camadas do fundo (`fundo(g, pn, semCab)`, dc:409-410)

1. Gradiente íris linear de (0,0) a (W,H): `#f4f9fc, #f5fbf6, #fdf9f0, #f8f4fc, #f4f9fc` (5 paradas iguais). GD: imagem pequena (ex.: 64 x 90) pintada pixel a pixel e ampliada com `imagecopyresampled`.
2. Linhas onduladas: k = -2..63, y0 = k*H/60; ímpar `rgba(10,111,160,.11)`, par `rgba(30,123,69,.085)`; espessura .5; x de 0 a W passo 5; `y = y0 + 13*sin(x*f1 + p1 + k*.32) + 5*sin(x*f2 + k*.7)`.
3. Rosácea: 34 curvas de 200 segmentos, `r = 320*(.66 + .34*sin(mm*t + fs))`, centro (500,720), `rgba(11,53,80,.05)`, espessura .45 (ver dc:409 para o giro de cada curva).
4. Marca d'água: translate(500,707), rotate(-π/6.4), bold 18px `rgba(11,53,80,.04)`, texto `('BLUEJUS · ' + cod + ' · ')` repetido 9 vezes; linhas de yy -1050 a 1050 passo 62, x = -1400 + (i%2)*80. GD: `imagettftext` com ângulo 28.125 graus e cor com alfa (alfa GD 0..127).
5. Molduras: `strokeRect(12,12,W-24,H-24)` espessura 2 NV; `strokeRect(34,34,W-68,H-68)` espessura .8.
6. Borda ondulada: 3 fios com fases 0, 2.1, 4.2 e cores `#0a6fa0`, `#1e7b45`, `#8a6d1f`; espessura .7, amplitude 8, frequência .1, passo 2, ao longo do retângulo em 23.
7. Microtexto: 5px NV, `('BLUEJUS ASSINATURA ELETRONICA DOCUMENTO AUTENTICO ' + cod + ' ')` x9 cortado em 890 de largura, em (46, H-39).
8. Faixa lateral: translate(945,48); a cada 100 enquanto yy+74 < H-96: retângulo (0, yy+8, 12, 62) com gradiente `#7fb4cf, #d9eef7, #8fcaa5, #e8dca8, #7fb4cf`; texto "BLUEJUS ASSINA" bold 6.5 NV girado -90 graus em (8.5, yy+66).
9. Cabeçalho (exceto duas colunas): A1.

A prévia na tela já tem a versão "PRÉVIA" disso tudo em `public/js/assinatura-folha.js:51` (`fundo`, porta de `fundoPrev` dc:312-315), com as mesmas cores: serve de referência JS dentro do próprio repositório.

### b3. Selo (dc:412) e anéis (dc:313-314)

- Estrela de 64 pontas, raios sr e .914sr; preenchimento cônico a partir do ângulo 1.2: `#7fd0e8, #b4e7a8, #f4e08a, #f2b0c8, #b7a6f0, #7fd0e8` (sem cônico: radial). GD: pintar por pixel o ângulo (`atan2`) dentro do polígono (é pequeno: raio 58*2.48 = 144 px).
- 36 elipses rx = sr-8, ry = .3sr, giradas, `rgba(11,53,80,.4)` espessura .45.
- Círculo interno .55sr `rgba(255,255,255,.85)`.
- Concluído: visto `#1e7b45`, espessura .085sr, pontos (-.26,0), (-.07,.2), (.27,-.2) (x sr). Senão "PENDENTE" bold .112sr `#8a6200`.
- `seloAneis`: anel branco `rgba(255,255,255,.76)` entre .72sr e .83sr; texto circular "BLUEJUS AUTENTICO {cod} " (.088sr, raio .745sr, ângulo inicial `sem*.137`, `sem = 7 + Σ charCode*(i+3)`); "BLUEJUS · " bold .15sr no raio .405sr.
- `anelTxt` (dc:313): n = round(2πr / largura do texto), esc = 2π/total, início padrão -π/2 - largura('BLUEJUS')*esc/2; cada letra com `imagettftext` no ângulo tangente.
- Já portado em JS no repositório: `public/js/assinatura-folha.js:33` (anelTxt) e `:42` (seloAneis).

### b4. QR (dc:407)

- `qrcode(0,'M')`, conteúdo `'https://www.bluejus.com.br/assinar/validar/' + cod`; fundo branco com folga 4; módulos NV; célula u = s/n com sobreposição +.25 (evita fios brancos entre módulos).
- No servidor: `bacon/bacon-qr-code` v3.1.1 já instalado; usar `Encoder::encode($texto, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix()` e pintar os módulos com `imagefilledrectangle` (sem renderizador; mesmo controle de cor e sobreposição do desenho). Precedente de nível M: `src/TrabalhoExterno/Service/QrCodeEmSvg.php`.
- Versão do QR: o gerador JS escolhe o tipo automático (0); bacon também. A matriz pode diferir da do JS em máscara, mas o conteúdo lido é o mesmo (aceitável: o desenho não fixa o padrão).

### b5. Código BJA (dc:294, 375)

```
ALF = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'   (32 símbolos, sem I, O, 0, 1)
d.hash = sha(JSON(blocos) + id)
d.cod  = 'BJA-' + [0,4,8].map(i => 4 caracteres: ALF[parseInt(hash.substr((i+j)*2, 2), 16) % 32]).join('-')
```
Gerado no envio. Formato `BJA-XXXX-XXXX-XXXX` (12 símbolos, 60 bits). Decisão já tomada: o código sai de HMAC com segredo próprio do selo (não do hash puro, que seria adivinhável por quem tem o conteúdo). Proposta: `ChaveDoSelo::codigo(int $documentoId, string $hashEnviado)` = HMAC-SHA256(segredo, "v1|" + id + "|" + hash), 12 bytes mapeados em ALF (byte % 32, como o desenho), com prefixo "BJA-" e hífens; coluna `codigo` UNIQUE; colisão: tentar de novo com contador (probabilidade desprezível, mas a UNIQUE garante). O desenho só fixa o formato e o momento (envio); a origem por HMAC é decisão já tomada.

### b6. Montagem do arquivo (dc:421-425)

Uma imagem JPEG por página (DCTDecode), A4. Decisão tomada: Dompdf para compor + GD para o fundo + Ghostscript `pdfimage24` para achatar em imagem. Conferido no contêiner: Ghostscript 10.05.1 com `pdfimage24`, `pdfimage8`, `pdfimage32`, `png16m`, `jpeg`; GD com FreeType e JPEG (sem WebP); Dompdf v3.1.6.

---

## c) O que já existe no código (com caminho:linha)

### c1. Domínio Assinatura

- `src/AssinaturaEletronica/Entity/DocumentoParaAssinar.php`: colunas 57-129; `pasta_rotulo` é texto livre (não é FK); `hash_enviado` 119; `arquivo_enviado_chave/nome/paginas` 122-129; `enviar()` 269; `assinar()` 375-391 (marca Concluído e `concluidoEm`, devolve true ao concluir); `recusar()` 394; `exigirVez()` 406. Não há `codigo`, `arquivo_final_chave`, `hash_final`, `pasta_id`, `vinculo_pendente`.
- `Entity/Signatario`: tem `metodo`, `nome_digitado`, `cpf_digitado`, `rubrica_chave`, `motivo_recusa`, `ip`, `user_agent`, `latitude`, `longitude`, `precisao`, `selfie_chave`, `selfie_sha256`, `selfie_versao`. Não tem `recusado_em` (a data da recusa só está na trilha).
- `UseCase/EnviarDocumentoUseCase.php:57-71`: calcula hash, `enviar()`, hashes dos tokens, evento `DocumentoEnviado`. Lugar de gerar o código.
- `UseCase/AssinarDocumentoUseCase.php`: `executar` 81-114 (transação com arquivo novo; correio depois do COMMIT na linha 110); `registrar` 222-240 (ao concluir: evento `DocumentoConcluido` e `CartaDaAssinatura::conclusao`). Ponto de gancho da conclusão.
- `Service/ChaveDaAssinatura.php`: VERSAO=1, TAMANHO_MINIMO=32, parâmetro `assinatura.segredo`, HMAC de token e código. Modelo para `ChaveDoSelo`. Parâmetros em `config/services.yaml:15-18`.
- `src/Shared/Service/ConferenteDeSegredos.php:18, 47-65` e `src/Shared/Command/ConferirSegredosCommand.php`: lista de segredos conferidos. Runbooks modelo: `docs/runbooks/assinatura-segredo-em-producao.md`, `assinatura-selfie-chave-em-producao.md`.
- `Armazenamento/ChavesDeAssinaturaEletronica.php:28-99`: categoria `ASSINATURA_DOCUMENTO`, escopo do escritório. Falta a chave do PDF final.
- `Repository/DocumentoParaAssinarRepository.php`: `arquivosEmUso` 110 (só pdf e logo: incluir o final), `travarERecarregar` 136, `emTransacao` 270, `emTransacaoComArquivoNovo` 287.
- `Service/ApresentacaoDoDocumento.php`: `resumo` 44-66 (`'pasta'` = rótulo livre), `detalhe` 70-95, `signatario` 170-186. Máscara de e-mail atual mostra 1 caractere (`a***@x`); o manifesto do desenho mostra 2.
- `Service/DadosPadraoDoEscritorio.php` (razão, endereço, telefone) e enum `TipoDeLogo` (Bluejus, Escritório, Própria); logos em `public/images/logo/`.
- `Controller/AssinaturaPublicaController.php:65-136`: rotas públicas; pega-tudo `/assinar/{resto}` na linha 136 com prioridade -10 (a rota `/assinar/validar` precisa de prioridade maior ou ficar antes).
- `EventListener/AssinaturaPublicaListener.php:32-34, 73-81`: protege pelo prefixo de nome `assinatura_eletronica_publica_` (noindex, no-store, filtro de escritório desligado).
- `config/packages/security.yaml:62`: `^/assinar/` com PUBLIC_ACCESS. `config/packages/assinatura_eletronica.yaml`: limitadores (acrescentar o da validação).
- Chave do Master `assinatura`: global (`src/Master/Service/CatalogoDeRecursos.php:47-48, 83`; `src/Master/EventListener/BloqueioDeRecursoDesativado.php`); o prefixo `assinatura_eletronica_` cobre também as rotas públicas novas.
- Tela: `templates/assinatura_eletronica/index.html.twig` (urls 25-31; abas 48-51 sem Validar; `qrcode-generator` já carregado na linha 111); `public/js/assinatura.js` (1545 linhas; cabeçalho na linha 15 diz "Ficam para depois: código BJA, Baixar PDF com selo, Vincular à pasta e aba Validar"; cartão do código 858).
- `public/js/assinatura-folha.js`: `COD_VAZIO` 23, `QR_VALIDAR` 24, `anelTxt` 33, `seloAneis` 42, `fundo` 51, `qr` 122, `blocoV` 131, `html(o)` 142, `pintar` 229 (duas colunas 241); exporta `window.BJAFolha`.
- `public/js/assinatura-publica.js:352-360`: tela verde sem código nem download; `som()` na linha 333 (reaproveitar no bipe da leitura do QR).
- `templates/assinatura_eletronica/publica.html.twig:48`: carrega `qrcode-generator`.
- Traduções: `translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml:191-205` (chaves da folha); `translations/assinatura_eletronica/...` (textos dos eventos, `TipoDeEvento` de `rascunho_salvo` a `envio_cancelado`).
- Migrations: `Version20261109150000` (M1, chave desligada), `260000` (M3), `270000` (M5 selfie). A M2 desta etapa é `Version20261109290000`.

### c2. PDF, imagem e QR

- `src/Shared/Service/GeradorDePdfComDompdf.php:28-54`: acrescenta rodapé "BlueJus · página"; não serve como está (precisa de Dompdf sem rodapé e com fundo por página). Interface `GeradorDePdf`.
- `src/Pasta/UseCase/ExportarPecaTextoUseCase.php:340-352`: Dompdf com `chroot` na `AreaTemporariaPrivada` e `isRemoteEnabled` falso (modelo de uso seguro).
- `src/Shared/Service/ExecucaoDoGhostscript.php:37-53`: `rodar(array $args, timeout)`. `symfony/process` já está no `require` (commit bf5240ce3).
- `src/Shared/Armazenamento/AreaTemporariaPrivada.php`: `criar` 39, `gravar` 70, `liberar` 92.
- `CompressorArquivo.php:288` (gs pdfwrite) e `ContadorDePaginasPorGhostscript` (precedentes).
- `src/TrabalhoExterno/Service/QrCodeEmSvg.php`: bacon, nível M.
- `public/js/vendor/qrcode-generator/qrcode.js` existe; jsQR não existe em `public/js/vendor/`. Teste `tests/Arquitetura/ScriptsDeCdnComIntegridadeArquiteturaTest.php` vigia scripts de CDN: servir jsQR do próprio sistema (`public/js/vendor/jsqr/jsQR.js`, v1.4.0 como o desenho dc:23).
- Fontes no contêiner: `/usr/share/fonts/opentype/urw-base35/NimbusSans-{Regular,Bold}.otf` (clone de Arial/Helvetica) e `NimbusMonoPS-*.otf` (clone de Courier), vindas do pacote ghostscript; DejaVu em `vendor/dompdf/dompdf/lib/fonts`. Ver L18.
- `Dockerfile:15-21`: libjpeg, libfreetype, ghostscript, gd com freetype e jpeg.

### c3. Pasta (vínculo)

- `src/Pasta/Entity/PastaDocumento.php`: categorias 27-33 (PECA, PROCURACAO, IDENTIFICACAO, COMPROVANTE_RESIDENCIA, GRATUIDADE_JUSTICA, DEMAIS, CONTRATO); campos de assinatura 132-141 (`assinatura_origem` com 10 caracteres); `marcarAssinadoDigitalmente` 494, `marcarAssinadoManualmente` ~518, `desmarcar` 534; é Auditável (o log de auditoria é o histórico da pasta).
- `src/Pasta/Enum/OrigemDaAssinatura`: só Digital e Manual.
- `src/Pasta/UseCase/MarcarContratoAssinadoPeloArquivoUseCase.php`: `executar(Pasta, PastaDocumento, User, ?Tenant)` exige User; só vale com categoria CONTRATO, documento assinado e nome original com /contrato/i; `AlterarSituacaoContratoUseCase::executar` 34-50 dá flush dentro. Único chamador: `PastaController::financeiroUpload` 2104-2186 (canAccessResource edit, `ChavesDePasta::novoDocumento`, `syncDispatcher->despachar` para o Drive).
- `src/Pasta/UseCase/UploadPecaUseCase.php:204-262`: `ArquivoArmazenado` + `GeradorDeCodigoDeDocumento` (código DOC da Central) + `AvisoDePastaAcompanhada::aoIncluirDocumento`. É o equivalente do `BJDocs.registrar` do desenho.
- `src/Pasta/Repository/PastaRepository.php:433` `buscarParaVinculo(termo, tenant, limite, idsExcluidos, quemBusca)`: devolve `{id, texto}` (texto unido com " - "), exclui lápide, restringe às pastas editáveis pelo cadeado, UNACCENT em nup, nomeCliente e nomeAcao. Chamadores: Dashboard, `Djen/.../PublicacaoPastaController:48`, Cobrança. O modal do desenho precisa de nup, cliente e ação em campos separados (ver bloco 3).
- `src/Service/PermissionChecker.php:78` `canAccessResource('pasta', id, 'edit')` (inclui o teto do cadeado). `docs/AUTORIZACAO.md:504-523`: o listener de lápide não vê rota com id da pasta só no corpo; o UseCase confere `Pasta::estaExcluida()` (`src/Pasta/Entity/Pasta.php:450`). `Pasta.php:53-56`: NUP não é único.
- Modelos prontos (`public/js/assinatura-modelos.js`): proc-geral, proc-simples, proc-cnpj, proc-inv, proc-menor, subst, renuncia, contrato, notif, comp, hipo, conta, resid. Mapeamento sugerido de categoria: procurações e subst = PROCURACAO; contrato = CONTRATO; hipo = GRATUIDADE_JUSTICA; resid = COMPROVANTE_RESIDENCIA; demais, upload e modelos do escritório = DEMAIS.

### c4. Transversais

- R2: toda coluna nova `*_chave` entra em `src/Shared/EspelhoRemoto/Reconciliacao/FontesDeReferencia.php:60-67` e `Liberacao/DatasDasReferencias.php:33-34` (teste `ColunasDeChaveNoEspelhoArquiteturaTest`). A sessão do R2 é exclusiva: avisar o Coordenador.
- `src/Tenant/UseCase/PurgarEscritorioUseCase.php` ~317-320 apaga as tabelas da assinatura antes de `pasta` (355): FK `pasta_id` não quebra a purga; mesmo assim `ON DELETE SET NULL`.
- Messenger: transporte `async` (doctrine); precedente `#[AsMessage('async')]` em `src/Contribua/Message/ReconsultarCobrancaNoBanco.php:10` (evita mexer no `messenger.yaml`, arquivo quente). Dev não tem worker.
- Domínio de produção: nginx `server_name bluejus.com.br` (www redireciona); `DEFAULT_URI` no roteamento. nginx tem `camera=()` nas linhas 51 e 233 (precisa `camera=(self)`, pendência do Samuel na spec 8).

---

## d) Divisão proposta

### d0. Contrato comum (um PR antes de abrir os blocos; quem coordena escreve)

1. Migration M2 `Version20261109290000` (skill `criar-migration`), em `assinatura_documento`: `codigo VARCHAR(18) NULL UNIQUE`, `arquivo_final_chave VARCHAR NULL`, `hash_final CHAR(64) NULL`, `pasta_id INT NULL FK pasta ON DELETE SET NULL`, `vinculo_pendente BOOL NOT NULL DEFAULT false`, `vinculado_por_id INT NULL FK user ON DELETE SET NULL`, `vinculado_em TIMESTAMP NULL`, `pasta_documento_id INT NULL FK pasta_documento ON DELETE SET NULL` (o arquivo que foi para a pasta). Mapeamento na entidade com métodos `definirCodigo`, `guardarArquivoFinal`, `vincularAPasta`, `marcarVinculoFeito`.
2. `ChaveDoSelo` (modelo `ChaveDaAssinatura`): segredo `ASSINATURA_SELO_SEGREDO`, parâmetro `assinatura.selo_segredo`, VERSAO 1, mínimo 32; vazio em `.env`, fixo em `.env.test`, nome em `.env.prod.example`; entrada no `ConferenteDeSegredos`; runbook `docs/runbooks/assinatura-selo-segredo-em-producao.md`. O Samuel cria o valor em produção.
3. Código gerado no `EnviarDocumentoUseCase` (b5). Sem segredo: o envio recusa com mensagem (L19).
4. Interfaces (assinaturas fixas, implementações nos blocos): `MontadorDoPdfFinal::montar(DocumentoParaAssinar, ModoDoPdf): ArquivoGerado` (bloco 1); `MolduraDaFolha` (fundo, cabeçalho, rodapé, selo; bloco 1, usada pelo 4); `PaginasDoManifesto::desenhar(...)` (bloco 4); `VinculoComAPasta::aplicarPendente(DocumentoParaAssinar)` (bloco 3).
5. Gancho da conclusão: no `AssinarDocumentoUseCase::registrar`, depois do COMMIT, despachar mensagem `DocumentoConcluidoParaSelar` (`#[AsMessage('async')]`); o handler chama `MontadorDoPdfFinal` (guarda `arquivo_final_chave` e `hash_final`) e depois `VinculoComAPasta::aplicarPendente`. Download sob demanda gera e guarda se ainda não existir (dev sem worker).
6. Casos novos em `TipoDeEvento` (vínculo definido, vínculo feito, vínculo não aplicado, PDF final gerado) com textos; chaves de tradução novas já criadas no yaml da tela (todas as de a); campos `codigo` e `vinculo` em `ApresentacaoDoDocumento::resumo/detalhe`; nomes de rota reservados.
7. Entradas no espelho R2 (`FontesDeReferencia`, `DatasDasReferencias`) para `arquivo_final_chave`, combinadas com a sessão do R2.
8. Chave nova em `ChavesDeAssinaturaEletronica` (`pdfFinal`) e `arquivosEmUso` incluindo o final.

### d1. Bloco 1: montador do PDF no servidor

Porta de `fundo`, `selo`, `seloAneis`, `anelTxt` para GD (b1 a b4, com teste de paridade da semente); cabeçalho e rodapé com QR do bacon; diagramação de `pdf()` (página inteira e duas colunas com redução de fonte; paginação feita pelo próprio montador, ver L1); páginas de PDF enviado rasterizadas pelo Ghostscript `png16m`; folha branca; Dompdf com uma página por folha (fundo da página como imagem) e achatamento final com `pdfimage24` (conferir tamanho e compressão JPEG); rotas `GET /assinatura/documentos/{id}/pdf` (interna) e `GET /assinar/{token}/pdf` (signatário); nomes `assinado_`/`para_assinar_`. Arquivos próprios: `src/AssinaturaEletronica/Pdf/*` (novo), controller interno e público só na ação do PDF, testes.

### d2. Bloco 2: código e validação

Rotas públicas `GET /assinar/validar` e `GET /assinar/validar/{codigo}` (nome `assinatura_eletronica_publica_validar*`, prioridade acima do pega-tudo, limitador por IP), JSON para a aba; aba Validar na tela (A7) em JS próprio `public/js/assinatura-validar.js`, reaproveitado pela página pública; jsQR em `public/js/vendor/jsqr/`; `BarcodeDetector` primeiro; modal da câmera; foto; código na tela verde e botão Baixar (`assinatura-publica.js`, usando a rota do bloco 1); cartão do código no detalhe. A resposta pública nunca leva CPF nem e-mail (spec 3.6).

### d3. Bloco 3: vínculo à pasta

Modal (A9); busca (método novo no `PastaRepository` devolvendo id, nup, cliente e ação separados, com as mesmas travas de `buscarParaVinculo`); `VincularAPastaUseCase` (canAccessResource edit, `estaExcluida`, estados permitidos); vínculo pendente aplicado na conclusão com o ator `vinculado_por` (L3); `PastaDocumento` + `ArquivoArmazenado` com código DOC + categoria pelo modelo (c3) + marca de assinado com origem nova `eletronica` (L10) + `MarcarContratoAssinado` quando couber + `despachar` do Drive; textos "Na pasta"/"Irá para a pasta" só para quem enxerga a pasta.

### d4. Bloco 4: manifesto

Página(s) do manifesto (A2): cartões, máscaras (CPF `***.XXX.XXX-**`, e-mail com 2 caracteres, "não informado"), lei pelo nível, método a partir de `metodo`, selfie e localização, data da recusa tirada da trilha, trilha do mais antigo ao mais novo cortada em 96 caracteres, nota legal; continua em página extra quando não cabe (L8). Usa a `MolduraDaFolha` do bloco 1 (pode começar com um esboço da interface).

### d5. Dependências e arquivos quentes

- Ordem: d0 primeiro; 1 e 2 em paralelo logo depois; 4 depende da moldura do 1 (começa com esboço); 3 depende dos bytes do PDF final (pela interface, testa com dublê).
- Arquivos compartilhados (dono indicado): migration M2, `config/services.yaml`, `.env`/`.env.test`/`.env.prod.example`, `ConferenteDeSegredos`, `TipoDeEvento`, `ApresentacaoDoDocumento`, yaml de traduções, `FontesDeReferencia`/`DatasDasReferencias` (todos no d0); `public/js/assinatura.js`, `index.html.twig`, `assinatura.css` (blocos 2 e 3 mexem: separar em JS próprios e deixar no `assinatura.js` só a ligação, feita no d0 com pontos de entrada vazios); `AssinarDocumentoUseCase` (só o d0); arquivos do domínio Pasta (`OrigemDaAssinatura`, `PastaRepository`, só o bloco 3); `assinatura_eletronica.yaml` (limitador, bloco 2); `messenger.yaml` não é tocado (usar `#[AsMessage]`).

---

## e) Lacunas e conflitos (recomendação pelas 3 regras: 1 fiel ao desenho; 2 função do sistema não sai; 3 extra só se necessário)

| # | Lacuna | Recomendação |
|---|---|---|
| L1 | Dompdf não varia o fundo por página automática, e a redução de fonte das duas colunas exige medir o texto. | Paginação feita pelo montador (porta do `pdf()` do desenho, medindo com as métricas da fonte), cada folha uma página do Dompdf com o seu fundo. Alternativa: montar tudo na GD e só embrulhar em PDF (mais simples, mas texto vira imagem já na origem, o que de todo modo acontece no achatamento). Regra 1. |
| L2 | `pdf()` e a prévia HTML divergem ("Assinado em" x "Assinado eletronicamente em", cabeçalho em toda página, métricas). | O PDF final segue `pdf()` (é o desenho do PDF); a prévia fica como está. Regra 1. |
| L3 | A conclusão acontece na rota pública, sem usuário logado; `MarcarContratoAssinado` e `AlterarSituacaoContrato` exigem User; permissão, cadeado e lápide podem ter mudado desde o vínculo. | Guardar `vinculado_por_id`; na conclusão, reconferir com esse usuário; se perdeu acesso, pasta na lápide ou usuário removido: não grava, mantém pendente, registra evento "vínculo não aplicado". Regra 2 (não fura permissão). |
| L4 | Gerar o PDF em imagem dentro do POST público de assinatura é pesado (segundos). | Mensagem assíncrona depois do COMMIT + geração sob demanda no primeiro download (dev não tem worker). |
| L5 | Download na tela verde precisa de rota pública por token; depois de "Assinatura já registrada" o desenho não oferece download. | Rota `GET /assinar/{token}/pdf` válida enquanto o token valer; sem download fora da tela verde (regra 1). |
| L6 | O desenho busca pastas do localStorage e permite "digitar o NUP"; no sistema a lista vem do servidor e o NUP não é único. "A lista carrega após abrir a aba Expediente..." não tem lastro. | Vínculo sempre por id; só o vazio "Nenhuma pasta encontrada."; o botão manual "Vincular à pasta {q}" só quando exatamente uma pasta editável tem aquele NUP (ou sai). Pergunta ao Coordenador se sai. |
| L7 | Duas colunas que não cabem a 9.5 geram página em branco no desenho. | Continuar na segunda página com a mesma diagramação (regra 3: necessário, senão perde texto assinado). Alternativa: recusar o envio com aviso. |
| L8 | Manifesto com muitos signatários não cabe numa página (o desenho só desenha uma). | Continuar em páginas extras com a mesma moldura; trilha completa (não cortar evento em documento probatório). Regra 3. |
| L9 | Qual hash mostrar na validação. | `hash_enviado` (é o impresso no PDF, como o desenho); `hash_final` guardado só como prova do arquivo. |
| L10 | `OrigemDaAssinatura` não tem origem eletrônica (só Digital e Manual). | Caso novo `eletronica` (cabe nos 10 caracteres); mexe no domínio Pasta (bloco 3). |
| L11 | O desenho se contradiz: botão de vincular na linha aparece na Lixeira, o menu não. | Usar a regra do menu (sem Lixeira) nos dois. |
| L12 | Download de rascunho ("RASCUNHO", hash provisório) existe no desenho. | Entra (regra 1), sem guardar arquivo. |
| L13 | Validar documento que está na Lixeira. | Mostra o registro (a prova continua); situação como está. |
| L14 | A validação do desenho mostra a situação crua ("pendente", "recusado"). | Usar o rótulo de situação que o sistema já tem. |
| L15 | A chave `assinatura` do Master é global: ligar "para o escritório piloto" liga para todos os escritórios cujos perfis receberam o módulo. | Avisar o Samuel antes de ligar em produção. |
| L16 | A selfie não aparece no manifesto do desenho; a localização aparece no texto do método, num PDF entregue a todas as partes. | Não incluir a imagem (regra 1); manter as coordenadas como o desenho, e anotar no pacote jurídico (LGPD). |
| L17 | O QR do desenho usa `https://www.bluejus.com.br/...` fixo; o sistema tem `DEFAULT_URI`, e o www redireciona. | Montar a URL pelo roteador com URL absoluta (vale em dev e produção); o texto impresso segue o desenho. A página `/assinar/validar` sem código precisa existir (o rodapé manda para ela). |
| L18 | Fontes: Helvetica do Dompdf é só WinAnsi; NimbusSans e NimbusMonoPS chegam por tabela com o pacote ghostscript; DejaVu é mais larga que Arial. | Usar NimbusSans e NimbusMonoPS pelo caminho do sistema e declarar a dependência no Dockerfile (pacote `fonts-urw-base35`) para não sumir num rebuild. |
| L19 | Sem o segredo do selo, não há código. | Envio recusa com mensagem no estilo do desenho (nova chave de tradução); `ConferirSegredosCommand` acusa. |
| L20 | O desenho não tem "desvincular". | Não criar (regra 3). |
| L21 | Documentos já enviados no dev sem código (a migration não gera, falta o segredo). | Gerar o código na primeira leitura quando faltar (ou comando de preenchimento); em produção a chave está desligada, então não há legado. |
| L22 | Câmera depende do nginx (`camera=()`), e o PDF "para assinar" guardado é da etapa 5 (M4). | Samuel troca para `camera=(self)`; até lá o botão da foto funciona. O montador já aceita o modo "para assinar", sem guardar. |
