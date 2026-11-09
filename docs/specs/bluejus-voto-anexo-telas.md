# BlueJus Voto: inventário do desenho por região e tela

Fonte: `docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html` (444 linhas, 204 KB), lido inteiro.
Consultados só para entender botões: `bj-voto-cenarios.js` (inteiro), cabeçalhos de `bj-voto-entrada.js`,
`bj-voto-boletim.js`, `bj-contas.js`, `bj-recursos.js`, `bj-tema.js`, `bj-monitor.js`, `bj-dica.js`.
Travessão (traço longo ou médio): **nenhum** no `.dc.html` nem nos `bj-voto-*.js` (conferido por grep). Onde um texto
teria problema de outra ordem, está marcado com ⚠.

Convenções: "estado" = nome da variável do desenho (`state.aba`, `sub`, `sc-if`). `S` = `BJVotoNucleo.Sistema`.
Textos entre aspas são literais do desenho. `{x}` = valor calculado.

---

## 0. Estrutura, dependências, base visual

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Arquivo | Título da página | `<title>Voto</title>`, `lang="pt-BR"` | sempre |
| Helmet | Bibliotecas da família | `bj-monitor.js` (mede carga/erros, chave `bj-monitor`), `bj-recursos.js` (liga/desliga telas pelo Master, chave `bj-recursos`; registra a tela `voto` com ícone `bi-archive`), `bj-tema.js` (temas comemorativos: selo `#bjtSelo`, mini `#bjtMini`, fita `#bjtFita`), `bj-dica.js` (troca `title=` por dica azul-clara `#e5f1f7`/`#0b4f73`), `bj-contas.js` (usuário e conta; isola chaves por conta com prefixo `bjt:{conta}:`) | sempre |
| Helmet | Bibliotecas do Voto | `bj-voto-nucleo.js`, `bj-voto-testes.js`, `bj-voto-boletim.js`, `bj-voto-cenarios.js`, `bj-voto-entrada.js` | sempre |
| Helmet | Biblioteca externa | `https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js` (QR Code) | sempre |
| Helmet | Ícones | Bootstrap Icons local `./bootstrap-icons/bootstrap-icons.min.css` | sempre |
| Helmet | Comentário do CSS | "BlueJus · Voto. Módulo isolado: usa apenas chaves bj-voto-* e não altera dados de outras telas. Ver ESPEC_VOTO.md." ⚠ as chaves reais são `bj-votacao-*` | n/a |
| Base | Fonte | `body { font-family:Arial, Helvetica, sans-serif }`; monoespaçada `.vMono { font-family:"Courier New", Courier, monospace; word-break:break-all }` | sempre |
| Base | Fundo e texto | `body { margin:0; background:#f3f6f8; color:#12242f }` | sempre |
| Base | Cores-base | primária `#0a6fa0`; hover `#3b8fc2`; azul-escuro títulos `#0b3550`; texto `#12242f`; texto secundário `#34505f`; cinza `#5b7383`; rótulo `#4a6373`; borda `#d3dde4`; linhas `#e6edf2`/`#e3ecf2`; sucesso `#1e7b45`; erro `#a3232b`; alerta `#8a6200`; cabeçalho do sistema `#0a76a8` | sempre |
| Base | Raio | 3px em tudo (cartão, botão, campo, chip, modal, toast); cabine 14px 14px 10px 10px; tela/teclado da urna 2 a 3px | sempre |
| Base | Mapa de status (`Component.ST`) | rascunho "Rascunho" `#5b7383`; publicada "Publicada" `#0a6fa0`; aberta "Votação aberta" `#1e7b45`; encerrada "Encerrada" `#8a6200`; apurada "Apurada" `#0b3550` | por votação |
| Layout | Coluna principal | `main { max-width:1240px; margin:0 auto; padding:18px clamp(10px,2vw,24px) 110px; display:flex; flex-direction:column; gap:12px }` | sempre |
| Layout | Título | `h1` "Voto" `font-size:27px; font-weight:normal; color:#12242f` | sempre |
| Aviso permanente | Faixa amarela no topo | `.vAviso`: "**Ambiente demonstrativo.** Dados e eleitores são fictícios. Não é votação oficial de nenhuma entidade e o sistema não possui certificação de órgão eleitoral." | sempre (sistema e portal) |
| Responsivo | `@media (max-width:760px)` | `.uGr` 1 coluna; `.uFen` (LEDs) some; `.uTela { min-height:300px }`; `.vG2` 1 coluna; `.vOc` some (separador e rótulo "Voto" do cabeçalho) | celular |
| Acessibilidade | `@media (prefers-reduced-motion: reduce)` | desliga animações `.uBg .uBody .uNum .uFade .uFim .uCur`; anel do FIM com duração .01s; LEDs não piscam (`piscaLeds` checa `matchMedia`) | movimento reduzido |
| Foco | Contorno | `.vF:focus, .vB:focus-visible, .vTab:focus-visible, .uT:focus-visible { outline:3px solid #3b8fc2; outline-offset:1px }` | foco por teclado |

## 1. Componentes próprios do desenho (CSS do helmet)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | `.vC` | `background:#fff; border:1px solid #d3dde4; border-radius:3px; box-shadow:0 1px 3px rgba(18,36,47,.06)` | sempre |
| Barra de título do cartão | `.vH` | `display:flex; gap:9px; padding:10px 16px; background:#0a6fa0; color:#fff; font-size:15.5px; font-weight:bold`; variante "Incidentes registrados" com `background:#8a6200` | sempre |
| Botão | `.vB` | `min-height:36px; padding:0 14px; background:#fff; color:#0a6fa0; border:1px solid #0a6fa0; border-radius:3px; font-size:13.5px; font-weight:bold; gap:7px` | normal |
| Botão | `.vB:hover` | `background:#3b8fc2; border-color:#3b8fc2; color:#fff` | hover |
| Botão primário | `.vP` | `background:#0a6fa0; color:#fff` | normal |
| Botão perigo | `.vD` | `color:#a3232b; border-color:#a3232b`; hover fundo `#a3232b` | normal/hover |
| Botão desabilitado | `.vB:disabled` | `opacity:.45; cursor:not-allowed; background:#fff; color:#5b7383; border-color:#c6d3dd` | desabilitado |
| Botão chave apresentada | `.vB.vKey:disabled` | `opacity:1; background:#1e7b45; color:#fff; border-color:#1e7b45` | parte do guardião já apresentada |
| Rótulo | `.vL` | coluna, `gap:4px; font-size:12px; font-weight:bold; color:#4a6373` | sempre |
| Campo | `.vF` | `height:36px; border:1px solid #d3dde4; border-radius:3px; padding:0 9px; font-size:14px; color:#12242f; width:100%`; `textarea.vF { height:66px; padding:7px 9px; resize:vertical }` | sempre |
| Aba | `.vTab` / `.vTabOn` | `padding:11px 15px; border-bottom:3px solid transparent; font-size:14px; bold; color:#34505f; white-space:nowrap`; hover `#0a6fa0`; ativa `color:#0a6fa0; border-bottom-color:#0a6fa0` | aba/sub-aba |
| Grade 2 colunas | `.vG2` | `repeat(2, minmax(0,1fr)); gap:10px` (1 coluna até 760px) | formulários |
| Indicadores | `.vKpis` / `.vKpi` | grade `auto-fit minmax(150px,1fr) gap:10px`; cartão `padding:13px 15px; border-left:4px solid #0a6fa0`; número `b { font-size:24px; color:#0b3550 }`; rótulo `span { 12.5px; #5b7383 }` | painel, resultados, estatísticas |
| Chip | `.vChip` | `padding:3px 9px; color:#fff; font-size:12px; bold; border-radius:3px`; fundo vem do JS (cor do status) | status |
| Linha de lista | `.vLin` | `padding:11px 15px; border-top:1px solid #e6edf2`; pares `background:#f6f9fb` (zebra) | listas |
| Avatar | `.vAv` | `56x56; background:#e5f1f7; color:#0a6fa0; font-size:19px; bold`; img `object-fit:cover` | candidaturas |
| Caixa de foto | `.vFoto` | `aspect-ratio:4/3; border:2px dashed #9fc3d8; background:#f5fafd; color:#0a6fa0; 12.5px bold; cursor:pointer` | upload |
| Caixa de foto ruim | `.vFotoRuim` | `border-color:#a3232b; background:#fdf4f4; color:#a3232b` | qualidade reprovada |
| Caixa de foto (moldura) | `.vFotoBox` | `max-width:280px; gap:5px` | upload |
| Mensagem de qualidade | `.vQ` | `12.5px; line-height:1.35; bold`; cor e ícone por estado: ok `#1e7b45` `bi-check-circle-fill`; ressalva `#8a6200` `bi-exclamation-triangle-fill`; ruim `#a3232b` `bi-x-circle-fill`; analisando `#0a6fa0` `bi-hourglass-split` | upload |
| Linha de acordeão | `.vPerG` / `.vPerGOn` | `padding:13px 16px; border-top:1px solid #e3ecf2; 14.5px; color:#0b3550`; hover `#3b8fc2` branco; aberto `#e8f2f8` | Perfis |
| Pílula de etapa | `.vPas` / `.vPasOk` / `.vPasAt` | `12px; padding:3px 8px; #eef3f6/#5b7383`; feita `#dcefe3/#1e7b45`; atual `#0a6fa0/#fff bold` | passos |
| Título de seção | `.vSec` | `grid-column:1/-1; 14px bold #0a6fa0; border-bottom:1px solid #d3dde4` | formulário de criação |
| Ladrilhos | `.vCats` / `.vCat` / `.vCatOn` | grade `auto-fill minmax(190px,1fr) gap:8px`; ladrilho `padding:13px 12px; border:1px solid #c9d8e2; 13.5px bold #0b3550`; ícone 19px `#0a6fa0`; hover `#3b8fc2`; selecionado `#0a6fa0` branco | categoria, equipe |
| Aviso | `.vAviso` | `padding:10px 14px; background:#fff8e6; border:1px solid #e6c56a; color:#6b4e00; 13px; line-height:1.4` | avisos |
| Passo | `.vPasso` | `display:flex; gap:8px; 13px; #34505f` (declarado, não usado na marcação) | n/a |
| Modal | `.vMod` / `.vModC` | fundo `position:fixed; inset:0; z-index:4000; background:rgba(11,53,80,.55); padding:14px`; caixa `width:min(720px,100%); max-height:88vh; background:#fff; border-radius:3px`; clique fora fecha; clique dentro `stopPropagation` | modais |
| Toast | `role="status"` | `position:fixed; bottom:24px; left:50%; translateX(-50%); z-index:6000; background:{toastBg}; color:#fff; padding:11px 18px; border-radius:3px; 14px bold; box-shadow:0 8px 22px rgba(0,0,0,.25); max-width:90vw`; some em 3,6 s; verde `#1e7b45` (sucesso), vermelho `#a3232b` (erro), azul `#0a6fa0` (fallback de cópia mostra o próprio link) | `temToast` |
| Portal | `.vPortal #bjtSelo, .vPortal #bjtMini { display:none }` | no portal some o selo do tema | portal |

