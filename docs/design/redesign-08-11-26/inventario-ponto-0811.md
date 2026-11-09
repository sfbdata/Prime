# Inventário do desenho: Ponto Eletrônico (VS 08.11.26)

Arquivo lido inteiro (837 linhas, 151 KB):
`docs/design/redesign-08-11-26/16 - Ponto Eletronico - VS 08.11.26.dc.html`

Também lidos: `bj-recursos.js`, `bj-monitor.js`, `bj-lembretes.js`, `bj-tema.js`, `bj-plano.js`,
`bj-idioma.js`, `bj-dica.js`, `bj-compartilhar.js`, trechos de `bj-contas.js` e `bluejus-central.js`,
`ESPEC_PONTO_ELETRONICO.md`, `CLAUDE.md` da pasta. Não há `canvas.json` nem `uploads/` na pasta:
a tela tem uma prancheta só (desktop fluido, com responsivo por `@media`).

Referência de linha: `Lnnn` = linha do `.dc.html`. Funções do JS citadas pelo nome do método
da classe `Component` (L396 a L834).

---

## 0. Base visual (vale para a tela toda)

| Token | Valor do desenho | Onde |
|---|---|---|
| Fonte | `Arial, Helvetica, sans-serif` | `body` L28 |
| Fundo da página | `#f3f6f8` | `body` L28 |
| Texto padrão | `#12242f` | `body` L28 |
| Link | `#0a6fa0`, hover `#0b5f86` | L29 |
| Azul do sistema (barras de título, botão principal) | `#0a6fa0` | todo o arquivo |
| Azul do cabeçalho global | `#0a76a8` | header L80 |
| Cartão (section) | `background:#fff; border:1px solid #d3dde4; border-radius:3px; box-shadow:0 1px 3px rgba(18,36,47,.06)` | L105, L144, L166 |
| Raio padrão | `3px` (81 ocorrências); chave liga/desliga tem botão `2px` | todo |
| Verde ok | `#1e7b45` (texto escuro `#145a32`, fundo `#e8f5ee`, borda `#9fd0b2`) | |
| Vermelho erro | `#a3232b` (texto escuro `#7f1c23`, fundo `#fdecee`, borda `#f1c4c9`) | |
| Âmbar aviso | `#7a5200` texto, `#fff8e1` / `#fff3cd` fundo, `#e0c36a` / `#d9a300` borda, `#8a6200` selo | |
| Azul claro info | `#e5f1f7` fundo, `#9fc3d8` borda, `#0b4f75` texto | |
| Cinzas de texto | `#34505f`, `#4a6373`, `#5b7383`, `#243845` | |
| Linhas | `#e6edf2` (divisória interna), `#d3dde4` (borda de campo e cartão) | |
| Tabela `.bjpT` | `width:100%; border-collapse:collapse; font-size:14px` | L33 |
| `th` | `padding:10px 12px; font-size:12px; letter-spacing:.3px; color:#34505f; background:#eef3f6; border-bottom:1px solid #d3dde4; white-space:nowrap; text-align:left` | L34 |
| `td` | `padding:9px 12px; border-bottom:1px solid #e6edf2; white-space:nowrap; font-variant-numeric:tabular-nums` | L35 |
| Linha listrada (só Folha, `.bjpLin`) | ímpar `#eef2f5`; hover `td` fundo `#3b8fc2`, texto `#fff`, botões e `.bjpSm`/`.bjpPl` brancos | L36 a L39 |
| Linhas das outras tabelas (`.bjpJ`) | sem listra e sem hover (classe sem regra no CSS) | L182, L206, L215, L223, L254 |
| Campo de formulário | `height:34px; border:1px solid #d3dde4; border-radius:3px; padding:0 8px; font-size:13.5px; color:#12242f; background:#fff` (modais: `height:36px; padding:0 10px; font-size:14px`) | |
| Rótulo de campo | `font-size:12px; font-weight:bold; color:#4a6373; gap:5px` em coluna | |
| Botão secundário azul | `border:1px solid #0a6fa0; background:#fff; color:#0a6fa0; border-radius:3px`, hover `#e5f1f7` | |
| Regra do dono da pasta (CLAUDE.md) | "Arial, azul do sistema #0a6fa0, cantos pouco arredondados (estilo PJe), hover de linha azul #3b8fc2 com texto branco, cards no padrão do Dashboard" | `redesign-08-11-26/CLAUDE.md` |

Ícones: Bootstrap Icons (`./bootstrap-icons/bootstrap-icons.min.css`). Exceções em SVG inline:
digital do botão "Bater Ponto" (desenho do `bi-fingerprint`, 24px, L129), alto-falante ligado
(`bi-volume-up`, 18px) e mudo (`bi-volume-mute`, 18px) no L136, check e X animados da tela cheia.
Biblioteca externa: `qrcode-generator@1.4.4` (jsDelivr) para o QR do comprovante e da folha.

---

## 1. Mapa das telas, abas, modais e estados

### 1.1 Pranchetas / regiões (`data-screen-label`)

| Região | Linha | Conteúdo |
|---|---|---|
| Cabeçalho | L80 a L89 | tarja azul global + menu de telas (nav) L90 a L98 |
| (título da página) | L101 | `h1` "Ponto Eletrônico" |
| Registrar Ponto | L105 a L140 | coluna esquerda (`.bjpEsq`) |
| Folha de Ponto | L144 a L164 | coluna direita (`.bjpDir`), primeiro cartão |
| Registros e comprovantes | L166 a L261 | coluna direita, segundo cartão, com abas |
| Toast | L266 | aviso flutuante no rodapé |
| Modais | L269 a L320 | uma moldura única, 8 conteúdos (9 com as duas variantes de análise) |
| Tela cheia de validação | L323 a L391 | 4 fases: facial, validando, sucesso, erro |

### 1.2 Papéis (o desenho distingue três)

Função `papel()` L467: se o Master escolheu "Ver como", usa a escolha; senão Master
(`BJRecursos.master()`: e-mail `farlei.rocha@gmail.com` ou `u.master`), senão primário
(`BJContas.souPrimario()`), senão colaborador.

| O que | Colaborador | Usuário primário (gestor) | Master |
|---|---|---|---|
| Rótulo no cabeçalho (`papelTxt`, L787) | `COLABORADOR` | `USUÁRIO PRIMÁRIO` | `MASTER · USUÁRIO PRIMÁRIO` |
| Registrar Ponto, Folha, Pendências, Minhas Justificativas, Comprovantes, Banco de Horas | vê | vê | vê |
| Botões Abonar / Recusar na aba Minhas Justificativas (`gere`, `podeA = ro !== 'colaborador'`, L743, L755) | não | sim | sim |
| Lixeira da justificativa pendente (`aberta`) | sim | sim | sim |
| Abas Equipe, Auditoria, Configurações (`cfgVis`, L757 a L758) | não (se a aba ativa for uma delas, cai em Pendências) | sim | sim |
| Chave "Global" em cada função (`f.vM = euM`, L761) | não | não | sim |
| Chave "Escritório" em cada função | não | sim | sim |
| Texto de ajuda da Configuração (`cfgTxt`, L798) | | "Usuário primário: define como o ponto funciona no seu escritório, dentro do que o Master liberou." | "Master: a chave Global libera ou bloqueia a função para todos os escritórios. Com a função liberada, cada usuário primário decide o uso no seu escritório." |
| Barra de teste do protótipo "Ver como" / "Próxima batida" (`euMaster`, L228 a L234, L797) | não | não | sim (continua visível depois que o Master troca o "Ver como") |
| Tela desligada no `bj-recursos` (id `ponto`) | página "Ponto Eletrônico em preparação" | idem | vê a tela, com faixa vermelha "Esta tela está desativada para os usuários. Só o Master está vendo." |

### 1.3 Abas do cartão "Registros e comprovantes" (L758)

Ordem, ícone, contador (selo) e quem vê. Aba inicial: `aba: 'pend'` (Pendências), L430.

| # | id | Rótulo | Ícone | Selo (número) | Cor do selo | Aparece quando |
|---|---|---|---|---|---|---|
| 1 | pend | Pendências | `bi-exclamation-triangle` | qtde de pendências (`pendF.length`) | `#8a6200` (o código declara `#b8860b` e troca para `#8a6200`) | sempre |
| 2 | just | Minhas Justificativas | `bi-calendar-check` | qtde de justificativas da competência (todas, não só pendentes) | `#0a6fa0` | sempre |
| 3 | comp | Comprovantes | `bi-receipt` | qtde de comprovantes guardados | `#0a6fa0` | só com `ponto-comprovante` ligado (senão a aba some e, se ativa, volta para Pendências) |
| 4 | banco | Banco de Horas | `bi-piggy-bank` | sem selo (0) | | sempre |
| 5 | eq | Equipe | `bi-people` | justificativas a analisar (equipe + próprias pendentes) | `#8a6200` | primário e Master |
| 6 | log | Auditoria | `bi-shield-lock` | sem selo | | primário e Master |
| 7 | cfg | Configurações | `bi-sliders` | sem selo | | primário e Master |

Selo só aparece com número maior que 0 (`tem: n > 0`).

### 1.4 Modais (moldura L269 a L320; tabela de títulos `mt` L766)

| id (`state.modal`) | Título | Ícone | Botão principal | Botão secundário | Aberto por |
|---|---|---|---|---|---|
| esq | Registro de esquecimento | `bi-plus-circle` | Enviar | Cancelar | "+" da Folha, "Enviar registro de esquecimento" de Pendências, "Regularizar" do Registrar |
| just | Justificar falta | `bi-calendar-check` | Enviar | Cancelar | botão "Justificar falta" |
| val | Validar comprovante | `bi-shield-check` | Validar | Fechar | "Validar código" (aba Comprovantes) |
| cons | Cadastro facial | `bi-person-bounding-box` | Continuar | Cancelar + "Não autorizo" (à esquerda) | primeira batida facial sem cadastro |
| alerta | Antes de registrar | `bi-exclamation-triangle` | Registrar assim mesmo | Aguardar | batida com alerta de intervalo, interjornada ou descanso semanal |
| ext | Trabalho externo | `bi-briefcase` | Registrar | Cancelar | "Registrar como trabalho externo" na tela de erro (fora do raio) |
| ass | Assinar folha | `bi-pen` | Confirmar | Cancelar | "Assinar folha" na barra da Folha |
| ana (abonar) | Abonar justificativa | `bi-clipboard-check` | Abonar | Cancelar | "Abonar" em Minhas Justificativas |
| ana (recusar) | Recusar justificativa | `bi-clipboard-check` | Recusar | Cancelar | "Recusar" em Minhas Justificativas |

### 1.5 Tela cheia de validação (`state.fase`, L323 a L391)

| Fase | Condição (`sc-if`) | Quando |
|---|---|---|
| face | `fFace` | batida com reconhecimento facial (câmera) |
| val | `fVal` | anel de 24 barras + etapas, 2,8 s (`VAL_T = 2800`) |
| ok | `fOk` | sucesso, verde; fecha sozinho em 10 s |
| erro | `fErro` | falha, vermelho (aparece em 55% do tempo da validação, 1,54 s) |

Sem a função `ponto-animacao` (e sem facial) não há tela cheia: o resultado vira toast.

### 1.6 Condicionais `sc-if` relevantes (estado escondido no print)