## 2. Cabeçalho, menu e modos (sistema x portal)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cabeçalho sistema | Barra | `position:sticky; top:0; z-index:60; background:#0a76a8; color:#fff; height:64px; gap:clamp(8px,1.6vw,18px)` | `sistema` (= `!state.portal`), aparece no print |
| Cabeçalho sistema | Logo | `./bluejus-favicons/bluejus-logo-nav-branco.png` 30px, link para `./03 - EXPEDIENTE - VS 30.10.26.dc.html` ⚠ arquivo não está nesta pasta (está em `redesign-04-11-26`) | sistema |
| Cabeçalho sistema | Separador + "Voto" | separador 1x22px `rgba(255,255,255,.45)`; texto "Voto" 17px; ambos `.vOc` (somem até 760px) | sistema |
| Cabeçalho sistema | Usuário | `{usuario}` = `nomeU().toUpperCase()`; nome vem de `BJContas.usuario().nome`, padrão "Farlei Assis da Rocha"; `bold; clamp(12px,1.5vw,15px); max-width:50vw; ellipsis` | sistema |
| Menu sistema | Barra | `background:#fff; gap:4px; box-shadow:0 1px 3px rgba(18,36,47,.08); overflow-x:auto` | sistema |
| Menu sistema | Itens | "EXPEDIENTE" (03), "DEMANDAS" (`./15 - Demandas - VS 30.10.26.dc.html`, ⚠ não está na pasta), "PONTO" (`./16 - Ponto Eletronico...`), "ASSINATURA" (`./17 - Assinatura...`), "VOTO" (ativo `#0a6fa0`, `border-bottom:3px solid #0a6fa0`); demais `padding:15px 12px 12px; 13px bold; letter-spacing:.3px; color:#34505f`; hover `#0a6fa0` | sistema |
| Menu sistema | Diferença na família | ⚠ o Ponto (16) mostra também MINHAS METAS, AGENDA, TRABALHO EXTERNO e usa `data-recurso="voto"`; o Voto não tem esses itens nem `data-recurso` | sistema |
| Cabeçalho portal | Barra | `background:#fff; border-bottom:3px solid #0a6fa0; min-height:74px; padding:8px clamp(12px,3vw,26px); flex-wrap:wrap` | `portal` (link/QR ou "Testar acesso") |
| Cabeçalho portal | Logo + título | `./bj-voto-logo.png` 50px; separador 1x30 `#d3dde4`; "Portal do Eleitor" 18px bold `#0b3550` | portal |
| Cabeçalho portal | Chip | "Terminal presencial" (`.vChip` `#0a6fa0`) | `presencial` (aberto por "Abrir terminal presencial") |
| Cabeçalho portal | Botão | "Voltar à gestão" (`bi-arrow-left`) → sai do portal, limpa identificação, volta para `aba:'ges'` | `portalTeste` (portal aberto pela gestão) |
| Abas | Barra de abas | cartão `.vC` com `overflow-x:auto`; abas "Votar" (`bi-check2-square`, `ele`), "Resultados" (`bi-shield-check`, `tra`); só para Master fora do portal: "Gestão eleitoral" (`bi-gear`, `ges`), "Acompanhar" (`bi-broadcast`, `aco`) | `state.aba` inicial `'ele'` |
| Abas | Quem é "Master" aqui | `master()` = `BJRecursos.master()`: e-mail `farlei.rocha@gmail.com`, ou `u.master`, ou nenhum usuário logado (então `true`) ⚠ é o Master da plataforma, não um papel do escritório | gate das abas Gestão e Acompanhar |
| Abas | Aba escondida | `hab` "Cadastro para votar" não está na barra; só se chega por "Primeiro acesso" ou ao localizar o nome sem cadastro (PIN) | `aba:'hab'` |
| Passos do eleitor | Pílulas | "1. Localizar seu nome", "2. Confirmar identidade", "3. Votar", "4. Comprovante" (`.vPas` 13px, `padding:5px 10px`); etapa atual: 0 sem nome localizado; 1 localizado ou sem lista; 2 identificado; 3 identificado e já tem comprovante em todas | `vPasTem` = portal e cabine fechada |
| Links de entrada | Endereço | `https://www.bluejus.com.br/voto/e/{id}` (uma votação) e `https://www.bluejus.com.br/voto/v/{evento}` (evento com várias); no protótipo também `#eleicao=ID` e `#evento=ID`; `hashchange` relê | portal |
| Links de verificação | QR de documentos | comprovante `.../voto/e/{id}/c/{cod}`; relatório `.../r/{cod}`; auditoria `.../a/{cod}` | PDFs/imagens |

## 3. Aba Votar (`aba:'ele'`), sistema e portal

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Conferência do endereço | Cartão | `.vC` `border-left:4px solid #0a6fa0`; ícone `bi-shield-check` 20px; "Confira se o endereço é bluejus.com.br e se o código de conferência é **{focoCod}**." (`XXXX-XXXX`, 4+4 hex do hash da configuração; no evento, junção dos hashes) | `focoTem` (aberto por link de votação/evento) |
| Conferência do endereço | Botão | "Primeiro acesso" (`.vP`, `bi-person-vcard`) → `aba:'hab'` | `focoHab` = sem lista de aptos, link ativo, sem cadastro ou recusado, acesso por PIN |
| Localizar nome | Cartão | título "Localize seu nome para votar" 17px `#0b3550`; "Informe seus dados como constam na lista da votação." | `csTem` = portal, não identificado, nome não localizado, votação com lista |
| Localizar nome | Campos | "Nome completo"; `{csRot}` (no acesso por código: "CPF" / rótulo de número da categoria / "E-mail cadastrado"; no PIN: rótulo da categoria, ex. "Unidade", "Número de inscrição"); `{rtGr}` (ex. "Bloco ou torre") só se `csAmb` | `csAmb` = identificação repetida em grupos |
| Localizar nome | Dica de teste | `.vAviso` "Para testar: Eleitor Exemplo Um, CPF 000.000.001-91, e-mail um@exemplo.invalid. Para consultar inaptidão: Eleitor Exemplo Inapto, CPF 000.000.005-15." | `vHint` só na votação `assoc-dir` |
| Localizar nome | Botão | "Continuar" (`.vP`, `bi-arrow-right`) → `vLocalizar()` | sempre no cartão |
| Localizar nome | Erros | "Há mais de um registro com essa identificação. Informe também o campo abaixo." / "Informe também a identificação cadastrada." / "Nome não localizado na lista desta votação. Confira os dados ou procure a comissão responsável." (`#a3232b` 13.5px bold) | `csErro` |
| Localizar nome | Resultado (não apto) | linha: votação em negrito, detalhe, chip "Apto(a)" `#1e7b45` / "Não apto(a)" `#a3232b`; detalhes: "Aptidão concedida pelo conselho em {data}: {justificativa}" / "Ocorrência: {oc}. Inaptidão mantida pelo conselho em {data}: {just}" / "Ocorrência registrada em {data}: {oc}. Em avaliação pelo conselho." / "Você consta na lista de aptos." / "Procure a comissão responsável." | `csRes` |
| Localizar nome | Desfecho | código por e-mail: vai ao cartão de código com nome travado; PIN com cadastro: vai ao login com nome travado; PIN sem cadastro: vai à aba `hab` com nome, identificação e grupo travados | `vLoc` |
| Acesso por código | Cartão | título "Confirme sua identidade"; se nome localizado: `bi-check-circle-fill` verde + "**{nome}**, você está apto(a) a votar." + botão "Não sou eu" (limpa `vLoc`) | `vTokTem` = portal, não identificado, acesso `token` |
| Acesso por código, passo 1 | Campos | "Nome completo" e `{vIdRot}` (CPF / número / "E-mail cadastrado") só sem `vLoc`; "E-mail cadastrado" (`type=email`) se a identificação não for o e-mail | `vT1` (`vStep:1`) |
| Acesso por código, passo 1 | Aviso de localização | `bi-geo-alt` 12.5px: "Ao entrar, o navegador pedirá sua localização[, obrigatória nesta votação]. Ela fica no registro da sua identificação, para auditoria, e nunca é vinculada ao voto." | `vGeoAv` (geo diferente de `nao`) |
| Acesso por código, passo 1 | Botão | "Receber código por e-mail" (`.vP`, `bi-envelope-check`) → `S.pedirToken` | `vT1` |
| Acesso por código, passo 2 | Texto | "Informe o código de 6 números enviado para {vMail}. Ele vale por 10 minutos." | `vT2` |
| Acesso por código, passo 2 | Aviso de demonstração | `.vAviso` "Demonstração: nenhum e-mail é enviado. Código gerado: **{vDemo}**" (mono) | `vT2` (protótipo) |
| Acesso por código, passo 2 | Campo | "Código" `max-width:240px`, `.vMono`, `inputmode=numeric maxlength=6`; Enter confirma | `vT2` |
| Acesso por código, passo 2 | Botões | "Voltar" (volta ao passo 1) e "Confirmar código" (`.vP`, `bi-shield-check`) → `S.validarToken`; se já tem cadastro: pede localização e entra; se novo: passo 3 | `vT2` |
| Acesso por código, passo 3 | Fotos | 2 caixas `repeat(2, minmax(0,200px))`: "Foto do documento" (`bi-card-heading`, `capture=environment`) e "Selfie" (`bi-person-bounding-box`, `capture=user`); `accept="image/*"`; `data-sem-central="1"` | `vT3` (primeiro acesso por código) |
| Acesso por código, passo 3 | Consentimento | caixa: "Autorizo o tratamento dos meus dados, imagens e localização somente para confirmar minha identidade nesta votação, nos termos da LGPD." (13px `#34505f`) | `vT3` |
| Acesso por código, passo 3 | Declaração | caixa em negrito `#12242f`: "Declaro, nos termos da lei e sob as penas do art. 299 do Código Penal (falsidade ideológica: reclusão de 1 a 5 anos e multa, se o documento é público; reclusão de 1 a 3 anos e multa, se o documento é particular), que sou a pessoa identificada neste cadastro e que os dados, o documento e a selfie apresentados são verdadeiros." (texto também guardado em `Component.DECL` e enviado como `declTexto`) | `vT3` |
| Acesso por código, passo 3 | Botão | "Concluir identificação" (`.vP`, `bi-check-lg`) → `S.concluirToken`; toast "Identificação concluída." | `vT3` |
| Acesso por código | Erro | `{vErro}` `#a3232b` 13.5px bold (mensagens do núcleo; geo obrigatória: "Para continuar, permita o acesso à localização no navegador e tente novamente.") | `vErro` |
| Acesso por PIN | Cartão | "Confirme sua identidade"; linha "{nome}, você está apto(a) a votar." + "Não sou eu" se localizado; aviso de localização | `vLogTem` = portal, não identificado, acesso `pin` |
| Acesso por PIN | Campos | `{rtId}` e "`{rtGr}` (se houver)" só sem `vLoc`; "PIN" (`type=password inputmode=numeric autocomplete=current-password`, Enter entra); grade `auto-fit minmax(150px,1fr)` | `vLogTem` |
| Acesso por PIN | Botão e erro | "Entrar" (`.vP`, `bi-box-arrow-in-right`); erro "Cadastro não encontrado. Faça o primeiro acesso." ou do núcleo | `vErro` |
| Identificado | Barra | `bi-person-check-fill` verde 20px; "**{nome}, {identificação}**" (14.5px); botão "Sair" (limpa sessão e volta a `ele`) | `vLogado` = portal e `vUid` |
| Lista vazia | Aviso | gestão: "Visão de gestão: aqui aparecem todas as votações. O votante vê somente aquelas em que consta. Clique em Votar para ver a tela do votante." / com cadastro: "Nenhuma votação disponível para o seu cadastro. Se você deveria constar na lista, procure a comissão responsável." / portal: "Identifique-se para ver as votações em que você está cadastrado(a)." / sistema sem cadastro: "Faça sua habilitação para ver as votações em que você está cadastrado(a)." | `eleVazio` |
| Cartão da votação | Cabeçalho | nome 19px bold `#0b3550`; descrição 13.5px `#34505f`; chip de status | `eleLista` (não rascunho; no portal ou sem Master só as visíveis ao eleitor; filtrado pelo link) |
| Cartão da votação | Metadados | 13px `#34505f`, `gap:18px`: `bi-calendar-event` "{início} até {fim}" (`dd/mm/aa hh:mm`); `bi-tags` "{entidade} · {modalidade}[ · Voto aberto]"; `bi-geo` "{grupos}" ou "Todos os grupos"; `bi-upc` "Código de conferência {XXXX-XXXX}" | sempre |
| Cartão da votação | Mensagem | 13.5px bold com ícone e cor: "Você já votou. Até o encerramento da sessão permanente pode alterar seu voto." (`bi-arrow-repeat` verde) / "Você já votou nesta votação." (`bi-check-circle-fill` verde) / "Sessão suspensa até {data}." / "Encerra em mm:ss." (`#8a6200`) / "Votação apurada. O resultado está em Transparência." ⚠ / "Votação encerrada, aguardando apuração." / "Informe seus dados abaixo para receber o código por e-mail." / "Entre com sua identificação e PIN ou faça o primeiro acesso." / "Faça sua habilitação para poder votar." / "Sua habilitação está em análise." / "Você não está apto(a) para esta eleição.[ Ocorrência: {t}[ (em avaliação pelo conselho)].]" (`#a3232b`) / "Aguardando abertura pela mesa." / "A votação abre automaticamente em {data}." / "A votação ainda não foi aberta." | `e.msg` |
| Cartão da votação | Botões | "Conhecer candidaturas" (`bi-person-lines-fill`, abre modal); "Simular votação" (`bi-play-circle`, cabine em simulação, para qualquer status); "Votar" (`.vP`, `bi-check2-square`): no portal abre a cabine, desabilitado se não aberta, não apto, ou já votou sem poder alterar; no sistema abre o portal de teste da votação e só habilita se aberta | `e.nVotar` |
| Cartão da votação | Comprovante | "Comprovante em imagem" (`bi-image`) + campo `type=email` placeholder "E-mail para receber o comprovante" + "Enviar por e-mail" (`bi-envelope`) que abre `mailto:` com assunto "Comprovante de participação: {votação}" | `e.temCp` = já votou e tem cadastro |
| Comprovante por e-mail | Corpo do e-mail | "Comprovante de participação", "Votação: …", "{Entidade}: …", "Nome: …", "{rótulo id}: …", "Data e hora: {dd/mm/aaaa às hh:mm:ss}" ou "{data}, das HHh às HHh", "Localização no acesso: …" ou "Não coletada", "Código: …", "Este comprovante confirma somente a participação. O voto é secreto e não é informado aqui." | clique |
| Comprovante por e-mail | Erros | "Comprovante indisponível para este voto." / "Informe um e-mail válido." (toast vermelho) | clique |

## 4. Aba escondida Cadastro para votar (`aba:'hab'`, acesso por PIN)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título | `.vH` `bi-person-vcard` "Cadastro para votar"; corpo `padding:16px; gap:13px` | `abaHab` |
| Situação | Bloco | `background:#f7f9fb; border-left:4px solid {cor}`; ícone 26px; título 15.5px bold; detalhe 13px | `habTem` |
| Situação | Textos | "Cadastro em análise" + "{id}. Aguarde a conferência da comissão." (`bi-hourglass-split` `#0a6fa0`) / "Habilitado(a) para votar" ou "Identidade confirmada, aptidão pendente" + "{id}. Decisão em {data}." (`bi-check-circle-fill` `#1e7b45`) / "Cadastro recusado" + motivo ou "Procure a comissão e envie um novo cadastro." (`bi-x-circle-fill` `#a3232b`) | `hab.status` |
| Formulário | Campos (`.vG2`) | "Nome completo"; "CPF" (`inputmode=numeric`); `{rtId}`; "`{rtGr}` (se houver)"; "Crie um PIN (6 números ou mais)"; "Repita o PIN" (password, `new-password`); nome, identificação e grupo travados (`hfLock`) quando o nome foi localizado na lista | `habForm` = sem cadastro ou recusado |
| Formulário | Fotos (`.vKpis`) | por categoria: "Documento (frente)" / "Carteira profissional (frente)" (`cf`, `bi-person-vcard`), "Documento (verso)" (`cv`, `bi-credit-card-2-back`), comprovante de vínculo (`doc`, `bi-card-heading`, ex. "Titularidade ou procuração", "Comprovante de filiação"), "Selfie" (`sel`, `bi-person-bounding-box`, câmera frontal); com nome localizado só frente e selfie | `fotosCad` |
| Formulário | Análise da foto | ao escolher: "Analisando a imagem..." → "Qualidade aprovada." / motivo do problema (`.vFotoRuim`) / após 3 tentativas (se o motivo não for grave) "Enviada com ressalva para conferência humana. {motivo}" / "Não foi possível ler a imagem. Envie em JPG ou PNG."; foto reduzida a 640 px JPEG 0,68 | `qual['hf.x']` |
| Formulário | Consentimento | "Autorizo o tratamento dos meus dados, imagens e localização, inclusive dado biométrico, somente para confirmar minha identidade e aptidão nesta plataforma, nos termos da LGPD. Posso revogar esta autorização, sem prejuízo de meios alternativos de identificação." | sempre |
| Formulário | Aviso | `.vAviso`: com nome localizado "Seu nome consta na lista: você vota logo após enviar. A foto passa por verificação automática de luz e enquadramento, e a conferência da identidade é feita depois por uma pessoa." / sem "A foto passa por verificação automática de luz e enquadramento. A conferência da identidade é feita por uma pessoa." | `vLoc` |
| Formulário | Erros | "Os PINs não conferem." / "Envie todas as imagens solicitadas." / do núcleo | `habErro` |
| Formulário | Botão | `bi-send` "Enviar e votar" (nome na lista) ou "Enviar para análise"; toasts "Identificação concluída. Clique em Votar." / "Cadastro enviado para análise."; no portal já entra logado e volta à aba Votar | clique |
| Formulário | ⚠ | não há a declaração do art. 299 aqui (só no acesso por código) | n/a |