| `sc-if` | Mostra | Padrão no print |
|---|---|---|
| `bancoAcima` (L106 e L205) | triângulo amarelo no título e faixa na aba Banco | escondido (no dado de exemplo fica visível: 529h > 120h) |
| `geoVis` | caixa de localização | visível |
| `avisoVis` | caixa de intervalo / saída prevista | escondido |
| `filaVis` | "Sem internet: N batida(s) aguardando envio." | escondido |
| `ultPendVis` | "Última pendência ... Regularizar" | escondido |
| `iconeDigital` / `iconeFace` | ícone do botão principal | digital |
| `faceOpc` | botão "Registrar com reconhecimento facial" | visível |
| `sonsVis` / `somLigado` / `somMudo` | botão de som e seus dois ícones | visível, ligado |
| `aceTem` | selo "Assinada em" / "Ressalva em" na barra da Folha | escondido |
| `podeAss` | botão "Assinar folha" | escondido |
| `c.tem`, `c.aj`, `c.mais`, `c.tr` | célula de batida: hora, marca "aj", botão "+", traço | hora |
| `l.plano` / `l.selo` / `l.temJ` | saldo do dia em texto ou em selo; selo da justificativa | selo |
| `folhaVazia` | "Nenhuma batida nesta competência." | escondido |
| `a.tem` | número na aba | escondido |
| `abaPend`, `abaJust`, `abaComp`, `abaBanco`, `abaEq`, `abaLog`, `abaCfg` | conteúdo de cada aba | Pendências |
| `pendVazia`, `justVazia`/`justTem`, `compVazia`, `eqJVazia`, `logTem`/`logVazio` | vazios das listas | |
| `j.temMot`, `j.trava`, `j.gere`, `j.aberta` | motivo, cadeado, Abonar/Recusar, lixeira | cadeado |
| `euMaster` | barra de teste "Ver como" | visível |
| `f.vM`, `f.bloq` | chave Global; aviso "Desativado pelo Master" | |
| `temToast`, `temModal`, `mEsq`...`mCons`, `valOk`, `valErr`, `erroForm`, `modalAcao` | toast, modal e partes | escondido |
| `ov`, `fFace`, `semCam`, `fVal`, `fOk`, `temCod`, `auto`, `fErro`, `erroExt` | tela cheia e partes | escondido |

---

## 2. Tabela por região

### 2.1 Cabeçalho global (L80 a L89)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Cabeçalho | Barra | `position:sticky; top:0; z-index:60; background:#0a76a8; color:#fff; height:64px; display:flex; align-items:center; gap:clamp(8px,1.6vw,18px); padding:0 clamp(10px,2vw,24px)` | fixa no topo ao rolar | |
| Cabeçalho | Menu | `bi-list`, `font-size:24px`, `cursor:pointer`, dica "Menu" | | sem `onClick` no desenho (o menu lateral vem do sistema) |
| Cabeçalho | Logo | `./bluejus-favicons/bluejus-logo-nav-branco.png`, `height:30px`, alt "BlueJus" | | link para `./03 - EXPEDIENTE - VS 30.10.26.dc.html` (arquivo não está na pasta 08-11) |
| Cabeçalho | Divisória | `width:1px; height:22px; background:rgba(255,255,255,.45)` | some em até 760px (`.bjpOculta`) | |
| Cabeçalho | Nome da tela | "Ponto Eletrônico", `font-size:17px; white-space:nowrap` | some em até 760px | |
| Cabeçalho | Espaçador | `flex:1` | | |
| Cabeçalho | Botão "Indicar o BlueJus" (injetado por `bj-compartilhar.js`) | `.bjsh` 34x34, `bi-share`, 16px, opacidade .9, hover `rgba(255,255,255,.16)`; dica "Indique o BlueJus e ganhe recompensa" | entra antes do bloco do escritório (procura `.bi-building`) | abre `BJContas.indicar()` |
| Cabeçalho | Escritório | `bi-building` + nome; `gap:8px; font-weight:bold; font-size:15px`; nome `max-width:230px` com reticências; dica = nome completo | some em até 980px (`.bjpOculta980`) | texto de `conta().esc` |
| Cabeçalho | Tela cheia | botão 34x34, sem borda, raio 3px, `bi-arrows-fullscreen` 17px, hover `rgba(255,255,255,.16)`; aria e dica "Tela cheia" | | `requestFullscreen` / `exitFullscreen` (L788) |
| Cabeçalho | Usuário | nome em CAIXA ALTA, bold, `font-size:clamp(12px,1.5vw,15px)`, `max-width:44vw` com reticências | | `conta().nome.toUpperCase()` |
| Cabeçalho | Papel | `font-size:10.5px; letter-spacing:.5px; opacity:.9` | `MASTER · USUÁRIO PRIMÁRIO` / `USUÁRIO PRIMÁRIO` / `COLABORADOR` | |
| Menu de telas (nav) | Barra | `background:#fff; display:flex; gap:4px; padding:0 clamp(8px,2vw,22px); box-shadow:0 1px 3px rgba(18,36,47,.08); overflow-x:auto` | rola na horizontal | |
| Menu de telas | Links | `padding:15px 12px 12px; font-size:13px; font-weight:bold; letter-spacing:.3px; color:#34505f; border-bottom:3px solid transparent`; hover `color:#0a6fa0` | nenhum link marcado como ativo (o Ponto não está neste menu) | EXPEDIENTE, DEMANDAS, MINHAS METAS, AGENDA, TRABALHO EXTERNO (`data-recurso="trabalho"`), ASSINATURA (`data-recurso="assinatura"`), VOTO (`data-recurso="voto"`) |
| Menu de telas | Link desligado no `bj-recursos` | não Master: `display:none`; Master: `opacity:.45`, contorno tracejado `#c0392b` | | |

### 2.2 Título e grade (L100 a L104, CSS L30 a L32, L60)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Página | `main` | `max-width:1560px; margin:0 auto; padding:20px clamp(10px,2vw,24px) 40px` | | |
| Página | `h1` | "Ponto Eletrônico", `margin:0 0 14px; font-size:clamp(26px,3.4vw,36px); font-weight:normal` | | |
| Página | Grade `.bjpGrid` | `display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start` | | |
| Página | Coluna esquerda `.bjpEsq` | `flex:1 1 340px; max-width:430px; flex-direction:column; gap:16px` | em até 980px: `max-width:none; flex-basis:100%` | contém Registrar Ponto |
| Página | Coluna direita `.bjpDir` | `flex:999 1 560px; flex-direction:column; gap:16px` | | contém Folha e Registros |

### 2.3 Registrar Ponto (L105 a L140)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Registrar | Barra de título | `padding:11px 16px; background:#0a6fa0; color:#fff; gap:9px`; `bi-fingerprint` 18px; título 16px bold | | |
| Registrar | Banco de horas (canto direito) | rótulo "Banco de horas" 10.5px opacidade .9; valor 15px bold tabular | `+529h24m` no exemplo; formato `fSal`: `0h` ou `±XhYYm` | soma do saldo anterior com o saldo do mês corrente (ver regra 3.9) |
| Registrar | Alerta do banco | `bi-exclamation-triangle-fill` 12px `#ffd666`, dica "Saldo acima do limite configurado" | só se saldo > limite | |
| Registrar | Corpo | `padding:16px; flex-direction:column; gap:14px` | | |
| Registrar | Situação | 12.5px `#4a6373` | "Aguardando entrada" / "Em jornada" / "Em repouso" / "Jornada encerrada" | L739 |
| Registrar | Cronômetro | `font-size:clamp(44px,7vw,54px); line-height:1.15; tabular-nums; letter-spacing:1px`, `HH:MM:SS` | atualiza a cada 1 s | tempo trabalhado hoje (desconta repouso; conta até agora se não saiu) |
| Registrar | Hora oficial | "Hora oficial <b>HH:MM:SS</b> · segunda-feira, 09/11/2026"; 12.5px `#5b7383`, hora `#243845` tabular | | no protótipo é o relógio do aparelho; espec: hora do servidor (NTP) |
| Registrar | Rótulo do tipo | "Tipo de registro" 12.5px bold `#34505f` + `*` `#a3232b`; `margin-bottom:6px` | | |
| Registrar | Seletor de tipo | `role=radiogroup` grade 4 colunas `gap:4px`; botão `min-height:54px; padding:6px 2px; border:1px solid; border-radius:3px; font-size:12.5px; bold`; 2ª linha 11.5px normal tabular | selecionado: borda e fundo `#0a6fa0`, texto `#fff`; feito: borda `#9fd0b2`, fundo `#e8f5ee`, texto `#145a32`, `disabled`, cursor default; livre: borda `#d3dde4`, fundo `#fff`, texto `#34505f` | 4 tipos: Entrada, Repouso, Retorno, Saída; 2ª linha mostra a hora batida ou `--:--:--`; clique escolhe o tipo (pode pular a ordem); padrão = próximo da sequência |
| Registrar | Caixa de localização | `padding:9px 11px; border:1px solid; border-left:4px solid; border-radius:3px; font-size:12.5px; line-height:1.4`; ícone 15px; botão 24x24 `bi-arrow-clockwise` dica "Atualizar localização" | lendo: `#9fc3d8`/`#e5f1f7`/`#0b4f75` `bi-crosshair` "Obtendo a sua localização..."; ok: `#9fd0b2`/`#e8f5ee`/`#145a32` `bi-geo-alt-fill` "Localização confirmada. Precisão de N m."; impreciso (> 50 m): `#e0c36a`/`#fff8e1`/`#7a5200` `bi-exclamation-triangle` "GPS impreciso (N m). O sistema ainda validará se você está dentro do raio de alguma sede."; demo (sem GPS): mesmas cores, "O navegador não liberou o GPS nesta pré-visualização. No sistema, o registro validará o raio de cada sede." | só com `ponto-geo`; botão relê o GPS (`lerGeo`) |
| Registrar | Caixa de aviso (intervalo / saída) | mesmo molde da caixa de localização | em repouso há menos de 1h: azul `bi-hourglass-split` "Intervalo em andamento. O mínimo de 1h se completa em HH:MM."; repouso com 1h ou mais: verde `bi-check-circle` "Intervalo mínimo de 1h cumprido."; em jornada antes da saída prevista: azul `bi-bell` "Saída prevista às HH:MM."; depois: âmbar `bi-bell` "Passou da saída prevista (HH:MM). Não esqueça de registrar a saída." | intervalo depende de `ponto-alertas`; saída prevista de `ponto-lembrete` (L771 a L772) |
| Registrar | Fila sem internet | borda `#9fc3d8`, esquerda 4px `#0a6fa0`, fundo `#e5f1f7`, texto `#0b4f75`, `bi-wifi-off` 15px | "Sem internet: N batida(s) aguardando envio." | some quando a fila é enviada |
| Registrar | Última pendência | `padding:8px 11px; border:1px solid #e0c36a; background:#fff8e1; color:#7a5200; font-size:12.5px`; botão "Regularizar" `height:26px; padding:0 9px; border:1px solid #d9a300; background:#fff; color:#7a5200; font-size:12px` | só antes da entrada do dia e se houver pendência | texto "Última pendência: dd/mm/aaaa, falta a saída." ou "..., falta o retorno do repouso."; botão abre modal de esquecimento daquela batida |
| Registrar | Botão principal | `height:54px; border:none; border-radius:3px; color:#fff; font-size:18px; gap:10px`; hover `filter:brightness(.92)`; clique `transform:scale(.99)` | normal fundo `#0a6fa0`; concluída fundo `#7a8e9b`, cursor default, `disabled` | texto "Bater Ponto: Entrada" (tipo da vez) / "Bater Ponto com facial: Entrada" (facial obrigatória) / "Jornada de hoje concluída"; ícone digital SVG 24px, ou `bi-person-bounding-box` 21px se facial obrigatória; dispara `iniciar()` |
| Registrar | Botão facial opcional | `height:40px; border:1px solid #0a6fa0; background:#fff; color:#0a6fa0; font-size:14px; gap:8px`; hover `#e5f1f7`; `bi-person-bounding-box` | só com facial ligada, modo opcional, sem recusa e jornada não concluída | "Registrar com reconhecimento facial" |
| Registrar | Rodapé do cartão | `border-top:1px solid #e6edf2; padding-top:12px; gap:8px; flex-wrap:wrap` | | |
| Registrar | Justificar falta | `flex:1 1 150px; height:36px; border:1px solid #d3dde4; color:#34505f; font-size:13.5px; gap:7px`; hover `#f3f6f8`; `bi-calendar-check` | | abre modal "Justificar falta" com a data de hoje |
| Registrar | Som | botão 36x36, borda `#d3dde4`, hover `#f3f6f8`, ícone SVG 18px (alto-falante / mudo) | dica e aria "Silenciar sons" / "Ativar sons" | só com `ponto-sons`; liga/desliga e grava `bj-ponto-mudo` |
| Registrar | Jornada semanal | "Jornada semanal <b>44h00m</b>"; 12px `#5b7383`, valor `#243845` | | valor fixo no desenho (não acompanha a escala escolhida) |