## 5. Cabine de votação (urna virtual)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Fundo | `.uBg` | `position:fixed; inset:0; z-index:2147483000; background:radial-gradient(circle at 50% 30%, #2d3640 0, #161b21 72%); padding:clamp(6px,2vw,22px)`; `animation:uEntra .45s` (fade); `role="application" aria-label="Cabine de votação"`; esconde selo/mini/fita do tema | `cabTem` (`state.cab` diferente de null) |
| Corpo | `.uBody` | `width:min(1060px,100%); background:linear-gradient(180deg,#e3e7ec 0,#d5dae1 60%,#c6ccd4 100%); border-radius:14px 14px 10px 10px; box-shadow:0 30px 80px rgba(0,0,0,.55)...`; `animation:uSobe .5s cubic-bezier(.2,.8,.2,1)` (sobe 26px e escala .98→1) | cabine |
| Autenticação | Caixa | `.uBody max-width:480px`; topo `.uTopo` "CABINE DE VOTAÇÃO" / "BLUEJUS" (12.5px bold `#3a3f3f` `letter-spacing:.5px`) | `cabAuth` (`fase:'auth'`) |
| Autenticação | Textos | "Confirme sua identidade" 21px; "{nome}, {id}" + "Depois daqui, ninguém vê sua escolha: seu voto é cifrado neste aparelho e separado do seu nome." | `cabAuth` |
| Autenticação | Campo e botões | "PIN pessoal" (password, `autofocus`, Enter entra); erro `#a3232b` 14px; "Voltar" e "Entrar na cabine" (`.vP flex:1`); som 392 Hz 0,1 s ao entrar | `cabErro` |
| Autenticação | Rodapé | `.uDemo` "Demonstração. Sem certificação de órgão eleitoral." (11.5px `#51575a`) | `cabAuth` |
| Autenticação | Quando pula | no portal já identificado, a cabine abre direto em `inicio` usando a sessão (`vSess`) | portal |
| Faixas | Simulação | `.uFaixa` (`background:#8a6200; color:#fff; 13px bold; letter-spacing:.3px`): "SIMULAÇÃO: nenhum voto é registrado" | `uSim` |
| Faixas | Voto aberto | `.uFaixa`: "VOTO ABERTO: seu nome e seu voto ficarão visíveis" | `uAberto` (sigilo diferente de secreto) |
| Arranjo | `.uGr` | `grid-template-columns:minmax(0,1.62fr) minmax(260px,1fr); gap:clamp(12px,3vw,44px)`; 1 coluna até 760px | `cabUrna` |
| Tela | `.uMold` + `.uTela` | moldura `#cdd3da` com sombra interna; tela `background:#fff; min-height:clamp(330px,56vh,470px); padding:clamp(14px,2.4vw,26px); color:#111; Arial` | `cabUrna` |
| Tela: início | Conteúdo | logo `bj-voto-logo.png` `width:min(68%,400px)`; nome da votação `clamp(19px,2.8vw,27px)`; "Aperte **CONFIRMA** para iniciar" (CONFIRMA `#149345`); botão "Conhecer candidaturas" | `uInicio` (`fase:'inicio'`) |
| Tela: votando | Cargo | "SEU VOTO PARA" `clamp(14px,1.8vw,17px)` bold; nome do cargo `clamp(23px,3.4vw,35px)` | `uVota` (`fase:'urna'`) |
| Tela: votando | Número | "Número:" + caixas `.uNum` (`width:clamp(42px,6vw,56px); height:clamp(54px,8vw,72px); border:2px solid #111; font:bold clamp(30px,5vw,44px)`; `animation:uDig .16s` ao digitar); caixa atual pisca `.uCur` (`uPisca 1s steps(2)`, `#d7e6ef`); quantidade de caixas = dígitos do cargo (1 a 5) | `uMostraNum` (não branco) |
| Tela: votando | Branco | "VOTO EM BRANCO" ou, em pauta, "ABSTENÇÃO" (`clamp(30px,5vw,52px)` bold) | `uBranco` |
| Tela: votando | Nulo | "NÚMERO ERRADO" + "VOTO NULO" | `uNulo` (dígitos completos sem candidatura) |
| Tela: votando | Candidatura achada | nome `clamp(19px,2.8vw,28px)` bold; descrição; composição da chapa (até 4 linhas, `pre-line`) | `uAchou` |
| Tela: votando | Foto | `.uFoto` `width:clamp(92px,15vw,146px); aspect-ratio:3/4; background:#dfe3e2; border:1px solid #666`; sem foto: iniciais (bold 34px `#4b5a63`); não aparece em pauta | `uFotoVis` |
| Tela: votando | Rodapé de confirmação | `.uRod` (`border-top:2px solid #111`): "Aperte a tecla:" / "**CONFIRMA** para CONFIRMAR este voto" (`#1d7f42`) / "**CORRIGE** para REINICIAR este voto" (`#c75e12`) | `uRodape` (número completo ou branco) |
| Tela: votando | Rodapé de dica | em pauta, linha "1 = Sim     2 = Não" (opções); "Digite o número no teclado ou aperte **BRANCO**[ para se abster]." + botão "Conhecer candidaturas" | `uDica` / `uOpcT` |
| Tela: gravando | Conteúdo | `.uFim`: "Protegendo seu voto" / "Simulando o registro"; barra indeterminada `.uProg` (64%, 9px, `#d5d2c6`, faixa `#1e7b45` `uBarra 1.1s infinite`); "Cifrando a cédula e separando-a da sua identidade" / "Nada é cifrado nem enviado nesta simulação"; mínimo 1,1 s | `uGrava` (`fase:'grava'`) |
| Tela: fim | Conteúdo | anel SVG `.uAnel` (círculo `stroke:#1e7b45` 7px desenhado em .9s, visto em .4s após .75s); "FIM" `clamp(54px,10vw,92px)`; "Voto registrado" / "Simulação concluída" verde | `uFim` (`fase:'fim'`) |
| Tela: fim | Comprovante | "Comprovante de depósito da cédula (não revela seu voto)" + código mono `clamp(15px,2.2vw,20px)` (`formataComprovante`, prefixo BJV); botão "Comprovante em imagem" | `uReal` (não simulação) |
| Tela: fim | Simulação | "Nenhum voto foi registrado." + "Simular novamente" | `uSim` |
| Tela: fim | Próxima do evento | "Próxima votação" (`.vP`) + "Pendente: {nome}" (outra votação aberta do mesmo evento em que é elegível e não votou); "Concluir" vira primário quando não há próxima | `uProx` |
| Tela: erro | Conteúdo | `bi-exclamation-octagon` 54px `#a3232b`; "Voto não registrado"; mensagem do núcleo; "Voltar" | `uErro` (`fase:'erro'`) |
| Tela: candidaturas | Sobreposição | "Candidaturas" + "Voltar"; por cargo, cartões `.uCard` (avatar 56px, "{num} · {nome}", descrição, propostas, composição); ordem sorteada se `ordem:'sorteada'`; teclado bloqueado | `uVer` (`cab.ver`) |
| Lado direito | Placa | `.uPlaca` `#eef1f4` borda `#b9c0c9`: símbolo `bj-voto-simbolo.png` `clamp(38px,5vw,50px)` `mix-blend-mode:multiply`; "BLUEJUS<br>VOTO" `clamp(16px,2vw,22px)`; botão de som (`bi-volume-up`/`bi-volume-mute`, aria "Desligar sons"/"Ligar sons") | `state.som` inicia `true` |
| Lado direito | Teclado | `.uTec` `background:#15171a; grid 3 colunas`; teclas `.uT` `min-height:clamp(40px,6.2vh,54px); background:linear-gradient(180deg,#2b2e32,#0c0d0f); color:#f4f4f4; font:bold clamp(19px,2.6vw,24px) Arial; box-shadow:0 3px 0 #000`; ordem 1 a 9 e 0 na coluna 2; cada tecla com o caractere braille (`⠁ ⠃ ⠉ ⠙ ⠑ ⠋ ⠛ ⠓ ⠊ ⠚`, `.uB6` .82em `#8b9198`); aria "Número {n}" | `teclas` |
| Lado direito | Tecla pressionada | `.uT:active, .uPr { transform:translateY(3px); box-shadow:0 0 0 #000 }`; `.uPr` aplicado por 110 ms também no teclado físico | `state.pr` |
| Lado direito | Teclas de ação | `.uAc` 3 colunas: "BRANCO" (`.uBr` branco `#fff→#e2e2e2`), "CORRIGE" (`.uCo` laranja `#f0853a→#dd6a18`), "CONFIRMA" (`.uCf` verde `#25b65b→#149345`, mais alta `clamp(52px,8vh,64px)`); texto `clamp(11px,1.35vw,13px)` `#16181a` | cabine |
| Regras do teclado | BRANCO | com dígitos digitados: recusa (som grave 196 Hz); sem dígitos: marca branco | `fase:'urna'` |
| Regras do teclado | Dígitos | ignorados depois de completar o número ou com branco marcado | `fase:'urna'` |
| Regras do teclado | CORRIGE | limpa dígitos e branco do cargo atual | `fase:'urna'` |
| Regras do teclado | CONFIRMA | no início: entra na urna; número incompleto: recusa (196 Hz); vários cargos: passa ao próximo; último: grava | `fase` |
| Teclado físico | Teclas | `0` a `9` dígito; `Enter` confirma (também no início); `Backspace`/`Delete` corrige; `b`/`B` branco; nada funciona com "Candidaturas" aberta | `keydown` na janela |
| LEDs | `.uFen` | 8 frestas 7x18px `#6f7780`; ao confirmar acendem em ordem aleatória `#3dff8f` com brilho, a cada 105 ms por 240 ms; somem até 760px | confirmar |
| Sons (WebAudio, onda senoidal) | Digitar / branco aceito | 440 Hz, 0,06 s, ganho 0,05 | `som` ligado |
| Sons | Recusa (branco com dígitos, confirmar incompleto) | 196 Hz, 0,13 s, ganho 0,05 | idem |
| Sons | Corrige | 294 Hz, 0,09 s, ganho 0,06 | idem |
| Sons | Iniciar e próximo cargo | 392 Hz 0,09 s + 494 Hz 0,13 s | idem |
| Sons | Voto gravado (real e simulação) | 392 Hz 0,2 s + 494 Hz 0,2 s + 587 Hz 0,34 s, ganho 0,08 | idem |
| Sons | Erro ao gravar | 196 Hz 0,22 s, ganho 0,07 | idem |
| Sons | Entrar na cabine (PIN aceito) | 392 Hz 0,1 s | idem |
| Leitor de tela | `.uSr aria-live="assertive"` | "Voto para {cargo}. Digite o número."; "Voto em branco. Confirme ou corrija."; "Número errado, voto nulo. Confirme ou corrija."; "{nome}. Confirme ou corrija."; "Simulação concluída. Nenhum voto foi registrado."; "Voto registrado. Fim." | `uFala` |
| Rodapé | `.uDemo` | "Equipamento virtual BlueJus, inspirado na familiaridade da urna brasileira. Demonstração, sem vínculo com a Justiça Eleitoral. Teclado físico: números, B, Backspace e Enter." | `cabUrna` |
| Animações | Lista | `uEntra .45s`, `uSobe .5s`, `uDig .16s`, `uPisca 1s steps(2) infinite`, `uSur .24s` (`.uFade`, sobe 6px), `.uFim uSur .3s`, `uRing .9s` + `.4s .75s`, `uBarra 1.1s infinite`; `.uT transition:transform .06s, box-shadow .06s`; LED `transition:background .2s` | cabine |
| Concluir | Botão | "Concluir" fecha a cabine (`cab:null`); ⚠ não encerra a sessão do eleitor, nem no terminal presencial | `uFim` |

## 6. Modais

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Candidaturas | Modal | `.vMod` + `.vModC` 720px; `role="dialog" aria-modal="true"`; `.vH` `bi-person-lines-fill` "{nome da votação}" + "Fechar"; por cargo, título 15px `#0b3550`; linhas com avatar (foto ou iniciais), "{num} · {nome}" 14.5px, descrição e propostas 13px `#34505f`, composição 12.5px `pre-line`; ordem sorteada a cada abertura se `ordem:'sorteada'` | `modTem` (`state.mod`) |
| Link e QR Code | Modal | caixa `width:min(520px,100%)`; `.vH` `bi-qr-code` "Link e QR Code" + "Fechar"; nome 15.5px; "Cada pessoa vota do próprio celular, sem conta no BlueJus: aponta a câmera, localiza o nome e confirma a identidade." | `lnkTem` (`state.lnk`) |
| Link e QR Code | QR | `img#vQr` `width:min(280px,100%); aspect-ratio:1`, gerado em canvas 640 px, módulos `#0b3550`, margem de 4 módulos, correção M; alt "QR Code de acesso à eleição" | `lnkTem` |
| Link e QR Code | Campo | "Link de acesso" `.vMono readonly`, seleciona tudo ao focar | `lnkTem` |
| Link e QR Code | Código | "Código de conferência: **{XXXX-XXXX}**" | `lnkTem` |
| Link e QR Code | Botões | "Copiar link" (`.vP`, toast "Link copiado."; sem área de transferência, toast azul com o link); "Baixar cartaz com QR Code" (PNG); "Testar acesso" (abre o portal de teste); "Abrir terminal presencial" (portal de teste + chip "Terminal presencial", acessos gravados com canal `presencial`) | `lnkTem` |
| Link e QR Code | Aviso | `.vAviso` "O QR Code leva à votação, mas não libera o voto: a identificação continua exigida. Nesta demonstração o endereço ainda não abre em outro aparelho; use Testar acesso." | `lnkTem` |
| Link e QR Code | Registro | primeira abertura de cada link grava na trilha "Link e QR Code gerados"; erro "Gerador de QR Code indisponível. Recarregue a página." | abrir |
| Cartaz (PNG 1000x1414) | Conteúdo | faixa `#0a6fa0` 170px "Vote pelo celular" bold 72px branco; nome bold 40px `#0b3550` (cortado com "..."); QR 720 px; "Aponte a câmera para o código" bold 42px; "Não precisa ter conta no BlueJus" bold 34px `#1e7b45`; "1. Localize seu nome   2. Confirme sua identidade   3. Vote" 30px; endereço 26px; "Confira o código: {cod}" bold 32px; "Ambiente demonstrativo. Sem certificação de órgão eleitoral." 24px `#8a6200`; arquivo `cartaz-qrcode-{id}.png` | baixar |

## 7. Aba Resultados (`aba:'tra'`; mensagens chamam de "Transparência")

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão por votação | Cabeçalho | `.vH` `bi-shield-check` "{nome}" + chip `background:rgba(255,255,255,.2)` com status | `transp`: publicada, aberta, encerrada, apurada (eleitor vê só as suas) |
| Resultado resumido | Faixa | `padding:12px 14px; background:#e8f4ec; border-left:4px solid #1e7b45; 16px bold`: "{cargo}: {num} {nome} · ..." ou situação | `t.resT` (apurada) |
| Voto aberto | Lista | "Votos nominais" 14px; linhas "{nome} · {id}", voto em negrito, data com segundos | `t.nomTem` |
| Indicadores | 3 KPIs | "Cédulas na urna"; "Participações registradas"; "Guardiões para apurar" ("{k} de {n}" ou "Definidos na abertura") | sempre |
| Registro | Linhas | "**Boletim de abertura:** {data}, urna com 0 cédulas" ou "Emitido na abertura"; "**Código da urna agora:** {hash 32}" ou "Gerado na abertura"; "**Configuração registrada:** {hash 32}" (mono) | sempre |
| Conferir comprovante | Campo | "Confira seu comprovante" `.vMono` placeholder "BJV-... ou BJC-..." | sempre |
| Conferir comprovante | Mensagens | BJC: "Comprovante de participação válido." / "Comprovante de participação não localizado."; BJV: "Comprovante localizado na urna. Posição {n}." / "Comprovante não localizado." | `t.msg` |
| Botões | Lista | "Conferir" (`bi-search`); "Verificar urna" (`bi-patch-check`, desabilitado antes da abertura) → "Urna íntegra: {n} cédula(s), cadeia e assinaturas conferidas." / "Divergência: {problema}"; "Pacote público" (`bi-download`, JSON `pacote-publico-{id}.json`, só apurada); "Boletim de abertura" (PDF, após abrir); "Boletim de apuração" (PDF, apurada); "Boletim de urna (impressão)" (`bi-printer`, apurada) | `t.nAud`, `t.nRes`, `t.nBolA` |
| Resultado detalhado | Barras | por cargo: título 15px `#0b3550`; linha "**{num}** {nome}" (`min(46%,300px)`), barra 15px `#0a6fa0` sobre `#e6edf2` (% do peso), valor 128px ("{votos}" ou "{votos} un · {peso}"); "Brancos {n} · Nulos {n}" (pauta: "Abstenções"); "Resultado: {num} {nome}" verde ou situação em vermelho | `t.temRes` |
| Verificador independente | Cartão | "Verificador independente" 15px; "Envie um pacote público (.json). O verificador refaz a cadeia, confere as assinaturas, decifra as cédulas e refaz a contagem sem consultar o sistema."; botão-rótulo "Verificar pacote" (`bi-file-earmark-check`, `accept=".json"`) | sempre na aba |
| Verificador independente | Mensagens | "Pacote confere: {n} cédula(s) recontadas, resultado igual ao publicado." / "Divergência: {problema}" / "Arquivo inválido." | `pacMsg` |
| PDF Boletim de abertura | Conteúdo | título "BOLETIM DE ABERTURA", código BJB-XXXX-XXXX-XXXX, QR do link; Eleição, Grupos ("Todos"), Período, Hash da configuração, Início da cadeia, Guardiões "{k} de {n}", Lista de aptos e hash, Abertura, "Cédulas na urna: 0 (urna vazia)", Participações 0; assinaturas da categoria (ex. "Presidência da assembleia" e "Secretaria da assembleia") | botão |
| PDF Boletim de apuração | Conteúdo | "BOLETIM DE APURAÇÃO"; abertura, encerramento, apuração, cédulas apuradas, participações, inválidas, cabeça da cadeia; tabela por cargo com "Votos em branco", "Votos nulos", "Total" | botão |
| Boletim de urna (impressão) | Conteúdo | formato de boletim de urna: código BJU-, `{grupo}`, "Local de votação: On-line", "Eleitores aptos", "Comparecimento", "Eleitores faltosos" (4 dígitos), "Código identificação", datas e horários de abertura e fechamento; por cargo em CAIXA ALTA: nome, número, votos; "Total de votos nominais", "Brancos", "Nulos", "Total apurado"; códigos de resumo e verificação | botão |

## 8. Aba Acompanhar (`aba:'aco'`, só Master fora do portal)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título e texto | `.vH` `bi-broadcast` "Acompanhar em tempo real"; "Clique em Assistir para abrir uma janela própria, que pode ser levada a outra tela. Assistir mostra participação e quórum e pode ser projetado. Lista nominal e Documento de auditoria são restritos à comissão, à auditoria e ao Gestor Master; nunca indicam o voto." | `abaAco` |
| Lista | Vazio | "Nenhuma votação publicada." | `acoSem` |
| Lista | Linha | nome 14.5px; "{entidade} · {n} de {aptos} votaram"; chip; "Assistir" (`.vP`, `bi-box-arrow-up-right`); "Lista nominal" (`bi-people`) e "Documento de auditoria" (`bi-file-earmark-pdf`) só para Comissão, Auditoria e Gestor Master; abertas primeiro | `a.nomPode` |
| Janela Assistir | Pop-up | `window.open` 620x560 "Ao vivo: {nome}"; CSS próprio: cabeçalho `#0a6fa0` com nome, entidade e status; 3 números 40px `#0a6fa0` ("votaram", "aptos", "{pc}% de participação" ou "do peso apto"); barra 18px `#dfe9f0` com marca de quórum `#a3232b`; "Quórum atingido: {q}%" (`#1e7b45`) ou "Quórum exigido: {q}%" (`#8a6200`); cronômetro; no voto aberto em tempo real, lista nome e voto; "Atualizado às {hh:mm:ss}. Os votos permanecem cifrados: o resultado só existe depois da apuração." (ou "Votação com voto aberto."); atualiza a cada 1 s | clique |
| Janela Lista nominal | Pop-up | 1060x760; "Lista nominal restrita. Não indica o voto."; botão "Gerar documento de auditoria"; "{n} votaram de {aptos} aptos"; cartões (`minmax(310px,1fr)`) com fotos do documento e selfie (130px), nome, CPF mascarado `***.xxx.xxx-**` · grupo, "Identificação: {data com segundos}", "Voto na faixa: {dd/mm/aaaa}, das HHh às HHh", "Localização: {lat, lon (precisão de N m)}" ou "Não autorizada pelo votante" / "Não coletada" / "Indisponível no aparelho", selo "Presencial"/"Virtual" | clique |
| Pop-up bloqueado | Alternativa | caixa flutuante arrastável no canto inferior direito, `z-index:9999`: "Janela bloqueada pelo navegador: libere pop-ups para abrir separada" + botão "X" | navegador bloqueia |
| PDF Documento de auditoria | Conteúdo | "LISTA DE VOTANTES PARA AUDITORIA", código BJA-; Aptos, Votaram, Presencial, Virtual, Emitido em/por; "Documento restrito, com dados pessoais e imagens de documentos. Circulação limitada à comissão e à auditoria, nos termos da LGPD."; "Este documento não indica em quem cada pessoa votou. O horário é o da identificação; o voto consta apenas por faixa horária, para preservar o sigilo. A localização é a informada pelo aparelho."; fichas com imagens, CPF completo, faixa, localização, forma, comprovante; toast "Documento de auditoria gerado." | botão |

## 9. Aba Gestão eleitoral (`aba:'ges'`, só Master fora do portal)

### 9.1 Barra de contexto e sub-abas

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Barra | Botão | "Criar eleição" (`.vP`, `bi-plus-lg`): passa a atuar como Comissão e abre o assistente | sempre |
| Barra | "Votação" | `select` com "Equipe padrão (votações sem equipe própria)" e cada evento ou votação (escopo de equipe) | `state.ctx` |
| Barra | "Atuando como" | `select` "{cargo ou papel}: {nome}" dos perfis do contexto; inicial `p-admin` (Administração) | `state.atua` |
| Barra | Ícones | "+" (aria e title "Adicionar cargo") e lápis ("Editar cargos"): os dois levam a Perfis ⚠ mesma ação | sempre |
| Barra | Nota | "Seleção apenas para demonstração. No sistema real, cada pessoa entra com a própria conta e vê somente o que o seu perfil permite." (12.5px `#5b7383`) | protótipo |
| Sub-abas | Ordem | "Estatísticas" (`bi-bar-chart-line`, `es`), "Perfis" (`bi-people`, `pe`), "Painel" (`bi-speedometer2`, `pa`), "Criar eleição" (`bi-plus-square`, `el`), "Lista de aptos" (`bi-list-check`, `ls`), "Eleitores" (`bi-person-check`, `et`), "Avaliações" (`bi-clipboard-check`, `av`), "Apuração" (`bi-calculator`, `ap`), "Auditoria" (`bi-journal-check`, `au`), "Testes" (`bi-bug`, `te`) | `state.sub` inicial `'pa'` |
| Sub-abas | Visíveis por papel (`VIS`) | Gestor Master: es, pa, au · Administração: pe, pa · Conselho Eleitoral: av, pa · Comissão Eleitoral: pa, el, ls, ap · Validação: et · Guardião: ap · Auditoria: pa, au, te · Fiscal: pa, au · outro: pa; sub-aba fora da lista cai na primeira do papel | `perfil().papel` |