### 2.4 Folha de Ponto (L144 a L164)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Folha | Barra de título | `padding:9px 16px; background:#0a6fa0; color:#fff; gap:10px; flex-wrap:wrap`; `bi-table` 17px; título 16px bold | | |
| Folha | Competência | `select` aria "Competência", `height:30px; border:1px solid #d3dde4; border-radius:3px; background:#fff; color:#12242f; font-size:13px; padding:0 6px` | opções `MM/AAAA` de 10/2026 até o mês atual, mais recente primeiro | troca a competência da Folha e da aba Minhas Justificativas |
| Folha | Selo de aceite | `height:24px; padding:0 8px; border-radius:3px; color:#fff; font-size:12px; bold; gap:5px`; `bi-pen` | concordou: fundo `#1e7b45`, "Assinada em dd/mm/aaaa"; ressalva: fundo `#8a6200`, "Ressalva em dd/mm/aaaa" | aparece quando a competência tem aceite |
| Folha | Assinar folha | `height:30px; padding:0 11px; border:1px solid rgba(255,255,255,.65); background:transparent; color:#fff; font-size:13px; gap:7px`; hover `rgba(255,255,255,.16)`; `bi-pen` | só com `ponto-aceite`, competência anterior à atual e sem aceite | abre modal "Assinar folha" |
| Folha | Baixar PDF | mesmo estilo; `bi-file-earmark-pdf` | um por vez (trava enquanto gera) | gera e baixa o PDF (ver 3.15) |
| Folha | Colunas | Dia · Semana · Entrada · Repouso · Retorno · Saída · Trabalhado · Saldo dia · Saldo mês · Justificativa | | |
| Folha | Linhas exibidas | só dias com alguma batida ou feriado com horas | listra ímpar `#eef2f5`; hover `#3b8fc2` texto branco | |
| Folha | Dia | `<b>dd</b>` | | |
| Folha | Semana | "Segunda", "Terça"... | | |
| Folha | Célula de batida | hora `HH:MM:SS` como gravada | ajustada: marca "aj" `font-size:10.5px; bold; color:#0a6fa0; cursor:help`, dica "Ajustado por NOME em dd/mm/aaaa. Registro original preservado."; faltando em dia passado: botão `.bjpMais` 24x24 `bi-plus-circle` 15px `#5b7383`, hover `rgba(255,255,255,.25)`, dica "Enviar registro de esquecimento: Saída"; faltando hoje ou em feriado: "—" | "+" abre o modal de esquecimento com dia e tipo |
| Folha | Trabalhado | `XhYYm` | vazio quando 0 (classe `bjpVazia`, some no celular) | |
| Folha | Saldo dia | dia completo e em ordem: texto bold `±XhYYm` verde `#1e7b45` (>= 0) ou vermelho `#a3232b`; senão selo `height:22px; padding:0 7px; border:1px solid; border-radius:3px; font-size:12px` | "Fora de sequência" (`#f1c4c9`/`#fdecee`/`#7f1c23`, `bi-exclamation-octagon`); "Feriado" (`#9fc3d8`/`#e5f1f7`/`#0b4f75`, `bi-calendar-event`); "Em andamento" (mesmas cores azuis, `bi-clock`); "Registro incompleto" (`#e0c36a`/`#fff3cd`/`#7a5200`, `bi-exclamation-triangle`) | |
| Folha | Saldo mês | bold, acumulado do mês até o dia | vermelho se < 0, verde se > 0, `#34505f` se 0 | |
| Folha | Justificativa | selo `height:22px; padding:0 8px; border-radius:3px; color:#fff; font-size:12px; bold` | "Abonada" `#1e7b45` `bi-check-circle`; "Em análise" `#0a6fa0` `bi-hourglass-split`; "Recusada" `#a3232b` `bi-x-circle` | primeira justificativa daquela data |
| Folha | Vazio | "Nenhuma batida nesta competência." `colspan=10; padding:18px; color:#5b7383; text-align:center` | | |
| Folha | Celular (até 760px) | `thead` some; cada linha vira grade de 4 colunas `gap:8px 6px; padding:10px 12px`; rótulo de cada célula via `data-r::before` 10.5px bold `#5b7383`; Dia, Semana e Saldo dia ocupam 2 colunas; célula vazia some | | |

### 2.5 Registros e comprovantes: barra de abas (L167 a L169)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Abas | Barra | `role=tablist; display:flex; gap:2px; overflow-x:auto; background:#eef3f6; border-bottom:1px solid #d3dde4` | rola na horizontal | |
| Abas | Aba | `padding:12px 14px 9px; border:none; border-bottom:3px solid; font-size:13.5px; bold; gap:7px; white-space:nowrap` | ativa: borda `#0a6fa0`, fundo `#fff`, texto `#0a6fa0`; inativa: transparente, texto `#34505f` | clique troca a aba (não limpa nada) |
| Abas | Selo numérico | `min-width:18px; height:18px; padding:0 4px; border-radius:3px; color:#fff; font-size:11px` | cor por aba (tabela 1.3) | |

### 2.6 Aba Pendências (L171 a L177)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Pendências | Texto | "Dias dos últimos 30 em que faltou uma batida. Elas não impedem o registro do ponto: envie o registro de esquecimento para regularizar." `padding:10px 16px; font-size:12.5px; color:#5b7383` | | |
| Pendências | Lista | `max-height:360px; overflow-y:auto`; linha `padding:8px 16px; gap:6px 14px; flex-wrap:wrap; border-bottom:1px solid #e6edf2` | | |
| Pendências | Data | bold 13.5px tabular `dd/mm/aaaa` | | |
| Pendências | Falta | 13.5px `#243845` | "Falta a saída" / "Falta o retorno do repouso" | |
| Pendências | Escritório | 12.5px `#5b7383`, reticências | some em até 760px | |
| Pendências | Botão | "Enviar registro de esquecimento", `margin-left:auto; height:30px; padding:0 11px; border:1px solid #d9a300; background:#fff; color:#7a5200; font-size:12.5px; gap:6px`; hover `#fff3cd`; `bi-plus-circle` | | abre modal de esquecimento |
| Pendências | Vazio | "Nenhuma pendência de registro." `padding:22px; center; 13.5px; #5b7383` | | |

### 2.7 Aba Minhas Justificativas (L179 a L191)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Justificativas | Colunas | Data(s) · Tipo · Status · Atestado · Obs. da Análise · Analisado em · (ações) | | só as da competência escolhida na Folha |
| Justificativas | Data | selo `padding:3px 8px; border:1px solid #d3dde4; border-radius:3px; background:#f3f6f8; 12.5px bold` | | |
| Justificativas | Tipo | selo `padding:2px 8px; border-radius:3px; background:#bfeaf7; color:#0b3550; 11.5px bold`; abaixo subtítulo 12.5px `#5b7383` (`margin-top:3px`) e motivo 12px `#5b7383`; célula `white-space:normal; min-width:170px` | tipos: "Falta", "Esquecimento de Registro", "Trabalho Externo"; subtítulo ex. "Saída às 23:59" | |
| Justificativas | Status | selo `height:22px; padding:0 8px; color:#fff; 12px bold` | "Abonado" `#1e7b45` `bi-check-circle`; "Em análise" `#0a6fa0` `bi-hourglass-split`; "Recusado" `#a3232b` `bi-x-circle` | |
| Justificativas | Atestado | nome do arquivo ou "Sem anexo"; `#34505f` | | |
| Justificativas | Obs. da Análise | `#34505f` | pendente: "—"; analisada sem obs: "Não informado" | |
| Justificativas | Analisado em | `dd/mm/aaaa` ou "—" | | |
| Justificativas | Cadeado | `bi-lock` 16px `#5b6a9a`, dica "Análise concluída: não pode ser alterada." | analisada | |
| Justificativas | Abonar | `height:28px; padding:0 9px; border:1px solid #1e7b45; background:#fff; color:#145a32; 12.5px`; hover `#e8f5ee` | pendente e papel primário/Master | abre modal "Abonar justificativa" |
| Justificativas | Recusar | `border:1px solid #a3232b; color:#7f1c23`; hover `#fdecee` | idem | abre modal "Recusar justificativa" |
| Justificativas | Lixeira | 28x28, `margin-left:4px; border:1px solid #d3dde4; color:#a3232b`; hover `#fdecee`; `bi-trash3`; aria "Excluir justificativa"; dica "Excluir enquanto não analisada" | só pendente | apaga na hora, sem confirmação (L755 `tirar`) |
| Justificativas | Vazio | "Nenhuma justificativa enviada nesta competência." | | |

### 2.8 Aba Comprovantes (L193 a L199)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Comprovantes | Texto | "Cada comprovante é uma imagem única, com código, QR Code e padrões de segurança gerados a partir do próprio registro." 12.5px `#5b7383`, `flex:1 1 220px` | | |
| Comprovantes | Validar código | `height:32px; padding:0 12px; border:1px solid #0a6fa0; color:#0a6fa0; font-size:13px; gap:7px`; hover `#e5f1f7`; `bi-shield-check` | | abre modal "Validar comprovante" |
| Comprovantes | Lista | `max-height:360px; overflow-y:auto`; linha `padding:8px 16px; gap:6px 14px; font-size:13.5px` | até 120 guardados | |
| Comprovantes | Data e hora | bold tabular `dd/mm/aaaa HH:MM:SS` | | |
| Comprovantes | Tipo | "Entrada"... | | |
| Comprovantes | Método | 12.5px `#5b7383` | "Facial" / "Sistema" | |
| Comprovantes | Código | `<code>` 12.5px `#0b4f75`, `flex:1; min-width:150px`, formato `BJP-XXXX-XXXX-XXXX` | | |
| Comprovantes | Baixar | `height:30px; padding:0 11px; border:1px solid #0a6fa0; color:#0a6fa0; 12.5px; gap:6px`; hover `#e5f1f7`; `bi-download`; aria "Baixar comprovante CÓDIGO" | | baixa `comprovante-ponto-CÓDIGO.jpg`; toast "Comprovante baixado em imagem." |
| Comprovantes | Vazio | "Os comprovantes aparecem aqui depois do primeiro registro." | | |

### 2.9 Aba Banco de Horas (L201 a L208)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Banco | Indicadores | grade `repeat(auto-fit, minmax(170px,1fr)); gap:1px; background:#e6edf2`; célula `background:#fff; padding:12px 16px`; rótulo 11.5px bold `.3px` `#4a6373`; valor 22px bold tabular `margin-top:2px` | | 3 indicadores: "SALDO ATUAL" (verde ou vermelho se < 0); "LIMITE CONFIGURADO" (`120h`; vermelho se acima, senão `#34505f`); "PRAZO DE COMPENSAÇÃO" (`6 meses`, `#34505f`) |
| Banco | Faixa acima do limite | `padding:9px 16px; background:#fff3cd; color:#7a5200; font-size:13px; border-bottom:1px solid #e0c36a`; `bi-exclamation-triangle` | só se saldo > limite | "Saldo acima do limite configurado. Planeje a compensação para evitar passivo." |
| Banco | Tabela de lotes | Competência (bold) · Crédito · Compensado · Saldo (bold) · Vence em · Situação | | lotes por mês (ver regra 3.10) |
| Banco | Situação | selo `height:22px; padding:0 8px; color:#fff; 12px bold` | "No prazo" `#1e7b45`; "Vence em N dias" `#8a6200` (até 30 dias); "Vencido" `#a3232b`; "Compensado" `#5b7383`; "Compensação" `#5b7383` (mês com débito); "Sem data de origem" `#8a6200` (saldo anterior a 10/2026) | |
| Banco | Nota legal | 12px `#5b7383` `line-height:1.5; padding:10px 16px` | | "Horas negativas abatem primeiro os créditos mais antigos. O prazo padrão é de 6 meses, próprio do acordo individual (art. 59, § 5º, da CLT), e pode ser ajustado em Configurações conforme o acordo do escritório." |

### 2.10 Aba Equipe (L210 a L219), primário e Master

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Equipe | Nota de exemplo | `padding:8px 16px; background:#f7f9fb; font-size:12px; color:#5b7383` | | "Dados de exemplo: exceto a sua linha, os colaboradores abaixo são fictícios até a integração com o cadastro da equipe." (nota do protótipo) |
| Equipe | Indicadores | grade `minmax(130px,1fr)`; célula `padding:10px 14px`; rótulo 11.5px bold `#4a6373`; valor 24px bold | | "Em jornada" `#1e7b45`; "Em intervalo" `#0a6fa0`; "Sem registro" `#8a6200`; "Pendências" `#34505f` (soma); "A analisar" `#a3232b` |
| Equipe | Tabela | Colaborador · Situação · Entrada · Último registro · Banco · Pendências · Escala | | primeira linha é a própria pessoa (cargo "Você") |
| Equipe | Colaborador | nome bold + cargo 12px `#5b7383` | | |
| Equipe | Situação | selo `height:22px; padding:0 8px; color:#fff; 12px bold` | "Em jornada" `#1e7b45`; "Em intervalo" `#0a6fa0`; "Sem registro" `#8a6200`; "Encerrada" `#5b7383` | |
| Equipe | Último registro | "HH:MM · Tipo" ou "—" | | |
| Equipe | Banco | bold `±XhYYm` | | |
| Equipe | Escala | `select` aria "Escala de NOME", `height:30px; font-size:12.5px` | 4 escalas (tabela 6.4); padrão `44-6` | grava a escala da pessoa; auditoria "Escala alterada" |
| Equipe | Barra de análise | `padding:10px 16px; background:#eef3f6; border-top e border-bottom 1px #d3dde4`; título 14px bold "Justificativas a analisar (N)" | | |
| Equipe | Abonar selecionadas | `height:30px; padding:0 11px; border:1px solid #1e7b45; color:#145a32; 12.5px` (sem hover) | `disabled` sem seleção | abona todas as marcadas (ver 3.13) |
| Equipe | Lista a analisar | `label` clicável `padding:9px 16px; gap:10px 14px; 13.5px`; checkbox 16x16; nome bold; data tabular; tipo; subtítulo 12.5px `#5b7383` | | |
| Equipe | Vazio | "Nenhuma justificativa aguardando análise." `padding:18px` | | |

### 2.11 Aba Auditoria (L221 a L225), primário e Master

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Auditoria | Estado da cadeia | `padding:10px 16px; font-size:12.5px; gap:8px`; ícone 15px | íntegra: `#145a32` `bi-shield-check` "Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos."; alterada: `#a3232b` `bi-shield-exclamation` "Atenção: a cadeia de eventos foi alterada." | recalcula a cadeia ao desenhar (L783) |
| Auditoria | Tabela | `max-height:380px; overflow:auto`; Data e hora · Autor · Ação (bold) · Detalhe (quebra, `min-width:200px`) · Selo (`<code>` 12px `#0b4f75`, 8 hexadecimais) | até 300 eventos, mais novo primeiro | |
| Auditoria | Vazio | "Os eventos aparecem aqui a partir do próximo registro." | | |

### 2.12 Aba Configurações (L227 a L260), primário e Master

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Config | Barra de teste (Master) | `padding:10px 16px; background:#f7f9fb; gap:10px 18px; 12.5px #34505f`; selects `height:30px; 13px` | "Ver como": Master / Usuário primário / Colaborador; "Próxima batida": Sucesso / Erro: fora do raio / Erro: rosto não reconhecido / Erro: sem localização; nota "Controles de teste do protótipo." | só protótipo: simula papel e erro da próxima batida |
| Config | Texto de ajuda | `padding:10px 16px; 12.5px; #4a6373` | texto do Master ou do primário (1.2) | |
| Config | Linha de função | `padding:11px 16px; gap:10px 16px; border-bottom:1px solid #e6edf2`; ícone 18px `#0a6fa0` largura 20px; título 14px bold; descrição 12.5px `#5b7383` | 8 funções (tabela 4) | |
| Config | Chave Global (Master) | `role=switch` 42x22, raio 3px, `padding:2px`, bolinha 18x18 branca raio 2px; rótulo "Global" 12px | ligada `#1e7b45` (bolinha à direita); desligada `#9aabb7` (à esquerda); aria "TÍTULO: liberar para todos" | grava em `bj-recursos` (`ponto-ID`) e `bj-ponto-global`; auditoria "Configuração global"; toast "TÍTULO liberado para todos os escritórios." / "TÍTULO desativado para todos os escritórios." |
| Config | Chave Escritório | mesmo molde; rótulo "Escritório" | ligada `#1e7b45`; desligada `#9aabb7`; bloqueada pelo Master `#c4ced6`, `disabled`, cursor `not-allowed`; aria "TÍTULO: usar no escritório" | grava em `bj-ponto-escritorio`; auditoria "Configuração do escritório" |
| Config | Aviso de bloqueio | 12px `#a3232b`, `flex-basis:100%; padding-left:30px` | Global desligado | "Desativado pelo Master para todos os escritórios." |
| Config | Grade de campos | `repeat(auto-fit, minmax(200px,1fr)); gap:12px; padding:12px 16px; background:#f7f9fb` | | |
| Config | Uso da facial | `select` `height:34px; font-size:14px` | "Opcional: a pessoa escolhe" (padrão) / "Obrigatória em toda batida"; `disabled` com facial desligada | grava `faceModo` |
| Config | Guardar foto | checkbox 16x16 "Guardar foto de cada batida facial", 13px `#34505f` | padrão desligado; `disabled` sem facial | grava `foto` |
| Config | Guardar fotos por | `select` 6 meses / 12 meses / 24 meses | padrão 12; `disabled` sem facial | grava `fotoMeses` |
| Config | Cadastro facial | texto 12.5px + botão "Excluir meu cadastro" (`height:30px; border:1px solid #0a6fa0; color:#0a6fa0; bi-trash3`, sem hover) | "Sem cadastro facial." / "Cadastro facial ativo desde dd/mm/aaaa." / "Você não autorizou a facial." | apaga `bj-ponto-face` na hora, sem confirmação; auditoria "Cadastro facial excluído"; toast "Cadastro facial excluído." |
| Config | Limite do banco (horas) | `input type=number min=0 step=10`, `height:34px` | padrão 120 | grava `bancoLim` |
| Config | Prazo de compensação (meses) | `input type=number min=1 max=24` | padrão 6 | grava `bancoMeses` |
| Config | Sedes: título | "Sedes e raio de registro" 14px bold | | |
| Config | Trabalho externo | checkbox "Permitir trabalho externo, com motivo e análise" 12.5px | padrão ligado | grava `ext`; desligado esconde o botão "Registrar como trabalho externo" no erro |
| Config | Adicionar sede | `height:30px; border:1px solid #0a6fa0; color:#0a6fa0; bi-plus-lg` | | acrescenta "Nova sede", raio 150 |
| Config | Tabela de sedes | Sede (`min-width:150px`) · Latitude (`min-width:110px`) · Longitude · Raio (m) (`min-width:80px`, number) · ações; inputs `height:34px` | lat/lng com placeholder "informar"; arias "Nome da sede", "Latitude", "Longitude", "Raio em metros" | grava a cada tecla |
| Config | Usar minha posição | `height:30px; border:1px solid #0a6fa0; bi-crosshair` | sem GPS: toast vermelho "Localização indisponível neste aparelho." | preenche lat/lng com 6 casas |
| Config | Remover sede | 30x30, borda `#d3dde4`, `#a3232b`, `bi-trash3`, aria "Remover sede" | | remove sem confirmação |
| Config | Feriados: título e texto | "Feriados do escritório" 14px bold; "Os nacionais já estão incluídos. Cadastre aqui os estaduais e municipais, por sede." 12.5px `#5b7383` | | |
| Config | Formulário de feriado | grade `minmax(140px,1fr); gap:8px; align-items:end`; Data (`type=date`), Nome, Abrangência (`select`: "Todas as sedes" + cada sede), botão "Adicionar" (`height:34px; border:none; background:#0a6fa0; color:#fff; 13px`) | sem data ou nome: toast vermelho "Informe a data e o nome do feriado." | grava em `feriados` e limpa o formulário |
| Config | Lista de feriados | linha `padding:7px 0; gap:10px; 13px`; data bold; nome; abrangência `#5b7383`; botão `bi-x-lg` 26x26 `#a3232b` aria "Remover feriado" | | remove sem confirmação |

Observação: nenhuma mudança de sede, feriado, limite, prazo, modo facial ou foto vai para a auditoria (só as chaves de função, escala, facial e folha).

### 2.13 Toast (L266, `avisar` L468)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Toast | Caixa | `role=status; position:fixed; left:50%; bottom:22px; transform:translateX(-50%); z-index:2147483000; max-width:calc(100vw - 28px); color:#fff; padding:11px 16px; border-radius:3px; box-shadow:0 8px 24px rgba(18,36,47,.25); font-size:14px; animation:bjpUp .2s` | fundo verde `#1e7b45` (padrão), vermelho `#a3232b`, âmbar `#8a6200` (lembrete), azul `#0a6fa0` ("Gerando...") | some em 3.400 ms; um por vez |

Textos de toast: lista completa em 3.20.