### 9.2 Painel (`sub:'pa'`)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Indicadores | 5 KPIs | "Eleitores habilitados", "Em análise", "Votações abertas", "Cédulas depositadas", "Incidentes" (sobre todas as votações, não só o contexto) | `subPa` |
| Eventos | Cartão | `.vH` `bi-collection` "Eventos com mais de uma votação"; linha nome, "{entidade} · {n} votações"; "Link e QR Code do evento" (Comissão); "Relatório consolidado" (`.vP`, Comissão/Auditoria/Fiscal, se alguma apurada) | `temEv` |
| Votações | Cartão | `.vH` `bi-activity` "Votações"; por votação do contexto: nome + chip | `gesEle` |
| Votações | Passos | pílulas "1. Criar", "2. Lista de aptos", "3. Publicar", "4. Votação", "5. Apuração", "6. Relatório" (feito/atual) | por status |
| Votações | Próximo passo | `bi-arrow-right-circle` + texto: "Importe a lista de quem pode votar." [botão "Importar lista" → assistente etapa 5] / "Confira a configuração e clique em Publicar." / "Divulgue o QR Code. A mesa abre a votação abaixo, quando decidir." / "Divulgue o QR Code. A votação abre sozinha em {data}." / "Divulgue o QR Code e abra a votação." [botão "Mostrar QR Code"] / "Votação em andamento." [botão "Acompanhar em tempo real"] / "Cada guardião apresenta sua parte ({n} de {k}) em Apuração. Depois, a Comissão abre as cédulas." [botão "Ir para Apuração"] / "Apurada. Emita o relatório consolidado para a ata e o cartório."; botão só para Comissão | `e.temIr` |
| Votações | Participação | barra 9px `#1e7b45` sobre `#e6edf2`; "Participação {n} de {aptos} aptos ({pct}%). {Conduzida pela mesa. / Automática: abre {data}, encerra {data}. / Abertura manual.}" | sempre |
| Votações | Botões por permissão | "Relação de votantes" (`bi-people`; com relação nominal ligada, Comissão/Auditoria/Fiscal, encerrada ou apurada); "Relatório consolidado" (`.vP`, apurada, Comissão/Auditoria/Fiscal); "Documento de auditoria" (aberta/encerrada/apurada, Comissão/Auditoria/Gestor Master); "Link e QR Code" (Comissão, não rascunho); "Editar" (Comissão, rascunho); "Publicar" (`.vP`, Comissão, rascunho); "Abrir votação" (`.vP`, Comissão, publicada, abertura manual sem mesa) | `e.pode.*` |
| Mesa: abrir | Controles | `select` "1ª convocação"/"2ª convocação" (150px); "Minutos" (82px, padrão 5) + "minutos"; "Abrir por minutos" (`.vP`, `bi-stopwatch`); "Abrir sem prazo" (`bi-unlock`); toast "Votação aberta pela mesa." | `e.pode.mAb` (Comissão, mesa, publicada) |
| Mesa: aberta | Cronômetro e prazo | "Encerra em mm:ss" ou "Aberta, sem prazo definido" (14px bold `#1e7b45`, 150px); "Minutos" + "Definir prazo" (toast "Prazo definido.") + "Sem prazo" (toast "Prazo retirado.") | `e.pode.mCt` |
| Mesa: sessão permanente | Controles | "Próxima sessão" `datetime-local` 210px; `select` "Fechada até a próxima sessão" / "Votação continua aberta" (230px); "Sessão permanente" (`bi-pause-circle`); toast "Sessão permanente registrada." | `e.pode.mCt` |
| Mesa: suspensa | Retomar | "Sessão suspensa até {data}" (`#8a6200`) + "Retomar sessão" (`.vP`, `bi-play-circle`); toast "Sessão retomada." | `e.pode.ret` |
| Mesa | Ata parcial | "Ata parcial" (`bi-journal-text`, gera o relatório consolidado com a votação aberta) | `e.pode.parc` (sessão permanente, aberta, Comissão/Auditoria/Fiscal) |
| Encerrar | Controles | campo "Motivo do encerramento antecipado" + "Encerrar" (`.vD`, `bi-lock`); motivo padrão na mesa "Determinação da mesa"; toast "Votação encerrada." | `e.pode.enc` (Comissão, aberta) |
| Apurar | Botão | "Iniciar apuração" (`.vP`, `bi-calculator`) → sub-aba Apuração | `e.pode.apu` (Comissão, encerrada) |
| Publicar | Bloqueios | "Antes de publicar, importe a lista de aptos." / "Antes de publicar, designe ao menos 3 guardiões na equipe desta votação." / "O início já passou e a votação abriria imediatamente. Ajuste o horário ou escolha Conduzidos pela mesa."; sucesso "Votação publicada. A configuração ficou bloqueada." | publicar |
| Abrir | Regra | abre com 5 guardiões e mínimo 3 (`{ n: 5, k: 3 }`); toast "Votação aberta. Boletim de abertura emitido com urna vazia." | abrir |
| Incidentes | Cartão | `.vH` `background:#8a6200` `bi-exclamation-triangle` "Incidentes registrados"; vazio "Nenhum incidente registrado."; últimas 12 (tipo + data) | `semInc` |
| PDF Relação de votantes | Conteúdo | "RELAÇÃO DE VOTANTES", código BJL-; Aptos, Votaram, Não votaram, Emitida em; "Esta relação informa quem participou da votação. Não indica em quem cada pessoa votou."; tabela Nome, {rótulo id} (mascarado), Situação ("Votou HHh às HHh" / "Não votou" / "Inapto") | botão |
| PDF Relatório consolidado | Seções (renumeradas) | Resumo da votação; Identificação; Responsáveis designados; por votação: Boletim de abertura (ou sessões permanentes), Eleitorado e participação (com participação por grupo), Encerramento, Apuração (critérios, quórum "Atingido/Não atingido", resultado), Relação de votantes; Ocorrências e avaliações de aptidão; Voto aberto (com "Conferência com a urna: ..."); Autenticidade e registro em cartório (endereço oficial, código divulgado, "Impressão digital do programa (SHA-256)"); Anexo trilha (até 150); Incidentes; Termo de encerramento ("Os signatários declaram ter examinado o conteúdo deste relatório."); código BJR- | botão |

### 9.3 Estatísticas (`sub:'es'`, Gestor Master)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título | `.vH` `bi-bar-chart-line` "Estatísticas de todas as votações" + "Exportar CSV" | `subEs` |
| Filtro | "Categoria" | `select` "Todas" + 9 categorias (320px) | `state.esCen` |
| Nota | Texto | "Somente números agregados. O perfil não opera votações e não tem acesso ao voto de nenhuma pessoa." | sempre |
| Indicadores | 11 KPIs | "Votações", "Realizadas", "Em andamento", "Aptos nas realizadas", "Votantes nas realizadas", "Participação nas realizadas", "Quórum atingido" ("{x} de {y}"), "Ocorrências", "Avaliações pendentes", "Inaptidões mantidas", "Tornados aptos" | `esK` |
| Lista | Linha / vazio | nome; "{entidade} · {categoria} · até {data}"; "{n} de {aptos} votaram ({pc}) · Quórum: {Atingido/Não atingido/Não exigido} · {n} ocorrência(s)"; chip; vazio "Nenhuma votação nesta categoria." | `esSem` |
| CSV | Colunas | `estatisticas-votacoes.csv` com BOM: `votacao;entidade;categoria;situacao;encerramento;aptos;votantes;participacao;quorum;ocorrencias;pendentes;mantidas;tornados_aptos` | botão |

### 9.4 Perfis (`sub:'pe'`, Administração)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título e texto | `.vH` `bi-people` "Perfis por assembleia ou votação"; "Cada votação pode ter a sua própria equipe, com os cargos da entidade. Em cada votação, a pessoa tem um único perfil. A administração designa os perfis, mas não opera a eleição." | `subPe` |
| Acordeão | Grupo | `.vPerG`: `bi-chevron-right/down`; "Equipe padrão (votações sem equipe própria)" e cada evento ou votação; contagem "{n} representante(s)" ou "Sem equipe própria" | `perAb` |
| Grupo aberto | Equipe padrão | "Equipe padrão. Para montar a equipe própria, escolha a votação no campo acima." (`#8a6200`) | `ctxSem` |
| Grupo aberto | Cargos prontos | `select` "Categoria com cargos prontos" + "Aplicar cargos da categoria" (`.vP`, `bi-magic`); toast "Cargos da categoria aplicados. Indique o nome e o e-mail de cada pessoa." | `ctxTem` |
| Grupo aberto | Linha | nome, e-mail, chip `#0a6fa0` com cargo (title = permissão), lápis "Editar", lixeira "Remover" (`.vD`, desabilitada para Administração); toast "Perfil removido." | `p.ver` |
| Grupo aberto | Edição em linha | campos "Nome", "E-mail", "Cargo" + "Salvar" (`.vP`) e "Cancelar"; toast "Perfil alterado." | `p.ed` |
| Grupo aberto | Designar | "Nome", "E-mail", "Cargo" (placeholder "Ex.: Presidência da mesa"), "Permissões" (`select`: "Comissão Eleitoral", "Gestor Eleitoral Master (todas as votações)", "Conselho Eleitoral", "Validação", "Guardião", "Auditoria", "Fiscal") + "Designar" (`.vP`); toast "Perfil designado." | sempre |
| Equipes prontas por categoria | Cargos | comissão, validação, conselho, 5 guardiões (ex. "Escrutinador 1..5" no condomínio, "Guardião 1..5" na entidade de classe), auditoria, fiscal (lista completa em `bj-voto-cenarios.js`, `EQ`) | aplicar |