### 2.14 Modais (L269 a L320)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Modal | Fundo | `position:fixed; inset:0; z-index:2147483100; background:rgba(11,53,80,.55); padding:14px; overflow-y:auto` | | clique fora não fecha; Esc fecha |
| Modal | Caixa | `role=dialog; aria-modal; max-width:480px; background:#fff; border-radius:3px; box-shadow:0 18px 50px rgba(0,0,0,.35); animation:bjpUp .2s` | | |
| Modal | Cabeçalho | `padding:12px 16px; background:#0a6fa0; color:#fff; gap:9px`; ícone 17px; título 16px bold; X 28x28 `bi-x-lg` 17px, hover `rgba(255,255,255,.16)`, aria "Fechar" | | |
| Modal | Corpo | `padding:14px 16px; gap:11px` | | |
| Modal | Erro do formulário | `padding:0 16px 10px; font-size:13px; color:#a3232b` | aparece ao validar; some ao digitar | |
| Modal | Rodapé | `padding:10px 16px; background:#f7f9fb; border-top:1px solid #e6edf2; gap:8px; justify-content:flex-end` | | |
| Modal | Secundário | `height:36px; padding:0 14px; border:1px solid #d3dde4; color:#34505f; 14px` | "Cancelar" / "Fechar" (val) / "Aguardar" (alerta) | fecha |
| Modal | Principal | `height:36px; padding:0 16px; border:none; background:#0a6fa0; color:#fff; 14px`; hover `#0b5f86` | rótulo por modal (1.4) | `modalOk` (L804) |
| Modal | "Não autorizo" (cons) | `height:36px; padding:0 14px; border:1px solid #a3232b; color:#7f1c23; margin-right:auto` | | grava recusa e segue sem facial |
| Modal esq | Info | "dd/mm/aaaa · Saída" 13.5px `#34505f` | | |
| Modal esq | Horário | `input type=time step=1` (com segundos) `height:36px` | | |
| Modal esq | Motivo | `textarea rows=3; padding:8px 10px; resize:vertical` | sem horário ou motivo: "Informe o horário e o motivo." | cria justificativa "Esquecimento de Registro" pendente; auditoria "Justificativa enviada"; toast "Enviado para aprovação do gestor." |
| Modal just | Data da falta | `type=date`, padrão hoje | | |
| Modal just | Motivo | textarea | sem data ou motivo: "Informe a data e o motivo." | cria justificativa "Falta" pendente |
| Modal just | Atestado ou documento (opcional) | `input type=file accept="image/*,application/pdf"`, 13px | | guarda só o nome do arquivo no protótipo |
| Modal alerta | Texto | 14px `line-height:1.5; #243845` + nota 12.5px `#5b7383` "O alerta não impede o registro: a folha deve refletir o horário real." | 3 textos possíveis (3.6) | "Registrar assim mesmo" refaz a batida sem alerta |
| Modal ext | Texto | "O registro será feito fora do raio e seguirá para análise do gestor." | | |
| Modal ext | Local e motivo | textarea | vazio: "Informe o local e o motivo." | registra a batida como trabalho externo (sem facial) e cria justificativa "Trabalho Externo" pendente |
| Modal ass | Texto | "Competência <b>MM/AAAA</b>. Confira os registros antes de confirmar." | | |
| Modal ass | Sua posição | `select` `height:34px` | "Concordo: os registros representam fielmente minha jornada" (padrão) / "Discordo de parte dos registros (ressalva)" | |
| Modal ass | Ressalva | textarea (sempre visível) | ressalva vazia: "Descreva a ressalva." | grava aceite; auditoria "Folha assinada" ou "Folha assinada com ressalva"; toast "Folha assinada." |
| Modal ana | Info | "dd/mm/aaaa · Tipo · subtítulo" | | |
| Modal ana | Observação da análise | textarea | recusa sem observação: "Informe o motivo da recusa." | abona ou recusa (3.12) |
| Modal val | Código do comprovante | `input` `height:38px; padding:0 10px; font:bold 15px monospace; letter-spacing:1px; text-transform:uppercase`, placeholder "BJP-XXXX-XXXX-XXXX" | | procura em comprovantes e folhas emitidas |
| Modal val | Autêntico | `padding:10px 12px; border:1px solid #9fd0b2; border-left:4px solid #1e7b45; background:#e8f5ee; color:#145a32; 13.5px; line-height:1.45` | "<b>Comprovante autêntico.</b>" + "Entrada de NOME em dd/mm/aaaa HH:MM:SS, NSR 000000001." (folha: "Folha de Ponto MM/AAAA de NOME em ...") | |
| Modal val | Não localizado | `border:1px solid #f1c4c9; border-left:4px solid #a3232b; background:#fdecee; color:#7f1c23` | "<b>Código não localizado.</b> Confira a digitação. Comprovante sem registro correspondente não é válido." | |
| Modal cons | Textos LGPD | 13.5px `line-height:1.5; #243845; gap:10px` | | dois parágrafos (seção 7) |
| Modal cons | Autorização | `label` `padding:9px 11px; background:#f7f9fb; border:1px solid #d3dde4; border-radius:3px`; checkbox 16x16 `margin-top:2px` | sem marcar: "É preciso marcar a autorização para continuar." | "Li e autorizo o uso do reconhecimento facial para o registro do meu ponto."; grava autorização; auditoria "Facial autorizada" |

### 2.15 Tela cheia de validação (L323 a L391)

| Região | Item | Valor do desenho | Estado(s) | Comportamento |
|---|---|---|---|---|
| Tela cheia | Fundo | `role=dialog; aria-label="Registro de ponto"; position:fixed; inset:0; z-index:2147483646; overflow-y:auto; background:#0b3550; color:#fff` | | Esc fecha nas fases face, ok e erro (não na validação) |
| Facial | Título | `font-size:clamp(20px,3.6vw,28px)` | "Posicione o rosto na área de marcação" (0 s) → "Pisque os olhos" (1,2 s) → "Mantenha a posição" (2,4 s) | |
| Facial | Moldura | `width:min(68vmin,320px); aspect-ratio:3/4; border-radius:50%; border:4px solid; background:#072538; transition:border-color .25s` | borda `rgba(255,255,255,.55)`, vira `#59d98e` em 3,4 s | |
| Facial | Vídeo | `object-fit:cover; transform:scaleX(-1)` (espelhado) | sem câmera: `bi-person-fill` `#1c5475` `font-size:min(52vmin,240px)` | câmera frontal, largura 640, espera no máximo 2,5 s |
| Facial | Linha de leitura | `left:6%; right:6%; height:2px; background:#59d98e; box-shadow:0 0 14px 3px rgba(89,217,142,.65); animation:bjpScan 2.2s ease-in-out infinite` | some com movimento reduzido | |
| Facial | Subtítulo | 14px `#c7dce9`, `max-width:420px` | "Câmera indisponível nesta pré-visualização: a leitura está sendo simulada." / "A imagem é usada somente para confirmar a sua identidade." | |
| Facial | Cancelar | `height:38px; padding:0 18px; border:1px solid rgba(255,255,255,.55); background:transparent; 14px`; hover `rgba(255,255,255,.12)` | | fecha e para a câmera |
| Validando | Anel | `.bjpRing` `--r:min(31vmin,168px)`, lado `2.3 × r`; 24 barras a cada 15°; barra `.bjpBar` branca, opacidade .18, `scaleY(.6)`; acende `bjpBarOn .28s` em sequência (atraso `i/24 × 2800 × .94` ms) | | |
| Validando | Hora | centro do anel, `font-size:calc(var(--r) * .29)`, tabular | | hora da batida |
| Validando | Título | "Validando Entrada" `font-size:clamp(22px,4vw,32px)`; data 15px `#c7dce9`; `aria-live=assertive` | | |
| Validando | Etapas | `.bjpEtapas` `gap:10px 26px`; cada uma 14px, `bi-check-circle-fill` `#59d98e`, opacidade .35 → 1 (`bjpEtapa`) em sequência | "Horário oficial confirmado"; com geo: "Localização validada" ou "Localização simulada"; com facial: "Identidade confirmada"; "Registro selado" | |
| Sucesso | Cortina | fundo `#1e7b45` com `bjpWipe .6s` (círculo abrindo a partir de 50% 42%) | | |
| Sucesso | Anel mini | `--r:min(19vmin,96px)`; barras sobem com `bjpBarOk .5s cubic-bezier(.2,.8,.3,1.25)`, atraso `100 + i×14` ms; check SVG desenhado `bjpDraw .4s` atraso .25s, traço branco 7 | | |
| Sucesso | Textos | "Ponto registrado" `clamp(28px,5vw,42px)` bold; hora `clamp(48px,9vw,76px)`; "Entrada · data" `clamp(15px,2.4vw,18px)`; método 13.5px opacidade .95; código `padding:7px 12px; border:1px solid rgba(255,255,255,.55); font:bold 15px monospace; letter-spacing:1px` | método: "Identidade confirmada por facial" ou "Registrado no sistema", mais " · localização validada" ou " · localização simulada"; código só com comprovante ligado | entrada `bjpIn .4s` atraso .2s |
| Sucesso | Baixar comprovante | `height:44px; padding:0 18px; border:none; background:#fff; color:#145a32; 15px bold`; hover `#e8f5ee`; `bi-download` | só com comprovante | baixa a imagem e cancela o fechamento automático |
| Sucesso | Concluir | `height:44px; border:1px solid rgba(255,255,255,.7); transparent; 15px`; hover `rgba(255,255,255,.14)` | | fecha |
| Sucesso | Barra de tempo | rodapé `height:4px; background:#fff; animation:bjpBarra 10s linear` | | fecha sozinho em 10 s |
| Erro | Cortina | fundo `#a3232b`, `bjpWipe .45s`; conteúdo treme `bjpTreme .45s` atraso .15s | | |
| Erro | X | SVG 110x110, dois traços desenhados (.22s, atrasos .15s e .34s) | | |
| Erro | Textos | "Ponto não registrado" `clamp(28px,5vw,42px)` bold; título do erro `clamp(16px,2.6vw,20px)`; detalhe 14.5px `max-width:520px; line-height:1.5` | 3 erros (3.5) | |
| Erro | Tentar novamente | `height:44px; background:#fff; color:#7f1c23; 15px bold`; hover `#fdecee`; `bi-arrow-repeat` | | refaz a mesma batida (mesmo tipo e método) |
| Erro | Registrar como trabalho externo | `height:44px; border:1px solid #fff; transparent; 15px bold`; hover `rgba(255,255,255,.14)`; `bi-briefcase` | só no erro "fora do raio" e com trabalho externo permitido | abre modal "Trabalho externo" |
| Erro | Fechar | `border:1px solid rgba(255,255,255,.7)`; hover `.14` | | |

### 2.16 Responsivo (`@media`, L60 a L75)

| Ponto | Regra | Efeito |
|---|---|---|
| até 980px | `.bjpEsq { max-width:none; flex-basis:100% }`, `.bjpOculta980 { display:none }` | Registrar Ponto ocupa a largura toda (coluna direita desce); nome do escritório some do cabeçalho |
| até 760px | `.bjpOculta { display:none }` | some divisória e nome da tela no cabeçalho, e o nome do escritório nas linhas de Pendências |
| até 760px | `.bjpT.resp` (só a Folha) | tabela vira cartões em grade de 4 colunas com rótulo por célula (2.4) |
| `prefers-reduced-motion: reduce` | `.bjpBarOn, .bjpBarOk, .bjpMov { animation-duration:.01ms; animation-delay:0s }`, `.bjpScanL { display:none }` | animações viram instantâneas; linha da facial some |
| tamanhos fluidos | `clamp()` em padding do cabeçalho, nav, main, h1, cronômetro e textos da tela cheia | |

### 2.17 Animações (`@keyframes`, L50 a L59)

| Nome | O que faz | Uso |
|---|---|---|
| `bjpBarOn` | barra .18/scaleY(.6) → 1/1.22 (60%) → 1/1 | anel de validação, .28s ease-out |
| `bjpBarOk` | barra sobe de `translateY(r×.42) scaleY(.3)` | anel do sucesso, .5s |
| `bjpDraw` | `stroke-dashoffset` → 0 | check e X |
| `bjpWipe` | `clip-path: circle(0 → 150vmax at 50% 42%)` | cortina verde/vermelha |
| `bjpIn` | opacidade 0 e `translateY(12px)` → normal | textos e botões do sucesso |
| `bjpBarra` | `scaleX(1 → 0)` | contagem de 10 s |
| `bjpTreme` | `translateX` 0, -9, 9, -9, 9, 0 | erro |
| `bjpEtapa` | opacidade → 1 | etapas |
| `bjpScan` | `top` 8% ↔ 88% | linha da facial |
| `bjpUp` | opacidade 0 e `translateY(6px)` → normal | toast e modal, .2s |

### 2.18 Sons, vibração, notificação, teclado