### 9.5 Criar eleição (`sub:'el'`, Comissão): assistente de 7 etapas

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Fotos das candidaturas | Cartão | `.vH` `bi-camera` "Fotos das candidaturas"; "Votações publicadas aceitam foto até a abertura. Depois de aberta, a foto fica bloqueada. A foto aparece na urna, ao digitar o número."; avatar 56px com "Enviar foto"/"Trocar foto", nome e cargo; foto 480 px; toast "Foto salva." | `fotPubTem` (Comissão, sem rascunho aberto, publicada que não seja pauta nem consulta) |
| Cartão | Título | `.vH` `bi-plus-square` "Criar eleição" ou "Rascunho: {nome ou 'sem nome'}" | `subEl` |
| Sem rascunho | Texto + botão | "Um assistente conduz a criação em 7 etapas. Você pode parar e continuar depois: cada etapa fica salva como rascunho." + "Criar eleição" | `edSem` |
| Etapas | Pílulas clicáveis | "1. Categoria", "2. Regras e prazo", "3. Acesso", "4. Cargos", "5. Lista de aptos", "6. Equipe", "7. Revisar" (13px, `padding:6px 10px`); avançar valida as anteriores; cada troca salva o rascunho | `edP` 1 a 7 |
| Etapas | Título e subtítulo | 1 "Que tipo de votação você vai criar?" / "A categoria já traz regras, documentos e cargos prontos. Tudo pode ser ajustado."; 2 "Regras e prazo" / "Defina como o voto funciona e quando a votação acontece."; 3 "Como o votante entra" / "A configuração sugerida atende à maioria dos casos."; 4 "Cargos e candidaturas" / "Inclua cargos, pautas ou perguntas e suas opções."; 5 "Quem pode votar" / "Importe a relação de quem está apto."; 6 "Quem conduz a votação" / "Escolha quem vai operar, apurar e fiscalizar."; 7 "Confira e publique" / "Depois de publicada, a configuração fica bloqueada." (18px `#0b3550` / 13.5px) | `edStT`, `edStD` |
| Novo rascunho | Padrões | categoria "Assembleia de condomínio"; início amanhã 08:00; fim 10 h depois; evento herdado do contexto | `novoEd` |
| Etapa 1 | Categorias (`.vCats`) | "Assembleia de condomínio" (`bi-building`), "Entidade de classe ou conselho" (`bi-award`), "Associação ou clube" (`bi-people`), "Sindicato" (`bi-megaphone`), "Cooperativa" (`bi-diagram-3`), "Sócios ou acionistas" (`bi-briefcase`), "Instituição de ensino" (`bi-mortarboard`), "Consulta interna" (`bi-chat-square-text`), "Personalizado" (`bi-sliders`) | `edP1` |
| Etapa 1 | Ajuda | `.vAviso` da categoria (ex. "Votos por fração ideal, salvo disposição diversa da convenção. Confira na convenção o quórum de cada pauta, a segunda convocação e quem está apto a votar.") | `edAjuda` |
| Etapa 1 | Campos | `{rótulo da entidade}` (ex. "Condomínio", "Sindicato"); "Evento (use o mesmo nome para agrupar votações)" (travado com equipe própria); "Nome da votação" (linha inteira); "Descrição" (textarea); "Finalidade" | `edP1` |
| Etapa 2 | Campos | "Modalidade": "Eleição por chapa" / "Eleição individual" / "Pautas de assembleia" / "Consulta" / "Votação institucional"; "Peso do voto": "Um voto por eleitor" / "Com peso da lista (fração, quotas)"; "Abertura e encerramento": "Programados no horário" / "Conduzidos pela mesa"; "Sigilo do voto": "Secreto" / "Aberto: nominal, visível após o encerramento" / "Aberto: nominal, visível em tempo real" | `edP2` |
| Etapa 2 | Condicionais | "Alterar voto em sessão permanente": "Permitir até o encerramento" / "Não permitir" (só mesa); aviso "No voto aberto, o nome e o voto de cada pessoa ficam visíveis. Use somente quando o estatuto, a convenção ou o regulamento permitir. O eleitor é avisado antes de votar." (sigilo aberto); seção "Período" com "Início" e "Encerramento" `datetime-local` (só horário) | `edMesa`, `edSigAv`, `edHor` |
| Etapa 2 | Quem participa | seção "Quem participa"; "Grupos aptos (separados por vírgula; vazio para todos)"; "Ordem na lista de candidaturas": "Ordem de registro" / "Sorteada a cada visualização" | `edP2` |
| Etapa 3 | Campos | "Acesso do eleitor": "Cadastro com análise e PIN" / "Identificação e código por e-mail"; "No acesso por código, identificar por": "CPF" / "Número da lista (inscrição, matrícula)" / "E-mail" (só código); "Consulta de situação pelo votante": "Nome completo e identificação" / "Somente nome completo"; "Localização do votante": "Solicitar, sem impedir o acesso" / "Obrigatória para entrar" / "Não coletar"; "Relação nominal de quem votou": "Gerar após o encerramento" / "Não gerar" | `edP3`, `edTok` |
| Etapa 4 | Atalhos | "Cargos e pautas frequentes:" + botões "+ {nome}" da categoria (ex. "Síndico(a)", "Aprovação de contas", "Diretoria") | `edModTem` |
| Etapa 4 | Bloco do cargo | `border:1px solid #d3dde4; border-left:4px solid #0a6fa0; padding:12px`; "Cargo, pauta ou pergunta"; "Dígitos" 1 a 5 (96px); "Critério": "Mais votos" / "Maioria absoluta" / "Dois terços" / "Unanimidade"; "Calculado sobre": "Votos válidos" / "Presentes" / "Total de aptos"; "Quórum (%)" (100px, 0 a 100); "Quórum 2ª conv." (112px, vazio aceito); "Candidatura" (`bi-person-plus`); lixeira "Remover cargo" | `edCargos` |
| Etapa 4 | Candidatura | fundo `#f7f9fb`, `padding:9px`; foto 56px "Enviar foto"/"Trocar foto"; grade `90px minmax(0,1fr)`: "Nº", "Nome da chapa ou candidatura", "Descrição", "Propostas" (textarea), "Composição da chapa (um cargo por linha, ex.: Presidente: Nome)" (textarea); "Subir", "Descer", "Remover" | `g.cands` |
| Etapa 4 | Botão | "Outro cargo ou pauta" (`bi-plus-lg`) | `edP4` |
| Etapa 5 | Lista | "Envie planilha XLSX ou CSV. Quem não consta na lista não vê a votação."; "Importar lista"/"Substituir lista" (`.vP`, `bi-upload`, `.csv,.txt,.xlsx`) + "Baixar modelo"; situação: "{n} aptos e {m} não aptos importados." (verde) / "Lista obrigatória nesta votação." (vermelho) / "Lista opcional. Sem ela, vale a análise de cada cadastro." (cinza) | `edP5` |
| Etapa 6 | Equipe | 2 ladrilhos (`minmax(240px,1fr)`): "Usar a equipe padrão" (`bi-people`) / "Equipe própria com os cargos desta categoria" (`bi-person-badge`); com equipe própria: lista cargo e "A designar" ou "{nome} · {e-mail}"; "A Administração indica o nome e o e-mail de cada cargo." (`#8a6200`) + "Indicar pessoas" (`bi-person-gear`); toasts "Votação usará a equipe padrão." / "Cargos criados." | `edP6`, `edEqTem` |
| Etapa 7 | Revisão | tabela com "Categoria", "Entidade", "Votação", "Evento" ("Votação única"), "Modalidade", "Peso", "Sigilo", "Quando", "Acesso", "Cargos" ("{nome} ({n})"), "Lista de aptos", "Equipe" ("Própria, {n} cargos" / "Equipe padrão"); rótulo 150px `#5b7383`; cada linha com "Alterar" | `edP7` |
| Etapa 7 | Pendências | vermelho `bi-x-circle-fill` (erros de configuração e de publicação); amarelo "{n} cargo(s) ainda sem pessoa indicada pela Administração."; verde "Tudo pronto para publicar." | `edPend` |
| Rodapé | Botões | `border-top:1px solid #e3ecf2`: "Salvar e sair" (toast "Rascunho salvo. Continue depois em Editar, no Painel."); "Voltar" (`bi-arrow-left`); "Continuar" (`bi-arrow-right`); na etapa 7 "Publicar votação" (`.vP`, `bi-megaphone`, só Comissão e sem bloqueio); ao publicar: toast "Votação publicada. Agora divulgue o QR Code." e abre o modal Link e QR Code | `edVolta`, `edSeg`, `edNPub` |
| Validação | Erros | "Informe a entidade." / "Informe o nome da votação." / "Confira o início e o encerramento." / "O início precisa ser uma data futura. Para abrir na hora, escolha Conduzidos pela mesa." / "Informe o nome do cargo, pauta ou pergunta." / "Esta votação precisa da lista de aptos." / mensagens de `S.validaCfg` | `edErro` |

### 9.6 Lista de aptos (`sub:'ls'`, Comissão)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título | `.vH` `bi-list-check` "Lista de aptos da Comissão" + "Baixar modelo" | `subLs` |
| Regras | Texto | "Envie planilha XLSX ou CSV; use Baixar modelo. Quem não consta na lista não vê a votação. Sem a coluna situacao, todos ficam aptos; com ela, só "apto", "adimplente", "regular" ou "quite" liberam o voto. Votação com peso exige a coluna peso. Acesso por código exige nome e email. A lista fica bloqueada após a abertura." | sempre |
| Por votação | Linha | nome; resumo "{n} aptos, {m} inaptos, peso total {p}. Importada em {data}.[ {k} alteração(ões) por decisão do conselho.][ Atenção: {n} valor(es) de peso com menos de 3 eleitores podem permitir identificar o voto ao abrir as cédulas.][ Atenção: {n} registro(s) sem e-mail: qualquer pessoa com nome e identificação poderia pedir o código.]" ou "Sem lista importada."; "Hash da lista: {32}" mono 11.5px | `aptosL` |
| Por votação | Botão | "Importar lista"/"Substituir lista" (`.vP`, `bi-upload`), habilitado só para Comissão em rascunho ou publicada; toast "{n} registros importados. Inválidas: {x}. Duplicadas: {y}." | `a.nPode` |
| Busca | Campo | "Procurar por nome ou identificação (sem busca, mostra os não aptos)"; até 8 resultados | `a.tem` |
| Busca | Linha | nome; "{id} · {grupo}"; chip "Apto"/"Não apto"; campo "Ocorrência, ex.: cadastro desatualizado" (máx. 80) + "Registrar" (`.vP`); histórico "Registrada em {data} por {quem}[. Avaliação pendente no conselho. / . Aptidão concedida/Inaptidão mantida em {data}: {justificativa}]"; vazio "Nenhum registro para mostrar."; toast "Ocorrência registrada." | `a.pes`, `a.semPes` |
| Modelo CSV | Conteúdo | `modelo-lista-de-aptos.csv`: `identificacao;nome;email;grupo;situacao;peso;categoria;ocorrencia` + linhas `A-101;Nome Exemplo;nome@exemplo.invalid;Bloco A;quite;0,0250;;`, `A-102;;;Bloco A;inadimplente;0,0250;;Cadastro desatualizado`, `B-201;;;Bloco B;quite;0,0310;;` | botão |