| Item | Valor | Onde |
|---|---|---|
| Som de sucesso ("plim") | senoides: 1568 Hz (0 s, .55 s, vol .26) + 3136 Hz (0 s, .25 s, .06) + 2093 Hz (.1 s, .85 s, .3) + 4186 Hz (.1 s, .35 s, .07); ataque .012 s, queda exponencial | `somOk` L484 |
| Som de erro | onda quadrada: 233 Hz (0 s, .2 s, .3) + 185 Hz (.24 s, .36 s, .3) | `somErro` L485 |
| Regra do som | só com `ponto-sons` ligado e sem mudo; gerado no navegador (`AudioContext`), sem arquivo | L482 a L485 |
| Vibração | sucesso 40 ms; erro [80, 60, 80] | `vibra` L486 |
| Notificação do navegador | título "BlueJus Ponto", corpo "Não esqueça de registrar a saída."; pede permissão na primeira batida se o lembrete estiver ligado | `checarLembrete` L454, `iniciar` L497 |
| Teclado | Esc fecha a tela cheia (face, ok, erro) ou o modal | `componentDidMount` L440 |
| Sem foco customizado | não há regra `:focus` no CSS | |

### 2.19 O que o protótipo grava no navegador (`localStorage`, L397)

| Chave | Conteúdo | No sistema real (espec) |
|---|---|---|
| `bj-ponto-dia` | `{ 'AAAA-MM-DD': { entrada, repouso, retorno, saida: 'HH:MM:SS' } }` | `ponto_batida` (só inserção) |
| `bj-ponto-comprovantes` | lista de registros com `nsr, tipo, iso, nome, esc, cnpj, lat, lng, acc, geo, face, hash, cod, off, ext` (até 120) | `ponto_comprovante` |
| `bj-ponto-justificativas` | `{ data, chave, tipo, sub, campo, hora, mot, st, anexo, obsA, em }` | `ponto_justificativa` |
| `bj-ponto-global` | chaves globais por função | `recurso_estado` (`ponto-*`) |
| `bj-ponto-escritorio` | `{ faceModo, raio, foto, fotoMeses, bancoLim, bancoMeses, ext, sedes[], feriados[], <função>: bool }` | `ponto_config_escritorio`, `ponto_sede`, `ponto_feriado` |
| `bj-ponto-face` | `{ em }` (autorizou) ou `{ recusa }` | autorização facial registrada |
| `bj-ponto-mudo` | bool | preferência do usuário |
| `bj-ponto-folhas` | folhas emitidas `{ folha, mes, cod, hash, nome, iso, ace }` (até 60) | registro da folha emitida (para validar `BJF-`) |
| `bj-ponto-ajustes` | `{ data: { campo: { hora, por, em, chave } } }` | `ponto_ajuste` |
| `bj-ponto-log` | `{ em, por, acao, det, h }` (até 300) | `ponto_auditoria` |
| `bj-ponto-aceites` | `{ 'AAAA-MM': { st, em, nome, obs } }` | `ponto_folha_aceite` |
| `bj-ponto-escalas` | `{ nome: idEscala }` | `ponto_escala` + vínculo por pessoa |
| `bj-ponto-eq-just` | justificativas da equipe (exemplo) | `ponto_justificativa` |
| `bj-ponto-fila` | códigos de batidas feitas sem internet | fila local cifrada (espec) |
| `bj-recursos` | chaves `ponto-*` (via `BJRecursos.set`) | `recurso_estado` |

### 2.20 Camadas globais carregadas pela tela (não são do Ponto, mas aparecem nela)

| Biblioteca | Efeito na tela |
|---|---|
| `bj-recursos.js` | guarda da tela `ponto` e das funções `ponto-*`; esconde/esmaece links com `data-recurso` |
| `bj-tema.js` | tema comemorativo troca o azul `#0a6fa0`/`#0a76a8` (em novembro: "Novembro Azul", `#1f6fbf`), faixa de 4px no topo, enfeites no cabeçalho e selo no canto inferior esquerdo |
| `bj-plano.js` | aviso de espaço do plano para o primário a partir de 90% |
| `bj-idioma.js` | tradução da interface (inclusive `data-tip`, `aria-label`, `placeholder`) |
| `bj-lembretes.js` | pop-ups de lembrete da Agenda |
| `bj-dica.js` | padrão de dica do sistema (fundo `#e5f1f7`, texto `#0b4f73`, borda `#b9d6e8`, 12px 600, raio 4px); o desenho marca as dicas com `data-tip` |
| `bj-compartilhar.js` | botão "Indicar o BlueJus" no cabeçalho |
| `bj-monitor.js` | mede erros e tempo de carga |
| `bluejus-central.js` | Central de Comunicação (sino, chat) |
| `bj-contas.js` | usuário, conta, dados do escritório, papel primário |

---

## 3. Funções e regras de negócio do JS (com local)

3.1 **Sequência das batidas** (`TIPOS` L400, `proximo` L472, `iniciar` L494). Quatro tipos fixos: Entrada,
Repouso, Retorno, Saída. Padrão é o primeiro ainda não batido no dia; a pessoa pode escolher outro tipo livre.
Tipo já batido fica travado. Com os 4 feitos, o botão vira "Jornada de hoje concluída" e trava.

3.2 **Cronômetro e situação** (`renderVals` L738 a L739). Trabalhado = (saída ou agora) menos entrada,
menos (retorno ou saída ou agora) menos repouso. Situação: sem entrada "Aguardando entrada"; com saída
"Jornada encerrada"; repouso sem retorno "Em repouso"; senão "Em jornada". Atualiza a cada 1 s (`componentDidMount` L437).

3.3 **Geolocalização** (`lerGeo` L474). `getCurrentPosition` com `enableHighAccuracy:true; timeout:7000; maximumAge:30000`.
Precisão até 50 m = ok; acima = "impreciso"; falha = "demo" (protótipo). Lida ao abrir a tela e no botão de atualizar.

3.4 **Raio da sede** (`maisProx` L451, `validar` L528). Distância por haversine (`12742000 × asin(√a)`, raio da Terra 6.371 km)
até cada sede com lat/lng preenchidos; pega a mais próxima. Fora do raio quando distância > raio da sede
(padrão 150 m). Sedes sem coordenada são ignoradas (sem nenhuma coordenada, nunca bloqueia). Batida de
trabalho externo não passa pela checagem. Espec: validar no servidor contra `ponto_sede`, usando a precisão do GPS.

3.5 **Erros de batida** (`validar` L528). Fora do raio: "Você está fora do raio permitido" + "A sede mais próxima é NOME,
a N m. O raio permitido é de N m. Aproxime-se ou informe o trabalho externo." (sem sede com coordenada:
"O registro exige estar dentro do raio de uma sede do escritório. Aproxime-se ou informe o trabalho externo.").
Sem GPS (simulado): "Não foi possível obter a sua localização" + "Ative o GPS do aparelho, permita a localização
no navegador e tente de novo.". Facial falhou (simulado): "Rosto não reconhecido" + "Procure um lugar bem iluminado,
retire boné ou óculos escuros e tente de novo.". Depois de um erro simulado, a simulação volta para "Sucesso".

3.6 **Alertas antes da batida** (`alertaPre` L455 a L464; usados em `iniciar` L498). Só com `ponto-alertas`. Nunca bloqueiam
(modal "Registrar assim mesmo"). Três regras:
- Retorno com menos de 1h de repouso: "Faltam X para completar o intervalo mínimo de 1h. Se registrar agora, a folha marcará o intervalo intrajornada como não conforme."
- Entrada com menos de 11h desde a saída de ontem (`86400 − saída + agora < 39600`): "Faltam X para completar 11h de descanso desde a saída de ontem. Se registrar agora, a folha marcará a interjornada como não conforme."
- Entrada depois de 6 ou mais dias seguidos com registro (olha 7 dias para trás): "Este será o Nº dia seguido com registro, sem o descanso semanal."
- Formato do "X": minutos arredondados para cima; 60 ou mais vira "XhYY", abaixo "N minuto(s)".

3.7 **Aviso de intervalo em andamento** (`renderVals` L771). Em repouso: menos de 1h mostra quanto falta (HH:MM); com 1h mostra "cumprido".

3.8 **Saída prevista e lembrete** (`saidaPrev` L453, `checarLembrete` L454, aviso L772). Saída prevista = entrada +
carga do dia pela escala da pessoa + intervalo (retorno menos repouso, ou 1h se ainda não houve). Lembrete
dispara uma vez por dia quando passa da prevista sem saída: som de sucesso, toast âmbar "Passou do horário
previsto. Não esqueça de registrar a saída." e notificação do navegador. Só com `ponto-lembrete`.

3.9 **Motor da folha** (`linhasMes` L607 a L630). Por dia do mês:
- Junta batidas de exemplo (`SEED`), folha importada de outubro (`OUT`), batidas gravadas (`dia`) e ajustes (`AJ_SEED` + `aj`), com o ajuste por cima.
- Feriado = nacional fixo (`FERIADOS`) ou do escritório cuja abrangência é "Todas" ou igual à lotação da pessoa (em caixa alta).
- Carga do dia = escala da pessoa por dia da semana (folha importada: 7h20, domingo 0).
- Estados: importado usa os totais da planilha; dia futuro "fut"; dia completo: trabalhado = saída − entrada − (retorno − repouso), extra = máx(0, trabalhado − carga), saldo += trabalhado − carga; hoje "hoje"; parcial "parc" (não desconta); feriado sem batida "feriado"; sem batida "vazio" (desconta a carga do dia, mesmo com justificativa abonada).
- Fora de sequência: alguma batida menor que a anterior; vira selo vermelho e obs "Horário fora de sequência: revisar".
- Observações do dia: "Saída ajustada em dd/mm/aaaa", "Feriado: NOME", "Registro incompleto", e cada justificativa ("Falta", "Falta: recusada", "Falta: em análise").
- Conformidade: intrajornada não conforme se repouso < 1h em dia completo; interjornada não conforme se entre a saída de um dia e a entrada do seguinte há menos de 11h; descanso semanal conforme se a maior sequência de dias com batida for até 6.
- Totais: trabalhado, extras, saldo, feriados trabalhados, fins de semana com batida, maior sequência, dias incompletos, dias a revisar.
- Comentário do desenho (L606): "Regra extraída da folha atual: 44h em 6 dias = 7h20 de segunda a sábado; dia sem batida desconta; dia com batida incompleta não desconta."

3.10 **Banco de horas e lotes** (`renderVals` L746, L774 a L776). Saldo atual = saldo anterior da competência (`ANT`) + saldo do mês corrente.
Limite padrão 120h, prazo padrão 6 meses. Lotes: um "Anterior a 10/2026" (sem vencimento) + um por mês desde 10/2026,
crédito = saldo positivo do mês, débito = saldo negativo. Débitos somados abatem os créditos mais antigos primeiro (FIFO).
Vencimento = último dia do mês "competência + prazo". Situação: Compensação (só débito), Compensado (zerou),
Sem data de origem, Vencido, "Vence em N dias" (até 30), No prazo. Acima do limite: alerta no título e faixa na aba.

3.11 **Justificativas** (`modalOk` L805 a L806, `addJ` L768, `justs` L596). Três tipos: "Falta" (data, motivo, anexo
opcional, chave `f+data`), "Esquecimento de Registro" (dia, tipo da batida, horário com segundos, motivo; chave
`data+tipo`), "Trabalho Externo" (criada sozinha pela batida externa). Toda nova nasce "pendente", vai para a
auditoria e mostra "Enviado para aprovação do gestor.". Pendente pode ser excluída pela pessoa; analisada fica travada.