### 9.7 Eleitores (`sub:'et'`, Validação)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título / vazio | `.vH` `bi-person-check` "Eleitores cadastrados: {n}"; "Nenhum cadastro enviado." | `subEt`, `semEleit` |
| Linha | Cabeçalho | nome 14.5px; "{grupo} · {id mascarado}"; chip "Revisão posterior" `#0a6fa0` / "Em análise" `#8a6200` / "Habilitado" ou "Sem aptidão" `#1e7b45` / "Recusado" `#a3232b` | sempre |
| Análise | Fotos | grade `minmax(120px,1fr)`: "Carteira frente", "Carteira verso", "Documento", "Selfie" (4:3, `#eef3f6`); "Qualidade aprovada" verde ou "Com ressalva: {motivo}" `#8a6200` | `v.an` (em análise ou revisão posterior do acesso por código) |
| Análise | Localização | "Localização no acesso ({data}): {lat}, {lon}, precisão de {n} m." / "não autorizada pelo votante" / "indisponível no aparelho" | `v.geo` |
| Análise | Conferência | caixas "Selfie confere com os documentos", "Vínculo conferido com a entidade", "Aptidão conferida (quando não houver lista)" | `v.chk` |
| Análise | Decisão | "Habilitar" (`.vP`, exige as duas primeiras caixas) + "Motivo da recusa" + "Recusar" (`.vD`, exige motivo); toasts "Decisão registrada." / "Recusa registrada." | `v.nOk`, `v.nRec` |

### 9.8 Avaliações (`sub:'av'`, Conselho Eleitoral)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título e texto | `.vH` `bi-clipboard-check` "Avaliações do conselho"; "Cada ocorrência registrada para pessoa não apta abre uma avaliação. A decisão fica na trilha de auditoria, com data e hora em segundos."; vazio "Nenhuma avaliação registrada." | `subAv`, `avSem` |
| Linha | Conteúdo | "{nome} · {id}"; chip "Pendente" `#8a6200` / "Tornado apto" `#1e7b45` / "Mantida" `#a3232b`; votação; "Ocorrência: {texto} (registrada em {data} por {quem})"; "Avaliação aberta em {data}." ou "{Aptidão concedida/Inaptidão mantida} em {data} por {quem}: {justificativa}"; pendentes primeiro | `avL` |
| Linha pendente | Decisão | "Justificativa da decisão" (máx. 160) + "Manter inaptidão" + "Tornar apto(a)" (`.vP`; desabilitado sem justificativa ou com a votação encerrada); toast "Decisão registrada." | `a.pend` |

### 9.9 Apuração com guardiões (`sub:'ap'`, Comissão e Guardião)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título e aviso | `.vH` `bi-calculator` "Apuração com guardiões"; `.vAviso` "Ninguém apura sozinho: a chave que abre as cédulas foi dividida entre guardiões. Cada guardião entra e apresenta a própria parte; depois a Comissão abre as cédulas. Nesta demonstração as cinco partes ficam neste navegador; em produção cada guardião mantém a sua fora do sistema." | `subAp` |
| Vazio | Texto | "Nenhuma eleição encerrada aguardando apuração." | `apSem` |
| Por votação | Guardiões | grade `minmax(130px,1fr)`; 5 botões "Guardião {x}" `min-height:60px` com `bi-key` / `bi-key-fill` (verde `.vKey` quando apresentada); só o próprio guardião clica na sua; toast "Parte apresentada." | `a.gs` |
| Por votação | Contagem e ação | "{n} de {k} partes apresentadas."; "Abrir cédulas e totalizar" (`.vP`, `bi-play-fill`, só Comissão com n ≥ k); toast "Apuração concluída. Veja o resultado em Transparência." ⚠ | `a.nOk` |

### 9.10 Auditoria (`sub:'au'`, Gestor Master, Auditoria, Fiscal)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título | `.vH` `bi-journal-check` "Trilha de auditoria" + "Verificar trilha" (`bi-patch-check`) + "Exportar" (`trilha-auditoria-voto.json`) | `subAu` |
| Mensagem | Verificação | "Trilha íntegra: {n} evento(s) conferidos." / "Trilha adulterada no evento {x}." | `audMsg` |
| Lista | Linha | últimas 60, mais nova primeiro: ação em negrito; data com segundos; "{ator} · {detalhe ou 'Sem detalhes'}"; hash 40 caracteres mono 11px `#7a8e9b` | `trilha` |

### 9.11 Testes (`sub:'te'`, Auditoria)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Cartão | Título | `.vH` `bi-bug` "Testes do protocolo" + "Executar testes" (`bi-play-fill`) + "Gerar 30 votos de teste" (`bi-people`); desabilitados enquanto roda | `subTe`, `teRod` |
| Texto | Explicação | "Os testes rodam em memória, com criptografia real, e não alteram as eleições. Os votos de teste usam eleitores fictícios na eleição aberta e ficam identificados na trilha." | sempre |
| Mensagens | Estado | "Executando..." / "Executando teste {n}..." / "{f} de {n} testes falharam." / "{n} testes aprovados." / "Gerando votos de teste..." / "30 votos de teste depositados pelo protocolo completo." / "Abra uma votação sem lista de aptos antes." | `teMsg` |
| Resultado | Linha | ícone verde/vermelho + nome + "({detalhe})" 12.5px | `teRes` |

## 10. Dados de exemplo (semente) e relógios

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Perfis | Semente | Administração (= nome do usuário, padrão "Farlei Assis da Rocha"), "Presidência da Comissão (exemplo)", "Validação (exemplo)", "Guardião 1..5 (exemplo)", "Auditoria (exemplo)", "Fiscal (exemplo)", "Conselho Eleitoral (exemplo)", "Gestor Master (exemplo)"; e-mails `@exemplo.invalid` | primeira carga |
| Votações | Semente | evento "Assembleia Geral Ordinária (exemplo)" do "Condomínio Residencial Exemplo" (mesa, peso, alterar voto, código por PIN): "Aprovação das contas de 2025", "Previsão orçamentária de 2026" (Sim/Não), "Eleição de síndico(a)" (11 "Candidatura Fictícia A", 22 "Candidatura Fictícia B"); 12 unidades A-101..A-106 e B-201..B-206, a sexta de cada bloco inadimplente, pesos 0,0900/0,0750 | primeira carga |
| Votações | Semente | "Eleição da diretoria", "Associação Exemplo", chapas 01 "Chapa Exemplo Alfa" e 02 "Chapa Exemplo Beta", horário (começou 1 h antes, termina em 7 dias), acesso por código; eleitores "Eleitor Exemplo Um..Quatro" (CPF 000.000.001-91 etc.) e "Eleitor Exemplo Inapto" (000.000.005-15) | primeira carga |
| Relógios | Intervalos | 1 s: liga classe `vPortal` e atualiza janelas ao vivo; 1 s: redesenha cronômetros se há votação aberta com prazo; 5 s: `S.tick()` (abre e fecha pelo horário) | sempre |

## 11. Persistência no navegador (protótipo)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Núcleo | Chaves `localStorage` | `bj-votacao-eleicoes`, `bj-votacao-perfis`, `bj-votacao-identidade` (cadastros com fotos em base64, PIN, localização), `bj-votacao-tokens` (códigos por e-mail), `bj-votacao-trilha`, `bj-votacao-incidentes`; por votação: `bj-votacao-urna:{id}`, `-aptos:`, `-ocorrencias:`, `-participacao:`, `-comprovantes:`, `-compromissos:`, `-guardioes:` (partes da chave), `-partes-apresentadas:`, `-autoriz-chave:`, `-resultado:`, `-nominal:`, `-pendente:{id}:{uid}` | sempre |
| Testes | Chave | `bj-voto-teste-ordem` | testes |
| Família | Chaves | `bj-monitor`, `bj-recursos`, `bj-temas`, `bj-tema-visto-*`, `bj-contas-v1` e outras de `bj-contas.js` | sempre |
| Isolamento | Prefixo por conta | `bj-contas.js` reescreve `getItem/setItem`: fora da conta de demonstração a chave vira `bjt:{conta}:{chave}` | sempre |
| Memória | Estado de tela | `state` (aba, sub, rascunho `ed`, sessão `vUid/vSess`, PIN em `_pin`) não é salvo; recarregar perde a sessão do eleitor | sempre |

## 12. Atributos e detalhes invisíveis

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| Upload | `data-sem-central="1"` | todos os `input type=file` do Voto (não deixa a Central de documentos capturar) | sempre |
| Upload | Câmera | `capture="environment"` (documento) e `capture="user"` (selfie) | celular |
| Campos | Autocomplete | `name`, `email`, `current-password`, `new-password` | sempre |
| Campos | `inputmode="numeric"` | CPF, PIN, código, minutos, quórum | sempre |
| Dicas | `title` | chip do perfil (permissão), "Adicionar cargo", "Editar cargos"; viram dica azul de `bj-dica.js` | hover |
| aria | Rótulos | "Convocação", "Minutos", "Próxima sessão", "Entre sessões", "Ocorrência", "Justificativa", "Número", "Nome", "Descrição", "Propostas", "Composição", "Subir", "Descer", "Remover", "Remover cargo", "Foto da candidatura", "Editar", "Cargo", "E-mail", "Alterar {rótulo}", "E-mail para receber o comprovante" | sempre |
| Nomes de arquivo | Downloads | `pacote-publico-{id}.json`, `boletim-abertura-{id}.pdf`, `boletim-apuracao-{id}.pdf`, `boletim-de-urna-{id}`, `relacao-de-votantes-{id}.pdf`, `relatorio-consolidado-{id}.pdf`, `lista-auditoria-{id}.pdf`, `comprovante-participacao-{cod}`, `cartaz-qrcode-{id}.png`, `estatisticas-votacoes.csv`, `modelo-lista-de-aptos.csv`, `trilha-auditoria-voto.json` | botões |
| Códigos | Prefixos | BJV (depósito da cédula), BJC (participação), BJB (boletim), BJU (boletim de urna), BJL (relação), BJR (relatório), BJA (auditoria); formato `XXXX-XXXX-XXXX` do SHA-256 | documentos |
| Máscara | CPF | `***.xxx.xxx-**` nas telas; completo só no PDF de auditoria | sempre |
| Peso | Formato | peso guardado em milionésimos; mostrado com até 6 casas `pt-BR` | votação com peso |

## 13. Código morto e inconsistências (para não copiar sem querer)

| Região | Item | Valor do desenho (CSS/JS) | Estado(s) |
|---|---|---|---|
| JS | Sem uso na marcação | `ufs` (lista de UFs), `cpPdf`/`uCpPdf` (comprovante em PDF sem botão), `edNovo`, `edFechar`, `edSalvar`, `focoLimpa`, `cens`, classe `.vPasso` | n/a |
| JS | `lerCsv` | lê colunas na ordem `ident, grupo, situacao, peso, categoria, nome, email`, diferente do modelo (`identificacao;nome;email;grupo;...`); não é chamado (a importação usa `BJVotoEntrada.lerLista`) | n/a |
| JS | Filtro da aba `hab` | `abas.filter(a => a[0]==='hab' ...)` nunca atua: `hab` não está na lista de abas | n/a |