3.12 **Análise (abonar/recusar)** (`analisar` L597 a L602). Grava situação, observação e data. Recusa exige motivo.
Abonar um esquecimento com tipo e horário cria um **ajuste** da batida (`bj-ponto-ajustes`: hora, por, em, chave da
justificativa); a folha passa a mostrar a batida com a marca "aj" e o original é preservado. Auditoria
"Justificativa abonada/recusada" com data, tipo, subtítulo e observação.

3.13 **Abono em lote** (`loteAbonar` L818). Na aba Equipe, marca várias e abona: as próprias passam por `analisar`;
as da equipe mudam para abonado. Auditoria "Abono em lote" "N justificativa(s) da equipe"; toast "N justificativa(s) abonada(s).". Não há recusa em lote.

3.14 **Comprovante com código e QR** (`validar` L520 a L527, `desenhar` L550 a L590, `baixar` L594). NSR = nº de
comprovantes + 1 com 9 dígitos. Hash SHA-256 de `{nsr, tipo, iso, nome, esc, cnpj, lat, lng, acc, geo, face}` + nonce
aleatório (`hash` L518, com plano B FNV se não houver `crypto.subtle`). Código `BJP-XXXX-XXXX-XXXX`: 12 caracteres do
alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (sem I, O, 0, 1), cada um = byte do hash mod 32. Imagem JPEG (qualidade .93)
de 1000×1480 × 2,48 (2480×3670 px) com padrões de segurança derivados do hash (fundo iridescente, linhas onduladas,
rosáceas, marca d'água diagonal "BLUEJUS · CÓDIGO · NOME", moldura dupla, bordas onduladas em 3 cores, microtexto,
faixa holográfica, selo holográfico com texto circular). Campos: TRABALHADOR, EMPREGADOR (+ CNPJ), LOCAL DO REGISTRO
(lat/lng 5 casas e precisão, ou "Geolocalização não exigida"), MÉTODO · NSR. QR para
`https://www.bluejus.com.br/ponto/validar/CÓDIGO`. Espec: gerar e assinar (HMAC) no servidor.

3.15 **Folha em PDF** (`baixarPdf` L717 a L730, `desenharFolha` L635 a L705, `pdfImagem` L706 a L716). Código
`BJF-XXXX-XXXX-XXXX` do hash de `{mes, aceite, nome, escritório, linhas, iso}` + nonce. Folha desenhada em imagem A4
(2480×3507 px, JPEG .92) e embutida num PDF 1.4 de uma página (texto não editável). Nome do arquivo
`folha_ponto_NOMESEMACENTO-MM-AAAA.pdf`. Guarda a folha emitida (para validar o código), auditoria "Folha emitida",
toast "Folha baixada em PDF. Código BJF-...". Conteúdo: cabeçalho, período, empregador (razão social, CNPJ, endereço),
"REGIME DE JORNADA", "DADOS DO EMPREGADO", grade diária (todos os dias), "RESUMO DO MÊS", "CONTROLE DE INTERVALOS",
"OBSERVAÇÕES GERAIS", declaração, linhas de assinatura, carimbo do aceite, QR, código, SHA-256, selo, "Base Legal".

3.16 **Validação de código** (`modalOk` val L811). Procura o código (maiúsculo, sem espaços) entre comprovantes e folhas
emitidas. Achou: "Comprovante autêntico." com tipo ou folha, nome, data e NSR. Espec: rota pública `/ponto/validar/{codigo}`.

3.17 **Aceite mensal da folha** (`podeAss` L816, `modalOk` ass L809). Só competência passada e sem aceite. Concordo ou
ressalva (ressalva exige texto). Grava `{st, em, nome, obs}`; selo na barra; carimbo e ressalva no PDF ("Aceite eletrônico em ..." / "Aceite com ressalva em ..."). Não há como desfazer.

3.18 **Reconhecimento facial** (`iniciar` L496 a L499, `abrirFace` L504 a L516, consentimento L812, recusa L823, exclusão L822).
Primeira vez: modal de consentimento LGPD; "Não autorizo" grava a recusa e a batida segue sem facial (sem penalidade);
quem recusou não vê mais o botão facial. Câmera frontal espelhada, roteiro de 3,4 s (posicione, pisque, mantenha),
borda verde, foto 240px JPEG .7 se "Guardar foto" estiver ligado (o protótipo captura e não grava), para a câmera e valida.
Modo "Obrigatória em toda batida" troca o botão principal para facial. Excluir cadastro apaga a autorização.
Espec: fornecedor com prova de vida; guardar template, não foto, salvo opção do escritório, com retenção.

3.19 **Sem internet** (`concluir` L546, `componentDidMount` L438). Batida feita offline marca `off` e entra na fila; o
aviso mostra quantas aguardam. No evento `online`, a fila é enviada, auditoria "Sincronização" "N batida(s) enviada(s)
ao voltar a internet" e toast "Batidas sem internet enviadas.". Comprovante e folha mostram "Sem internet". Espec:
fila cifrada, hora do aparelho e marca de sincronização, servidor registra as duas.

3.20 **Toasts e avisos** (`avisar` L468). Textos: "Batidas sem internet enviadas."; "Passou do horário previsto. Não
esqueça de registrar a saída."; "TIPO não registrada: ERRO."; "TIPO registrada às HH:MM:SS. Comprovante na aba
Comprovantes." (ou só "TIPO registrada às HH:MM:SS."); "Comprovante baixado em imagem."; "Justificativa abonada.";
"Justificativa recusada."; "Gerando a folha em PDF..."; "Folha baixada em PDF. Código BJF-...."; "Não foi possível gerar
o PDF."; "TÍTULO liberado para todos os escritórios."; "TÍTULO desativado para todos os escritórios."; "Enviado para
aprovação do gestor."; "Folha assinada."; "N justificativa(s) abonada(s)."; "Localização indisponível neste aparelho.";
"Informe a data e o nome do feriado."; "Cadastro facial excluído.".

3.21 **Auditoria encadeada** (`reg` L452, `hx` L413, checagem L783). Cada evento `{em, por, acao, det}` recebe selo
FNV-1a 32 bits de (selo anterior + em + por + acao + det), o primeiro com `00000000`. A aba recalcula a cadeia e acusa
alteração. Ações gravadas: Batida registrada, Sincronização, Justificativa enviada, Justificativa abonada,
Justificativa recusada, Abono em lote, Folha emitida, Folha assinada, Folha assinada com ressalva, Configuração global,
Configuração do escritório, Escala alterada, Facial autorizada, Facial não autorizada, Cadastro facial excluído.
Espec: `ponto_auditoria` com hash encadeado no servidor.

3.22 **Pendências dos últimos 30 dias** (`PEND` L407, filtro L753). No protótipo é uma lista fixa de (dia, batida
faltando: só "saída" ou "retorno"); some quando há justificativa não recusada para ela ou a batida existe. A
mais recente aparece no Registrar antes da entrada do dia.

3.23 **Escalas** (`ESC` L409, `escala` L449, `trocaEsc` L778). Escala por pessoa, padrão `44-6`; muda a carga diária,
a saída prevista, a folha e o cabeçalho do PDF.

3.24 **Feriados** (`FERIADOS` L404, `addFer` L821). Nacionais fixos no código; do escritório por data, nome e
abrangência (todas as sedes ou uma). Feriado sem batida não desconta; feriado trabalhado conta no resumo.

3.25 **Trabalho externo** (`irExterno` L824, `modalOk` ext L808, `concluir` L545). Só depois de erro "fora do raio" e com
a opção do escritório ligada. Exige "Local e motivo", registra a batida fora do raio e cria justificativa
"Trabalho Externo" pendente ("TIPO às HH:MM").

3.26 **Equipe** (L777 a L781). Situação atual por pessoa (em jornada, em intervalo, sem registro, encerrada), entrada,
último registro, banco, pendências, escala; indicadores somados. Só a linha da própria pessoa é real no protótipo.

3.27 **Liga/desliga em duas camadas** (`gl` L447, `ef` L448, `feats` L761). Função ativa = global (Master, `bj-recursos`)
E escritório (primário). Global desligado trava a chave do escritório.

3.28 **Configuração** (`saveE` L760). Grava modo facial, foto, retenção, limite e prazo do banco, trabalho externo, sedes e feriados.

3.29 **Competências** (L773). Lista de 10/2026 até o mês atual; padrão fixo `2026-11` (L430).

3.30 **Dados do empregador e do empregado** (`conta` L466, `folhaDados` L632). O nome do escritório "Farlei Assis da Rocha
Sociedade de Advogados" é trocado por "Farlei Assis da Rocha Sociedade Individual de Advocacia" (razão social do
empregador diferente do nome exibido). CNPJ e dados do empregado vêm fixos para o Farlei; outras pessoas saem vazias.

---

## 4. Ids `ponto-*` do `bj-recursos.js`

Padrão de todos: **ligado** (`ativo` = sem registro, ou `on !== false`, ou data `ate` vencida). Todos podem ser desligados
(nenhum tem `base:1`). No Ponto não há `data-recurso` para eles: o efeito vem do JS (`gl`/`ef`). Cada um também tem a
chave "Escritório" (padrão ligada).

| id | Rótulo no bj-recursos | Ícone | Título na aba Configurações | O que esconde/muda quando desligado |
|---|---|---|---|---|
| `ponto` (tela) | Ponto Eletrônico | `bi-fingerprint` | | tela inteira: não Master vê "Ponto Eletrônico em preparação" + "Voltar ao Expediente"; Master vê faixa vermelha |
| `ponto-animacao` | Ponto: validação em tela cheia | `bi-clock-history` | Validação em tela cheia | sem tela cheia (exceto batida facial, que sempre usa); resultado vira toast |
| `ponto-sons` | Ponto: sons | `bi-volume-up` | Sons de confirmação e erro | some o botão de som; sem plim, sem som de erro, sem som do lembrete |
| `ponto-comprovante` | Ponto: comprovante em imagem | `bi-receipt` | Comprovante em imagem | some a aba Comprovantes; não gera nem guarda comprovante; some código e "Baixar comprovante" no sucesso; toast sem "Comprovante na aba Comprovantes"; NSR para de subir (é contado pelos comprovantes) |
| `ponto-geo` | Ponto: geolocalização | `bi-geo-alt` | Geolocalização | some a caixa de localização; sem checagem de raio nem erro de GPS; some a etapa de localização e o sufixo no sucesso; comprovante diz "Geolocalização não exigida" |
| `ponto-facial` | Ponto: reconhecimento facial | `bi-person-bounding-box` | Reconhecimento facial | some o botão facial e o modo obrigatório; campos de facial ficam desabilitados |
| `ponto-lembrete` | Ponto: lembrete de saída | `bi-bell` | Lembrete de saída | some o aviso "Saída prevista" / "Passou da saída prevista"; sem toast, som e notificação de lembrete; não pede permissão de notificação |
| `ponto-alertas` | Ponto: alertas de intervalo | `bi-exclamation-triangle` | Alertas de intervalo | sem modal "Antes de registrar"; some o aviso de intervalo em andamento |
| `ponto-aceite` | Ponto: aceite da folha | `bi-pen` | Aceite mensal da folha | some o botão "Assinar folha" (selo de aceite já gravado continua) |

Descrições das funções na aba Configurações (`FEATS` L414 a L423):
- Validação em tela cheia: "Relógio completando a volta e confirmação ocupando toda a tela."
- Sons de confirmação e erro: "Plim no registro e alerta diferente quando o ponto falha."
- Comprovante em imagem: "Imagem em alta definição com código de validação, QR Code, marca d'água e padrões de segurança."
- Geolocalização: "Exige que o registro seja feito dentro do raio de uma sede."
- Reconhecimento facial: "Confirma a identidade pela câmera, com cadastro e autorização prévios."
- Lembrete de saída: "Avisa quando passar do horário previsto e a saída não foi registrada."
- Alertas de intervalo: "Avisa antes da batida sobre intervalo, interjornada e descanso semanal."
- Aceite mensal da folha: "O colaborador confirma ou registra ressalva na folha fechada."

---

## 5. Textos visíveis com travessão (reescrever)

15 ocorrências: 11 de "—" (U+2014) e 4 de "–" (U+2013). Todas são marcador de "sem valor".

| # | Linha | Caractere | Onde aparece | Sugestão de troca |
|---|---|---|---|---|
| 1 | L154 | — | Folha (tela): batida que falta hoje ou em feriado (`c.tr`) | vazio, ou "sem registro" |
| 2 | L411 | — (2x) | Equipe: Entrada e Último registro do exemplo "Diego Rocha" | "sem registro" |
| 3 | L681 | – | PDF da folha: batida que falta | vazio ou "sem registro" |
| 4 | L682 | – (3x) | PDF da folha: Horas Trabalhadas, Horas Extras e Saldo do Mês em dia futuro | vazio |
| 5 | L755 | — | Minhas Justificativas: "Obs. da Análise" de pendente | "Aguardando análise" |
| 6 | L755 | — | Minhas Justificativas: "Analisado em" sem análise | "Aguardando" ou vazio |
| 7 | L776 | — (4x) | Banco de Horas: Crédito, Compensado, Saldo e Vence em sem valor | "0h" / vazio / "Sem prazo" |
| 8 | L779 | — (2x) | Equipe, linha "Você": Entrada e Último registro sem batida | "sem registro" |

---

## 6. Dados de exemplo que pedem campo ou tabela no banco

### 6.1 Sedes (`sedes` L450, `addSede` L820)

| Campo | Exemplo | Observação |
|---|---|---|
| nome | "Brasília/DF", "Maceió/AL", "Nova sede" | por escritório |
| latitude | vazio ("informar") | texto aceita vírgula (convertida em ponto); "Usar minha posição" grava 6 casas |
| longitude | vazio | |
| raio (m) | 150 (padrão) | inteiro |
| Tabela | `ponto_sede` | espec |

### 6.2 Feriados (`FERIADOS` L404, `feriados` L821)

| Campo | Exemplo |
|---|---|
| data | `2026-10-12`, `2026-11-02`, `2026-11-15`, `2026-11-20` |
| nome | "Nossa Senhora Aparecida", "Finados", "Proclamação da República", "Consciência Negra" |
| abrangência | "Todas" ou nome da sede (casado com a lotação da pessoa em caixa alta) |
| origem | nacional (fixo no código) ou do escritório |
| Tabela | `ponto_feriado` |

### 6.3 Batida (`validar` L522 a L526)

nsr (9 dígitos), tipo (entrada, repouso, retorno, saida), data e hora (ISO, servidor), hora do aparelho (espec),
nome, escritório (razão social), CNPJ, lat, lng, precisão (m), geo exigida (bool), facial (bool), offline (bool),
motivo de trabalho externo, hash SHA-256, código `BJP-...`, foto (opcional, com retenção). Tabelas `ponto_batida` e `ponto_comprovante`.

### 6.4 Escalas (`ESC` L409)

| id | Rótulo | Semanal | Distribuição | Carga por dia (dom, seg ... sáb) em segundos |
|---|---|---|---|---|
| `44-6` (padrão) | "44h · seg a sáb (7h20)" | 44h | Segunda a Sábado | 0, 26400 ×6 |
| `44-5` | "44h · seg a sex (8h48)" | 44h | Segunda a Sexta | 0, 31680 ×5, 0 |
| `40-5` | "40h · seg a sex (8h)" | 40h | Segunda a Sexta | 0, 28800 ×5, 0 |
| `30-5` | "30h · seg a sex (6h)" | 30h | Segunda a Sexta | 0, 21600 ×5, 0 |

Mais: vínculo pessoa → escala; horário contratual (PDF: "09:00 às 17:20" só para `44-6`); intervalo
(PDF fixo "12:00 às 13:00"); texto da escala no PDF ("Segunda a Sábado (STQQSS)"). Tabela `ponto_escala`.

### 6.5 Banco de horas (`ANT` L405, L774 a L776)

| Campo | Exemplo |
|---|---|
| saldo anterior por competência | `2026-10`: 1.671.000 s (+464h10m); `2026-11`: 1.905.840 s (+529h24m) |
| limite (h) | 120 |
| prazo de compensação (meses) | 6 (1 a 24) |
| lote | competência, crédito, compensado, saldo, vencimento, situação |
| Tabela | `ponto_banco_lote` (+ limite e prazo em `ponto_config_escritorio`) |

### 6.6 Justificativa (`JUST` L403, `EQJ` L412)

data, chave, tipo ("Falta" / "Esquecimento de Registro" / "Trabalho Externo"), subtítulo ("Saída às 23:59"),
campo (tipo da batida), hora, motivo, anexo (arquivo: imagem ou PDF), situação (pendente, abonado, recusado),
observação da análise, data da análise, analista. Exemplo: 21/10/2026, esquecimento da saída às 23:59, abonado em 22/10/2026.
Tabela `ponto_justificativa`.

### 6.7 Ajuste (`AJ_SEED` L410)

dia, campo, hora nova, por ("Gestor"), em (`2026-10-22`), chave da justificativa. Espec pede também valor anterior.
Tabela `ponto_ajuste`.

### 6.8 Aceite da folha

competência, situação (ok / ressalva), data e hora, nome, texto da ressalva. Tabela `ponto_folha_aceite`.

### 6.9 Folha emitida

competência, código `BJF-...`, hash, nome, data de emissão, aceite na emissão (para validar o código).

### 6.10 Configuração do escritório

8 chaves de função; modo facial (opcional / obrigatória); guardar foto (bool); retenção (6, 12, 24 meses; padrão 12);
limite e prazo do banco; permitir trabalho externo (padrão sim); raio padrão (150). Tabela `ponto_config_escritorio`;
global em `recurso_estado`.

### 6.11 Cadastro facial

autorizado em, recusado em, (template no sistema real). Preferência de som (mudo) por usuário.

### 6.12 Dados do empregado e do empregador (PDF; `folhaDados` L632, `conta` L466)

| Campo | Exemplo do desenho |
|---|---|
| código do empregado | "0001" |
| cargo | "ADVOGADO (A)" |
| CPF (mascarado) | "***.547.725-**" |
| CTPS (mascarada) / série | "***2329" / "0060" |
| lotação | "MACEIÓ/AL" (casa com a abrangência do feriado) |
| data de admissão | "15/02/2020" |
| razão social do empregador | "Farlei Assis da Rocha Sociedade Individual de Advocacia" |
| CNPJ | "46.819.282/0001-54" |
| endereço (2 linhas) | "Quadra QNF 3, 31" / "Taguatinga Norte, Taguatinga/DF CEP 72125-530" |
| responsável que assina | "FARLEI ASSIS DA ROCHA OAB/DF 49.613" |
| tipo de ponto | "Eletrônico" |
| banco de horas | "Sim (acordo individual)" |

### 6.13 Equipe de exemplo (`EQ` L411, `EQJ` L412), fictícia

Ana Souza (Advogada, em jornada, +12h40m, 1 pendência), Bruno Lima (Estagiário, em intervalo, -2h10m), Carla Mendes
(Controladoria, +3h05m, 2), Diego Rocha (Advogado, sem registro, +0h40m, 4), Elisa Prado (Secretária, encerrada, +1h15m).
Justificativas: Carla 05/11 esquecimento "Saída às 18:02"; Diego 04/11 falta "Atestado anexado"; Ana 03/11 trabalho
externo "Entrada às 09:10, audiência". No sistema: cadastro real da equipe e situação calculada das batidas.

### 6.14 Batidas de exemplo

`SEED` (L402): 03/11 (entrada 09:37:38, repouso 12:03:22, retorno 13:07:44), 04/11 (entrada 09:29:04, repouso
19:31:43), 06/11 (entrada 09:31:30). `OUT` (L406): folha de outubro/2026 importada (por dia: 4 horários, trabalhado,
extra, saldo acumulado até +65:14). `PEND` (L407): 10 pendências de 26/10 a 06/11.

---

## 7. Citações de lei e portaria (literal)

1. Aba Banco de Horas (L207): "Horas negativas abatem primeiro os créditos mais antigos. O prazo padrão é de 6 meses, próprio do acordo individual (art. 59, § 5º, da CLT), e pode ser ajustado em Configurações conforme o acordo do escritório."
2. Modal Cadastro facial (L306): "Para registrar com o rosto, é preciso um cadastro facial único. A imagem do rosto é dado biométrico, classificado como dado pessoal sensível pela LGPD (art. 5º, II)."
3. Modal Cadastro facial (L307): "O BlueJus usa esse dado somente para confirmar a sua identidade no registro de ponto. Ele não é compartilhado com terceiros para outra finalidade, e o cadastro pode ser excluído a seu pedido."
4. PDF da folha, caixa "Base Legal:" (L703): "Art. 74, §2º da CLT  |  Portaria MTP nº 671/2021  |  Art. 13 da Portaria nº 3.626/91"
5. PDF da folha (L703): "(Esta folha de ponto substitui o Quadro de Horário de Trabalho, inclusive o de menores.)"
6. PDF da folha, Regime de Jornada (L664): "Banco de Horas: Sim (acordo individual)"; "Intervalo Intrajornada: 1h00 (mínimo)"; "Intervalo Interjornada: mínimo 11h00".
7. PDF da folha, declaração (L696): "Declaro que os registros acima representam fielmente minha jornada de trabalho."
8. Regras sem citação no texto: intervalo mínimo de 1h, descanso de 11h entre jornadas, descanso semanal após 6 dias.
9. Espec (a confirmar antes do lançamento): vigência da Portaria MTP 671/2021 diante da Portaria Consolidada MTE nº 1/2025; requisitos para REP-P; vigência do art. 13 da Portaria 3.626/1991; prazos do banco por acordo de cada escritório.

---

## 8. Pontos de atenção do próprio desenho (para decidir na implementação)

1. "Jornada semanal 44h00m" no Registrar está fixo (L137); não segue a escala.
2. NSR = quantidade de comprovantes + 1 (L522): com o comprovante desligado o NSR não sobe. No sistema, NSR sequencial no servidor.
3. Falta abonada continua descontando a carga do dia (L620): o abono só cria ajuste para esquecimento de batida.
4. Exclusões sem confirmação: justificativa pendente, sede, feriado, cadastro facial.
5. Mudanças de sede, feriado, limite, prazo, modo facial e foto não vão para a auditoria.
6. Barra "Ver como" / "Próxima batida" e a nota "Dados de exemplo" da Equipe são do protótipo.
7. Competência padrão fixa em 11/2026 e lista desde 10/2026.
8. Pendências vêm de lista fixa; no sistema, cálculo dos últimos 30 dias.
9. Hora oficial, código e comprovante são calculados no navegador; a espec manda fazer no servidor (NTP, HMAC).
10. Foto da facial é capturada e não é gravada; a espec manda guardar template.
11. Sedes sem coordenada não bloqueiam a batida; com GPS negado, o protótipo deixa bater (modo demo).
12. Os links do cabeçalho apontam para versões de outras telas que não estão na pasta 08-11 (Expediente 30.10, Demandas 30.10, Metas 29.10, Agenda 18.10, Trabalho Externo 25.10).
13. `raio`, `raioOff` e `trocaRaio` existem no JS (L799) sem campo na tela: o raio agora é por sede.
14. Dicas usam `data-tip`; nenhuma biblioteca da pasta desenha `data-tip` (o `bj-dica.js` lê `title`). A intenção é dica no padrão do sistema.
15. Tabelas fora da Folha (`.bjpJ`) não têm listra nem hover.
