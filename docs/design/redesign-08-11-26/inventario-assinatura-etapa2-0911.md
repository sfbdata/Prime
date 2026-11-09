# Inventário do desenho: etapa 2 da Assinatura (envio e assinatura Simples)

Desenho: `docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (475 linhas, inteiro, termina em `</html>`).
Coluna **L** = linha do dc.html. Textos entre aspas simples são literais do desenho (marcação ou JS).
`{x}` = valor que vem do dado. Cores e medidas copiadas do CSS/inline do desenho.
Classes do desenho citadas na coluna Componente (`.bjaB`, `.bjaP`...) estão definidas nas linhas 28 a 110
(resumo na região M). A etapa 1 já entregou `.bjaModal/.bjaModalCx/.bjaModalTx/.bjaModalPe`, `.bjaToast`
(com `is-erro/is-aviso/is-info`) e `.bjaHd.is-perigo` em `app/public/css/assinatura.css`, e o JS
`enviar()` já espera `data-url-enviar` (`app/public/js/assinatura.js:701-711`).

Contagem por região: A 21 · B 7 · C 21 · D 9 · E 6 · F 10 · G 13 · H 23 · I 8 · J 13 · K 8 · L 22 · M 18 · N 9 · O 6 · P 1 · total 195.

---

## A. Passo 4 do assistente e "Gerar links e enviar" (L193-199, 369, 374-375, 460-461)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| A1 | Passo 4 | Grade do passo | `.bjaGrid` (flex, wrap, gap 16px, align-items flex-start) com `padding:14px 16px` | grade do desenho | `p4` (passo 4) | 193, 56 |
| A2 | Passo 4 | Coluna lateral | `.bjaLat` flex 1 1 320px; max-width 440px; column; gap 14px | coluna do desenho | sempre no passo 4 | 194, 57 |
| A3 | Passo 4 | Campo 'Mensagem aos signatários' | `label.bjaL` + `textarea.bjaF rows="4"`; padrão 'Segue o documento para sua assinatura eletrônica.' | campo do desenho | novo: texto padrão; retomado: mensagem salva | 194, 369 |
| A4 | Passo 4 | Campo 'Prazo para assinar (dias)' | `input.bjaF type="number" min="1" max="90"`; padrão 7 | campo do desenho | sempre | 194, 369 |
| A5 | Passo 4 | Resumo | div `font-size:13px; color:#34505f; line-height:1.6`: `<b>{nome do documento}</b><br>{resumo}`; resumo = '{n} signatário(s)' + (em ordem: ', em ordem') + '. Prazo de {dias} dias.' + (modelo com campo vazio: ' Há {k} campo(s) em branco, que sairão com linha para preenchimento.') | texto | com/sem ordem; com/sem campo vazio | 194, 460 |
| A6 | Passo 4 | Prévia A4 | `.bjaCent` > `.bjaPv data-folha="w"` | folha do desenho (etapa 1) | sempre | 195 |
| A7 | Assistente | Erro | div `padding:0 16px 10px; font-size:13px; color:#a3232b`; `{wErro}` | texto de erro | `wErroTem` | 198 |
| A8 | Assistente | Barra de botões | div flex, gap 8px, justify-content flex-end, wrap, `padding:10px 16px; background:#f7f9fb; border-top:1px solid #e6edf2` | barra do desenho | passo > 1 | 199 |
| A9 | Assistente | 'Voltar' | `.bjaB.bjaN` com `height:38px` | botão neutro | passo > 1 | 199 |
| A10 | Assistente | 'Salvar rascunho' | `.bjaB` com `height:38px` | botão (etapa 1) | passo > 1 | 199 |
| A11 | Assistente | Botão 'Gerar links e enviar' | `.bjaP` (38px, `#0a6fa0`, hover `#0b5f86`), sem ícone; rótulo = passo 4 ? 'Gerar links e enviar' : 'Avançar' | botão primário | só no passo 4 | 199, 460 |
| A12 | Envio | Conferência de nomes | algum signatário com nome vazio: erro 'Informe o nome de todos os signatários.' | erro A7 | ao clicar | 375 |
| A13 | Envio | Conferência de CPF | CPF preenchido com dígito errado: erro 'Confira o CPF informado.' (`cpfOk`: 11 dígitos, não todos iguais, 2 dígitos verificadores) | erro A7 | ao clicar | 375, 296 |
| A14 | Envio | O que NÃO confere | e-mail não é obrigatório nem conferido; nível não conferido; signatário Simples sem e-mail passa (ver Dúvida 1) | | | 375 |
| A15 | Envio | Documento enviado | situação 'aguardando'; `vence = agora + dias × 86400000 ms` (conta da hora do envio, não do fim do dia); cada signatário `st:'pendente'` e `token = rid(20)` (20 caracteres de `abcdefghjklmnpqrstuvwxyz23456789`) | | | 374, 294, 307 |
| A16 | Envio | Hash | SHA-256 de `JSON(blocos) + id` (sistema: hash do PDF, pela spec) | | | 375 |
| A17 | Envio | Código | `BJA-XXXX-XXXX-XXXX` derivado do hash, alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (spec: etapa 3, HMAC) | | | 375 |
| A18 | Envio | Trilha | 1º evento 'Documento criado'; depois 'Enviado para assinatura. Hash {16 primeiros hex}...' | trilha | | 374-375 |
| A19 | Envio | Rascunho retomado | o protótipo cria documento novo e apaga o rascunho (`w.rasc`), perdendo a trilha dele; o sistema envia o mesmo documento (`url.enviar` com id) | | ⚠ convenção | 375 |
| A20 | Envio | Depois de enviar | vai para o **detalhe** do documento (`tela:'det'`, `docId`); toast verde `#1e7b45` 'Documento pronto. Copie o link ou envie por e-mail.' | toast K1 | sucesso | 375 |
| A21 | Envio | E-mail aos signatários | protótipo não envia nada; spec manda e-mail a cada signatário com e-mail (texto do e-mail não existe no desenho, ver E3/E6) | | ⚠ spec | 375 |

## B. Lista: ações de envio (L149, 430, 443)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| B1 | Linha da tabela | Ícone 'Copiar link' | `.bjaB.bjaI` (32×32), `bi-link-45deg`, `aria-label` e `data-tip` 'Copiar link' | botão-ícone + balão | `temLink`: situação calculada 'aguardando' (não expirado) e existe signatário pendente | 149, 430 |
| B2 | Linha da tabela | Ícone 'Enviar lembrete' | `.bjaB.bjaI`, `bi-bell`, `aria-label` e `data-tip` 'Enviar lembrete'; vem logo depois do B1 | idem | idem B1 | 149 |
| B3 | Linha/menu | Alvo das duas ações | `p1` = primeiro signatário com `st:'pendente'` na ordem da lista | | | 430, 443 |
| B4 | Linha/menu | Copiar link | copia o link de `p1` (E4) e mostra 'Link copiado.' | | | 430 |
| B5 | Linha/menu | Enviar lembrete | evento 'Lembrete enviado a {p1.nome}' e toast verde 'Lembrete registrado para {p1.nome}.' (sem confirmação) | toast K1 | | 430, 443 |
| B6 | Menu de contexto | Itens 'Copiar link' (`bi-link-45deg`) e 'Enviar lembrete' (`bi-bell`), cor `#243845` | ordem: Abrir, (Continuar e enviar), **Copiar link, Enviar lembrete**, Baixar PDF com selo, (Vincular à pasta), Duplicar... | `.bjaCtx` | só com 1 documento, 'aguardando' e `p1` | 443 |
| B7 | Linha | Expirado, Cancelado, Concluído, Recusado, Rascunho | os dois ícones não aparecem | | | 430 |

Observação: o **detalhe não tem "Enviar lembrete"**. No desenho o lembrete só existe na linha da lista e no menu de contexto.

## C. Detalhe do documento enviado (L216-226, 293, 361, 462-463)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| C1 | Cabeçalho | Linha do título | div flex, gap 12px, wrap, margin-bottom 12px; botão 'Documentos' `.bjaB.bjaN` com `bi-arrow-left`; `h1` `clamp(20px, 2.6vw, 28px)`, normal, `flex:1 1 240px` = nome do documento | cabeçalho do desenho (etapa 1) | sempre | 217 |
| C2 | Cabeçalho | Selo da situação | `height:26px; padding:0 10px; border-radius:3px; background:{cor}; color:#fff; font-size:13px; bold`; **sem ícone** | selo | Rascunho `#5b7383` · Aguardando `#0a6fa0` · Concluído `#1e7b45` · Recusado `#a3232b` · Expirado `#8a6200` · Cancelado `#7a8e9b` (Expirado = aguardando com `vence < agora`) | 217, 293, 361 |
| C3 | Signatários | Cartão | `section.bjaCard` overflow hidden; `.bjaHd` `background:#0a6fa0`, `bi-people`, 'Signatários' | cartão do desenho | sempre | 221 |
| C4 | Signatários | Linha de cada signatário | `padding:10px 14px; border-bottom:1px solid #e6edf2`; ordem = ordem da lista, **sem número de ordem** | | | 221 |
| C5 | Signatários | Nome | `b` 14px, flex 1; mostra o nome digitado ao assinar (`nomeAss`) ou o nome cadastrado | | assinado: nome digitado | 221, 463 |
| C6 | Signatários | Selo do signatário | `height:20px; padding:0 7px; border-radius:3px; color:#fff; font-size:11.5px; bold` | selo | 'Assinado' `#1e7b45` · 'Recusado' `#a3232b` · 'Pendente' `#8a6200` | 221, 463 |
| C7 | Signatários | Papel e nível | 12.5px `#5b7383`: '{papel} · {rótulo do nível}' (ex.: 'Contratante · Simples: e-mail e código') | | | 221, 292 |
| C8 | Signatários | Informação | 12.5px `#34505f`; assinado: '{data e hora pt-BR} · {método}' (ex.: '09/11/2026, 14:03:22 · E-mail e código'); pendente ou recusado: o e-mail, ou 'Sem e-mail: envie o link' | | assinado / pendente / recusado (recusado mostra o e-mail, não o motivo) | 221, 463 |
| C9 | Signatários | Barra de ações | div flex, gap 5px, wrap, margin-top 7px | | só `st:'pendente'` **e** documento 'aguardando' (expirado não); todos os pendentes, mesmo com ordem | 221, 463 |
| C10 | Signatários | 'Copiar link' | `.bjaB` + `bi-link-45deg` → E4 | botão | pendente | 221 |
| C11 | Signatários | 'E-mail' | `<a class="bjaB" href="mailto:...">` + `bi-envelope` → E3 | link-botão | pendente (aparece mesmo sem e-mail) | 221 |
| C12 | Signatários | 'WhatsApp' | `<a class="bjaB" target="_blank" rel="noopener">` + `bi-whatsapp` → E2 | link-botão | pendente | 221 |
| C13 | Signatários | 'Tela do signatário' | `.bjaB.bjaN` + `bi-box-arrow-up-right`; abre a tela pública em **prévia** (`abrirAss(token, true)`) | botão neutro | pendente | 221, 463 |
| C14 | Signatários | "Quem é o próximo" | **não existe** no detalhe; a ordem só aparece na tela pública (G9) e no resumo do passo 4 (', em ordem') | | | |
| C15 | Código | Cartão | `section.bjaCard` `padding:12px 14px`, column, gap 8px; rótulo 'CÓDIGO DE VALIDAÇÃO' 12px bold `#4a6373`; código `bold 17px monospace`, `#a3232b`, `letter-spacing:1px` = código ou 'Gerado no envio' | cartão | sem código: 'Gerado no envio' (ver Dúvida 4) | 222, 462 |
| C16 | Código | 'Cancelar envio' | `.bjaB.bjaR` com `height:38px`, **sem ícone**, depois dos outros botões do cartão | botão de perigo | só 'aguardando' (expirado não) | 222, 462 |
| C17 | Código | Ação do Cancelar | situação 'cancelado'; evento 'Envio cancelado. Documento e evidências preservados'; toast `#8a6200` 'Envio cancelado.'; no desenho **sem confirmação** (D5 acrescenta a do modal D) | | | 462 |
| C18 | Código | Depois de cancelar | selo 'Cancelado' `#7a8e9b`; barra de ações dos signatários some; botão Cancelar some; link público mostra G7 | | | 462-463, 437 |
| C19 | Trilha | Cartão | `.bjaHd` `#0a6fa0`, `bi-clock-history`, 'Trilha de auditoria'; lista `max-height:300px; overflow-y:auto`; item `padding:8px 14px; border-bottom:1px solid #e6edf2; font-size:12.5px`: `<b>{texto}</b>` + div `#5b7383` '{data e hora pt-BR}'; mais recente primeiro | cartão (etapa 1) | | 223, 306, 462 |
| C20 | Prévia | Folha | `.bjaCent` > `.bjaPv data-folha="d"`; rubricas e 'Assinado eletronicamente em {data}' aparecem conforme assinam | folha (etapa 1) | | 225, 352 |
| C21 | Código | Botões de outras etapas no mesmo cartão | 'Continuar e enviar' (etapa 1), '{Baixar PDF com selo}' e 'Vincular à pasta' (etapa 3) | | não entram na etapa 2 | 222 |

## D. Modal de Excluir (reaproveitado na confirmação do "Cancelar envio") (L281, 445)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| D1 | Modal | Fundo | `position:fixed; inset:0; z-index:2147483300; background:rgba(11,53,80,.55); display:flex; align-items:center; justify-content:center; padding:14px` | `.bjaModal` (etapa 1) | `mExc` | 281 |
| D2 | Modal | Caixa | `role="dialog" aria-modal="true" aria-label="Excluir documentos"`; `.bjaCard` `width:100%; max-width:460px; overflow:hidden` | `.bjaModalCx` | | 281 |
| D3 | Modal | Título | `.bjaHd` `background:#a3232b`, ícone `bi-trash3` + 'Mover para a Lixeira' ou 'Excluir definitivamente' | `.bjaHd.is-perigo` | | 281, 445 |
| D4 | Modal | Texto | `padding:14px 16px; font-size:14px; line-height:1.5; color:#243845`; '{n} documento(s) irão para a Lixeira. Envios em andamento serão cancelados e os links deixarão de funcionar. Documentos enviados e suas evidências ficam preservados e podem ser restaurados.' ou 'O rascunho será apagado e não poderá ser recuperado.' | `.bjaModalTx` | | 281, 445 |
| D5 | Modal | Rodapé | flex, justify-content flex-end, gap 8px, `padding:10px 16px; background:#f7f9fb` | `.bjaModalPe` | | 281 |
| D6 | Modal | 'Voltar' | `.bjaB.bjaN`, fecha sem fazer nada | botão neutro | | 281 |
| D7 | Modal | Confirmar | `.bjaB.bjaR`: 'Excluir' ou 'Excluir definitivamente' | botão de perigo | | 281, 445 |
| D8 | Modal | Fechamento | sem X, sem fechar ao clicar no fundo, sem Esc no desenho (a etapa 1 fecha com Esc pela convenção) | | | 281 |
| D9 | Modal | Textos para "Cancelar envio" | **não existem no desenho** (acréscimo D5). Sugestão só com frases do próprio desenho: título 'Cancelar envio' com `bi-slash-circle` (ícone de Cancelado, L293); texto 'O envio será cancelado e os links deixarão de funcionar. Documento e evidências ficam preservados.'; botões 'Voltar' e 'Cancelar envio' | | ⚠ decidir | |

## E. Link, WhatsApp, e-mail, cópia e lembrete (L358-360, 430, 463)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| E1 | Link | Endereço | `'https://www.bluejus.com.br/assinar/' + token` (sistema: domínio do sistema + `/assinar/{token}`) | | | 358 |
| E2 | WhatsApp | Link e texto | `'https://wa.me/?text=' + encodeURIComponent(msg)`, **sem número**; `msg = 'Olá, ' + {nome cadastrado} + '. Documento para assinar: ' + {nome do documento} + '. Acesse: ' + {link}`. Exemplo: 'Olá, Ana Souza. Documento para assinar: Procuração Geral: Ana Souza. Acesse: https://www.bluejus.com.br/assinar/k3m...'. Abre em nova aba (`target="_blank" rel="noopener"`) | link-botão | | 463 |
| E3 | E-mail | `mailto:` | `'mailto:' + {e-mail sem codificar, vazio se não houver} + '?subject=' + encodeURIComponent('Documento para assinatura: ' + {nome do documento}) + '&body=' + encodeURIComponent(({mensagem} ? {mensagem} + '\n\n' : '') + {link})`. Assunto: 'Documento para assinatura: {nome do documento}'. Corpo: '{mensagem}' + linha em branco + '{link}'. É o único texto de e-mail do desenho | link-botão | sem e-mail: `mailto:?subject=...` | 463 |
| E4 | Copiar | Cópia | `navigator.clipboard.writeText`; ok: toast verde 'Link copiado.'; falha: `textarea` + `execCommand('copy')` → 'Link copiado.'; falha de novo: toast **azul `#0a6fa0`** com o próprio link | toast K1 | ok / falha | 359-360 |
| E5 | Lembrete | Efeito | só grava evento e mostra toast (B5); nenhum e-mail no protótipo; spec: e-mail ao primeiro pendente | | ⚠ spec | 430 |
| E6 | E-mails do servidor | Textos | **nenhum no desenho**: e-mail do código, lembrete, aviso ao próximo da fila ('Você receberá o aviso quando...', G9), recusa e conclusão ao remetente | | omissão | |

## F. Tela pública: página e cabeçalho (L5-6, 28, 232-235, 299, 464)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| F1 | Página | Raiz | div `min-height:100vh; display:flex; flex-direction:column`, `data-screen-label="Tela do signatário"`; body `background:#f3f6f8; font-family:Arial, Helvetica, sans-serif; color:#12242f; margin:0`; **sem moldura nem menu do sistema** | página própria | `tAss` | 233, 28 |
| F2 | Cabeçalho | Barra | `header` `background:#0a76a8; color:#fff; min-height:58px; display:flex; align-items:center; gap:14px; padding:0 clamp(10px, 2vw, 24px)`; **não fixa** (sem sticky) | cabeçalho do signatário | sempre (bloqueio ou formulário) | 234 |
| F3 | Cabeçalho | Logo | `img` `./bluejus-favicons/bluejus-logo-nav-branco.png`, `alt="BlueJus"`, `height:28px` | | | 234 |
| F4 | Cabeçalho | Separador | `span.bjaOc` `width:1px; height:20px; background:rgba(255,255,255,.45)` | | some em ≤760px | 234, 103 |
| F5 | Cabeçalho | Nome do documento | `span` `font-size:15px; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap` = nome do documento | | | 234, 464 |
| F6 | Cabeçalho | 'Fechar pré-visualização' | `.bjaB` com `background:transparent; color:#fff; border-color:rgba(255,255,255,.6)`; volta ao detalhe | botão | só na prévia aberta pelo escritório (`aPrev`) | 234, 464 |
| F7 | Página | Conteúdo | `main` `width:100%; max-width:1400px; margin:0 auto; padding:16px clamp(10px, 2vw, 24px) 40px; box-sizing:border-box` | | | 235 |
| F8 | Página | Título da aba | `<title>Assinatura</title>` (único título do desenho) | | | 6 |
| F9 | Página | Viewport | `<meta name="viewport" content="width=device-width, initial-scale=1">` | | | 5 |
| F10 | Página | Entrada | link real `https://www.bluejus.com.br/assinar/{token}`; no protótipo `#assinar={token}` abre sem prévia (`prev:false`) | | | 299, 358 |

## G. Tela pública: bloqueios (L236, 376, 437)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| G1 | Bloqueio | Cartão | `.bjaCard` `padding:30px; text-align:center; max-width:560px; margin:24px auto` | cartão de bloqueio | `aBloq` | 236 |
| G2 | Bloqueio | Ícone | `i.bi.{ícone}` `font-size:38px; color:#0a6fa0` (azul em todos, inclusive recusa e prazo) | | | 236 |
| G3 | Bloqueio | Título | `font-size:19px; font-weight:bold; margin:8px 0 4px` | | | 236 |
| G4 | Bloqueio | Texto | `font-size:14px; color:#4a6373` | | | 236 |
| G5 | Bloqueio | Já assinou | `bi-check-circle` · 'Assinatura já registrada' · 'Este link já foi utilizado.' | | signatário assinado (também com documento concluído, e depois do 'Concluir' do J10) | 437 |
| G6 | Bloqueio | Recusou | `bi-x-circle` · 'Assinatura recusada' · 'A recusa foi registrada no documento.' | | este signatário recusou | 437 |
| G7 | Bloqueio | Cancelado | `bi-slash-circle` · 'Envio cancelado' · 'Este documento não está mais disponível para assinatura.' | | documento cancelado (inclui Lixeira com envio em andamento) | 437, 364 |
| G8 | Bloqueio | Prazo | `bi-clock` · 'Prazo encerrado' · 'Peça ao remetente um novo envio.' | | aguardando com prazo vencido | 437 |
| G9 | Bloqueio | Ordem | `bi-hourglass-split` · 'Aguardando assinatura anterior' · 'Você receberá o aviso quando {nome} assinar.' ({nome} = nome **cadastrado** do primeiro signatário anterior na lista que não assinou) | | "Assinar na ordem da lista" ligado | 436-437 |
| G10 | Bloqueio | Precedência | assinado > recusado (deste) > cancelado > expirado > ordem | | | 437 |
| G11 | Bloqueio | O que some | com bloqueio só ficam cabeçalho (F2) e cartão; prévia e painel não aparecem | | | 236-237 |
| G12 | Bloqueio | Link desconhecido | no desenho é **só toast** vermelho `#a3232b` 'Link inválido.'; não há tela (ver Dúvida 6) | toast K1 | | 376 |
| G13 | Bloqueio | Sem bloqueio no desenho | documento 'Recusado' por **outro** signatário: o formulário continua aberto para os demais (spec 1.10 manda os links deixarem de valer) | | omissão | 437 |

## H. Tela pública: formulário Simples (L237-261, 296, 376-379, 386-391, 464-467)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| H1 | Formulário | Grade | `.bjaGrid`; 1º filho `.bjaCent` > `.bjaPv data-folha="a" style="max-height:84vh;"` (prévia vem antes no DOM); 2º filho `.bjaLat style="max-width:420px;"` | grade do desenho | `aForm` | 237-239 |
| H2 | Painel | Cartão | `section.bjaCard` overflow hidden; `.bjaHd` `background:#0a6fa0`, `bi-pen`, 'Assinar documento' | cartão | | 239 |
| H3 | Painel | Corpo | `padding:12px 14px; display:flex; flex-direction:column; gap:11px` (botões esticam na largura) | | | 240 |
| H4 | Painel | Saudação | `font-size:13px; color:#34505f; line-height:1.5`: 'Olá, <b>{nome cadastrado}</b>. {mensagem do documento}'; sem mensagem: 'Leia o documento e confirme sua assinatura.' | texto | | 241, 464 |
| H5 | Painel | 'Nome completo' | `label.bjaL` + `input.bjaF`; **vem preenchido** com o nome cadastrado; digitar limpa o erro | campo | | 242, 376 |
| H6 | Painel | 'CPF' | `input.bjaF placeholder="000.000.000-00"`; **vem preenchido** com o CPF cadastrado (ou vazio); sem máscara | campo | ver Dúvida 2 | 243, 376 |
| H7 | Assinatura | Abas | div flex gap 4px margin-bottom 6px; 'Desenhar' e 'Digitar' `.bjaB` `flex:1`; ativa `background:#e5f1f7`, inativa `#fff` (inline: o hover não muda a cor); abre em 'Desenhar' | abas do desenho | `aEletr` (Simples e Reforçada) | 245, 376, 464 |
| H8 | Assinatura | Canvas | `width="760" height="240"`, `aria-label="Área para desenhar a assinatura"`, `width:100%; height:120px; border:1px solid #9fc3d8; background:#fff; touch-action:none; display:block; cursor:crosshair` | canvas | aba Desenhar | 246 |
| H9 | Assinatura | Traço | `lineWidth = 3.4` (px do canvas 760×240), `lineCap='round'`, `lineJoin='round'`, `strokeStyle='#0b3550'`; eventos de ponteiro (mouse, toque e caneta): `pointerdown` inicia e chama `setPointerCapture`, `pointermove` desenha, `pointerup` termina; coordenada convertida pela razão canvas/CSS em x e em y separadamente; só o movimento marca "não vazio" (toque parado não conta) | | | 378 |
| H10 | Assinatura | 'Limpar' | `.bjaB.bjaN` `margin-top:4px`; `clearRect` e volta a vazio | botão neutro | aba Desenhar | 246, 464 |
| H11 | Assinatura | Digitar | div `border:1px solid #9fc3d8; padding:14px; text-align:center; font:italic 30px Georgia, serif; color:#0b3550; min-height:44px`; mostra o próprio 'Nome completo' (não há campo separado) | | aba Digitar | 247 |
| H12 | Assinatura | Imagem da digitada | canvas 760×200, `'italic 76px Georgia, serif'`, `#0b3550`, centro (380, 100); reduz 4px por vez até caber em 720px (mínimo 24px); PNG | | | 379 |
| H13 | Assinatura | Imagem da desenhada | PNG do canvas 760×240; trocar de aba descarta o desenho | | | 379 |
| H14 | Código | Campo | linha flex gap 6px `align-items:flex-end`; `label.bjaL` flex 1 'Código enviado ao e-mail' (não mostra o endereço); `input.bjaF inputmode="numeric" maxlength="6"` com `font:bold 16px monospace; letter-spacing:4px` | campo | | 249 |
| H15 | Código | Botão | `.bjaB` `height:34px`: 'Enviar código'; depois do 1º pedido 'Reenviar'; cada clique gera código novo de 6 dígitos (100000 a 999999) e o anterior deixa de valer; **sem toast, sem contador, sem espera** | botão | antes/depois do pedido | 249, 465 |
| H16 | Código | Aviso amarelo da prévia | `font-size:12px; color:#7a5200; background:#fff8e1; padding:6px 9px`: 'Pré-visualização: o código é {código}. No sistema, ele chega por e-mail.' No protótipo aparece sempre que há código pedido, até fora da prévia; **no sistema não pode existir** | | prototipo apenas | 250 |
| H17 | Aceite | Caixa | label flex `align-items:flex-start; gap:8px; font-size:13px; color:#243845; line-height:1.4`; checkbox 16×16 `margin-top:1px`, começa desmarcado; texto 'Li o documento e concordo em assiná-lo eletronicamente. Serão registrados data, hora, localização autorizada e dados de identificação.' | caixa de marcar | | 257 |
| H18 | Painel | Erro | `font-size:13px; color:#a3232b`; some ao editar nome, CPF, código ou aceite | texto de erro | `aErroTem` | 258 |
| H19 | Painel | 'Assinar' | `.bjaP` `height:46px; font-size:16px`, ícone `bi-pen`, largura toda | botão primário | | 259 |
| H20 | Painel | 'Recusar assinatura' | `.bjaB.bjaR`, largura toda → abre I | botão de perigo | | 260 |
| H21 | Assinar | Ordem das conferências | 1 'Informe o nome completo.' (menos de 2 palavras) · 2 'Informe um CPF válido.' · 3 'O CPF não corresponde ao cadastrado para este signatário.' (só se houver CPF cadastrado) · 4 'Desenhe ou digite a sua assinatura.' · 5 'Solicite o código de confirmação.' · 6 'Código incorreto.' · 7 (Reforçada, etapa 7) 'Envie a selfie com o documento.' · 8 'Marque a confirmação de leitura.' | erro H18 | | 386-388 |
| H22 | Assinar | Efeito | som (J11) + vibração 40 ms; signatário 'assinado' com data, método 'E-mail e código', rubrica, nome e CPF digitados; evento 'Assinado por {nome digitado} · E-mail e código'; se todos assinaram: documento 'concluido' + evento 'Documento concluído'; abre J | | | 389-391 |
| H23 | Código | Limites | o desenho **não tem** validade do código, limite de tentativas nem teto diário (spec: HMAC, 15 min, 5 tentativas, teto diário); não há texto para esses casos | | omissão | 465, 387 |

## I. Tela pública: recusa (L283, 467)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| I1 | Recusa | Fundo | `position:fixed; inset:0; z-index:2147483100; background:rgba(11,53,80,.55)`; flex centralizado; `padding:14px` | modal do desenho | `mRec` | 283 |
| I2 | Recusa | Caixa | `role="dialog" aria-modal="true" aria-label="Recusar assinatura"`; `.bjaCard` `width:100%; max-width:440px; overflow:hidden` | | | 283 |
| I3 | Recusa | Título | `.bjaHd` `background:#a3232b`, 'Recusar assinatura' (**sem ícone**) | | | 283 |
| I4 | Recusa | Campo | corpo `padding:14px 16px`; `label.bjaL` 'Motivo' + `textarea.bjaF rows="3"`, começa vazio | | | 283, 467 |
| I5 | Recusa | Rodapé | flex end, gap 8px, `padding:10px 16px; background:#f7f9fb`; 'Voltar' (`.bjaB.bjaN`) e 'Recusar' (`.bjaB.bjaR`) | | | 283 |
| I6 | Recusa | Motivo vazio | toast vermelho `#a3232b` 'Informe o motivo.' (não é erro dentro da caixa) | toast K1 | | 467 |
| I7 | Recusa | Efeito | signatário 'recusado' com data e motivo; documento 'recusado'; evento 'Recusado por {nome cadastrado}: {motivo}'; caixa fecha; a tela mostra G6; sem toast | | | 467 |
| I8 | Recusa | Identificação | não pede nome, CPF, código nem aceite (ver Dúvida 7d) | | | 467 |

## J. Tela "Assinatura registrada" (L94-100, 110, 268-272, 380, 389-391, 468)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| J1 | Sucesso | Camada | `role="dialog" aria-modal="true" aria-label="Assinatura registrada"`; `position:fixed; inset:0; z-index:2147483646; overflow-y:auto; background:#1e7b45; color:#fff; font-family:Arial, Helvetica, sans-serif; animation:bjaWipe .6s ease-out both` (`clip-path:circle(0 at 50% 42%)` → `circle(150vmax at 50% 42%)`); classe `bjaMov` | tela cheia do desenho | `ovOk` | 268, 99 |
| J2 | Sucesso | Corpo | `min-height:100%; box-sizing:border-box; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; padding:24px 16px; text-align:center` | | | 268 |
| J3 | Sucesso | Anel de raios | `.bjaRing` `--r:min(19vmin, 96px)`, lado `calc(var(--r) * 2.3)`; 24 raios `.bjaRaio` girados `i × 15deg`; barra `.bjaBar` branca `left:calc(r × -.0375); top:calc(r × -1.13); width:calc(r × .075); height:calc(r × .26)`; `animation:bjaBarOk .5s cubic-bezier(.2,.8,.3,1.25) both` com atraso `100 + i × 14` ms (de `opacity:0; translateY(r × .42) scaleY(.3)`) | | | 269, 94-97, 468 |
| J4 | Sucesso | Visto | `svg viewBox="0 0 64 64"` `position:absolute; inset:22%; width:56%; height:56%`; `path d="M12 34 L27 49 L54 17"` `stroke:#fff; stroke-width:7; fill:none; pathLength=1; stroke-dasharray:1; stroke-dashoffset:1; animation:bjaDraw .4s ease-out .3s forwards`; classe `bjaMov` | | | 269, 98 |
| J5 | Sucesso | Título | 'Assinatura registrada' `font-size:clamp(28px, 5vw, 42px); bold` | | | 270 |
| J6 | Sucesso | Texto | `font-size:16px`: 'Todas as assinaturas foram coletadas.' ou 'Aguardando os demais signatários.' | | concluído / faltam outros | 270, 391 |
| J7 | Sucesso | Código | `font:bold 15px monospace; letter-spacing:1px; padding:7px 12px; border:1px solid rgba(255,255,255,.55); margin-top:6px` = código BJA (etapa 3, ver Dúvida 4) | | | 270 |
| J8 | Sucesso | Barra | div flex gap 8px wrap center margin-top 10px | | | 271 |
| J9 | Sucesso | 'Baixar PDF com selo' | `height:44px; padding:0 18px; border:none; border-radius:3px; background:#fff; color:#145a32; font-size:15px; bold; gap:8px`, ícone `bi-file-earmark-pdf` (PDF: etapa 3) | | | 271 |
| J10 | Sucesso | 'Concluir' | `height:44px; padding:0 18px; border:1px solid rgba(255,255,255,.7); border-radius:3px; background:transparent; color:#fff; font-size:15px` (peso normal); fecha a camada e a página mostra G5 | | | 271, 468 |
| J11 | Sucesso | Som | `AudioContext`, dois tons (oscilador padrão, senoide): 1568 Hz em 0 s (duração .55 s, volume .26) e 2093 Hz em .1 s (duração .85 s, volume .3); ganho sobe de .0001 em 12 ms e cai exponencialmente; falha silenciosa | | | 380, 389 |
| J12 | Sucesso | Vibração | `navigator.vibrate(40)` | | | 389 |
| J13 | Sucesso | Movimento reduzido | `@media (prefers-reduced-motion: reduce)`: `.bjaBar, .bjaMov { animation-duration:.01ms !important; animation-delay:0s !important; }` | | | 110 |

## K. Toasts da etapa 2 (L285, 305)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| K1 | Toast | Componente | `role="status"`; `position:fixed; left:50%; bottom:22px; transform:translateX(-50%); z-index:2147483647; max-width:calc(100vw - 28px); background:{cor}; color:#fff; padding:11px 16px; border-radius:3px; box-shadow:0 8px 24px rgba(18,36,47,.25); font-size:14px; animation:bjaUp .2s ease both`; some em 3400 ms; o novo substitui o anterior | `.bjaToast` (etapa 1) | verde padrão `#1e7b45` | 285, 305 |
| K2 | Toast | Envio | 'Documento pronto. Copie o link ou envie por e-mail.' verde | | | 375 |
| K3 | Toast | Cópia | 'Link copiado.' verde | | | 359-360 |
| K4 | Toast | Cópia falhou | {o link} azul `#0a6fa0` | `is-info` | | 360 |
| K5 | Toast | Lembrete | 'Lembrete registrado para {nome}.' verde | | | 430, 443 |
| K6 | Toast | Cancelar | 'Envio cancelado.' `#8a6200` | `is-aviso` | | 462 |
| K7 | Toast | Link | 'Link inválido.' `#a3232b` | `is-erro` | | 376 |
| K8 | Toast | Recusa | 'Informe o motivo.' `#a3232b` | `is-erro` | | 467 |

## L. O que a tela pública MOSTRA (e o que não mostra)

| # | Dado | Mostra? | Onde | L |
|---|---|---|---|---|
| L1 | Nome do documento | sim | cabeçalho (F5) | 234 |
| L2 | Conteúdo inteiro do documento (blocos, com todos os dados preenchidos nas cláusulas) | sim | prévia `data-folha="a"` | 238, 352-354 |
| L3 | Páginas do PDF enviado (documento avulso) | sim | prévia (bloco de página) | 354 |
| L4 | Logo do documento (BlueJus, do escritório ou própria) | sim | topo da folha | 352-354 |
| L5 | Fundo de segurança da folha com selo 'PRÉVIA' (visto só quando todos assinaram) | sim | folha (salvo folha branca) | 315, 352 |
| L6 | Rodapé de validação: QR para `/assinar/validar/{código}`, 'CÓDIGO DE VALIDAÇÃO', código (ou 'BJA-XXXX-XXXX-XXXX'), 'SHA-256 {hash em 2 linhas}' (ou 'gerado no envio do documento'), 'Confira em bluejus.com.br/assinar/validar ou leia o QR Code.' | sim | folha (salvo folha branca) | 351 |
| L7 | Mensagem do remetente | sim | saudação (H4) | 241 |
| L8 | Prazo / data de vencimento | **não** | (só o bloqueio 'Prazo encerrado' depois de vencer) | |
| L9 | Pasta, categoria, data de criação, situação, trilha, código no cabeçalho | **não** | | |
| L10 | Nome do escritório, usuário do escritório, quem enviou | **não** (o cabeçalho tem só a logo BlueJus); o escritório aparece só no que o próprio documento traz (logo e texto) | | 234 |
| L11 | Nome cadastrado do próprio signatário | sim | saudação (H4) e campo preenchido (H5) | 241-242 |
| L12 | CPF cadastrado do próprio signatário | sim (campo preenchido) | H6 | 243, 376 |
| L13 | E-mail do próprio signatário | **não** (o rótulo 'Código enviado ao e-mail' não mostra o endereço) | | 249 |
| L14 | Papel e nível do próprio signatário | **não** (o nível só define o formulário) | | |
| L15 | Nome do signatário anterior que falta assinar | sim, só no bloqueio de ordem (G9), nome cadastrado | | 437 |
| L16 | Outros signatários: rótulo do lugar de assinatura | sim: texto do modelo (`r`) ou, sem ele, o nome cadastrado | folha | 352, 354 |
| L17 | Outros signatários: imagem da rubrica | sim, de quem já assinou | folha | 352, 354 |
| L18 | Outros signatários: 'Assinado eletronicamente em {data e hora pt-BR}' (verde `#1e7b45`, 11.5px bold) | sim, de quem já assinou | folha | 352, 91 |
| L19 | Outros signatários: e-mail, CPF, papel, situação, método, lista de signatários, contagem | **não** | | |
| L20 | Motivo de recusa de outros | **não** | | |
| L21 | Código de 6 dígitos | **não** (no sistema só por e-mail; o aviso amarelo H16 é do protótipo) | | 250 |
| L22 | IP, dispositivo, evidências | **não** na tela; o aceite diz que 'Serão registrados data, hora, localização autorizada e dados de identificação.' | | 257 |

## M. Responsivo, hover, foco, animação, fontes e componentes base (L28-110)

| # | Região | Item | Valor do desenho (CSS/JS) | Componente | Estado(s) | L |
|---|---|---|---|---|---|---|
| M1 | Responsivo | Quebra natural | `.bjaCent` `flex:999 1 520px` + `.bjaLat` `flex:1 1 320px` + gap 16px: sem espaço para os dois, o painel desce para baixo da prévia | grade | | 56-58 |
| M2 | Responsivo | ≤980px | `.bjaLat { max-width:none; flex-basis:100%; }`, mas o inline `max-width:420px` da tela pública vence: o painel fica com até 420px, alinhado à esquerda (ver Dúvida 7a) | | ≤980px | 101, 239 |
| M3 | Responsivo | ≤760px | `.bjaOc` some (separador do cabeçalho); folha de 2 colunas: `padding:16px`, texto lateral some, 1 coluna | | ≤760px | 84, 103 |
| M4 | Responsivo | Prévia no celular | `.bjaPv` `background:#dfe6ec; padding:14px; max-height:84vh (inline); overflow:auto; box-sizing:border-box`: rola por dentro; o painel vem depois | | | 63, 238 |
| M5 | Responsivo | Folha | `.bjaPag` `aspect-ratio:1 / 1.414; padding:clamp(34px, 7%, 60px); max-width:720px; margin:0 auto; font-size:14px; line-height:1.55; box-shadow:0 2px 8px rgba(18,36,47,.2)` | folha (etapa 1) | | 68 |
| M6 | Responsivo | Camada de sucesso | título `clamp(28px, 5vw, 42px)`; anel `min(19vmin, 96px)`; `overflow-y:auto`; botões quebram linha | | | 268-271 |
| M7 | Hover | Botões | `.bjaB:hover` `#e5f1f7`/`#0a6fa0` · `.bjaN:hover` `#f3f6f8`/`#34505f` · `.bjaR:hover` `#fdecee`/`#7f1c23` · `.bjaP:hover` `#0b5f86`; E-mail e WhatsApp (`a.bjaB`) ficam `#0a6fa0` (`.bjaB:hover` vence `a:hover #0b5f86`); abas H7 e botões da camada J sem hover | | hover | 29-41 |
| M8 | Desabilitado | Botões | `.bjaB[disabled]` `opacity:.5; cursor:default` · `.bjaP[disabled]` `background:#7a8e9b`; o desenho não desabilita 'Assinar' nem 'Gerar links e enviar' | | | 34, 42 |
| M9 | Foco | Campos e botões | **nenhum estilo de foco** no desenho (foco padrão do navegador); nenhum atalho de teclado | | | |
| M10 | Animação | Lista | `bjaWipe` .6s (J1), `bjaBarOk` .5s (J3), `bjaDraw` .4s com atraso .3s (J4), `bjaUp` .2s (K1: de `opacity:0; translateY(8px)` a `none`) | | | 96-100 |
| M11 | Fonte | Corpo | `Arial, Helvetica, sans-serif` (página e camada de sucesso) | | | 28, 268 |
| M12 | Fonte | Assinatura digitada | tela `italic 30px Georgia, serif`; imagem `italic 76px Georgia, serif` | | | 247, 379 |
| M13 | Fonte | Códigos | campo `bold 16px monospace` + `letter-spacing:4px`; código BJA `bold 15px monospace` (camada) e `bold 17px monospace` (detalhe) | | | 249, 270, 222 |
| M14 | Base | `.bjaCard` | `background:#fff; border:1px solid #d3dde4; border-radius:3px; box-shadow:0 1px 3px rgba(18,36,47,.06)` | | | 30 |
| M15 | Base | `.bjaHd` | `display:flex; align-items:center; gap:9px; flex-wrap:wrap; padding:10px 16px; color:#fff; font-size:15.5px; font-weight:bold` | | | 31 |
| M16 | Base | `.bjaB` / `.bjaI` / `.bjaP` | `.bjaB` `height:32px; padding:0 11px; border:1px solid #0a6fa0; border-radius:3px; background:#fff; color:#0a6fa0; font-size:13px; inline-flex; gap:6px; nowrap; text-decoration:none` · `.bjaI` `width:32px; padding:0` · `.bjaN` borda `#d3dde4` texto `#34505f` · `.bjaR` borda `#a3232b` texto `#7f1c23` · `.bjaP` `height:38px; padding:0 16px; border:none; background:#0a6fa0; color:#fff; font-size:14px; gap:7px` | | | 32-42 |
| M17 | Base | `.bjaL` / `.bjaF` | `.bjaL` column, gap 4px, 12px bold `#4a6373` · `.bjaF` `height:34px; border:1px solid #d3dde4; border-radius:3px; padding:0 8px; font-size:13.5px; color:#12242f; background:#fff; width:100%` · `textarea.bjaF` `height:auto; padding:7px 8px; resize:vertical` | | | 44-46 |
| M18 | Tema | Tema comemorativo | `bj-tema.js` está no `<helmet>` da página toda: no protótipo recolore `#0a76a8`/`#0a6fa0` (inclusive o cabeçalho público) nas datas ligadas pelo Master | | ⚠ decidir se vale na tela pública | 14 |

## N. Textos da trilha na etapa 2

| # | Evento | Texto literal | L |
|---|---|---|---|
| N1 | criação no envio | 'Documento criado' | 374 |
| N2 | envio | 'Enviado para assinatura. Hash {16 primeiros hex}...' | 375 |
| N3 | lembrete | 'Lembrete enviado a {nome cadastrado}' | 430, 443 |
| N4 | cancelar envio | 'Envio cancelado. Documento e evidências preservados' | 462 |
| N5 | assinatura | 'Assinado por {nome digitado} · E-mail e código' | 387, 390 |
| N6 | último assinou | 'Documento concluído' | 390 |
| N7 | recusa | 'Recusado por {nome cadastrado}: {motivo}' | 467 |
| N8 | já existe (etapa 1) | 'Envio cancelado pela exclusão. Links desativados' | 364 |
| N9 | sem texto no desenho | abertura do link, código enviado, código conferido ou errado, aceite (a spec manda registrar) | |

## O. No mesmo bloco do desenho, mas de outras etapas (pendente, com nome)

| # | Item | Etapa | L |
|---|---|---|---|
| O1 | Reforçada: 'Enviar selfie com documento' / 'Selfie anexada', 'Localização: {lat, long}' / 'Localização não informada nesta pré-visualização.', método 'E-mail e código, selfie, localização {..}' | 7 | 251, 376, 387, 465 |
| O2 | gov.br e ICP: lista 'Baixar PDF para assinar', 'Assinar no portal gov.br' / 'Assinar com o seu certificado', 'Enviar PDF assinado', 'Depois de assinar, envie o PDF aqui.' e erros do PDF | 5 | 253-256, 385, 466 |
| O3 | Código BJA no detalhe, no rodapé da folha e na camada de sucesso | 3 | 222, 270, 351 |
| O4 | 'Baixar PDF com selo' (detalhe, camada de sucesso) e o PDF com manifesto | 3 | 222, 271, 392-425 |
| O5 | Vincular à pasta ao concluir (`vincExec`) | 3 | 390 |
| O6 | Quadro "Documento já assinado" no detalhe | 5 | 218 |

## P. Travessões

| # | Item | Situação |
|---|---|---|
| P1 | O dc.html tem 3 travessões (L418 e-mail vazio no manifesto, L428 e L430 célula vazia da lista), todos fora da etapa 2 e já tratados pela spec. Os textos da etapa 2 (regiões A a N) não têm travessão; usam '·' e '...'. Nada a reescrever. | ✓ |

---

## Dúvidas (para decidir antes de implementar)

1. **Signatário Simples sem e-mail** (omissão com consequência): o envio aceita ('Sem e-mail: envie o link'), mas a assinatura exige 'Código enviado ao e-mail'. Sem e-mail ninguém consegue assinar. Exigir e-mail no envio para Simples e Reforçada?
2. **CPF cadastrado vem preenchido na tela pública** (H6, L12): quem tiver o link vê o CPF, e a conferência 'O CPF não corresponde ao cadastrado...' perde o efeito. O nome também vem preenchido. Seguir o desenho ou deixar o CPF vazio?
3. **Botão E-mail do detalhe**: o desenho é `mailto:` (abre o e-mail do próprio advogado, E3); a spec da etapa 2 diz "E-mail (do servidor)". E o toast 'Documento pronto. Copie o link ou envie por e-mail.' sugere envio manual, mas a spec já manda o e-mail no "Gerar links e enviar".
4. **Código BJA é da etapa 3**, mas a etapa 2 mostra o lugar dele: detalhe ('Gerado no envio' num documento já enviado), rodapé da folha pública (código de exemplo e QR genérico) e camada de sucesso (J7, J9). O que mostrar na etapa 2?
5. **'Tela do signatário' (prévia do escritório)**: no protótipo dá para assinar pela prévia (código aparece no aviso amarelo). No sistema, prévia só de leitura? Senão o advogado assina pelo cliente, e abrir pelo token gravaria "abertura do link".
6. **Textos que o desenho não tem**: tela de link inválido (só toast), bloqueio para documento recusado por outro signatário (G13), código enviado (sem aviso), código vencido, tentativas esgotadas, teto diário, todos os e-mails (código, lembrete, próximo da fila, recusa, conclusão), confirmação do 'Cancelar envio' (sugestão em D9) e eventos novos da trilha (N9).
7. **Defeitos do desenho**: (a) inline `max-width:420px` anula o `@media (max-width:980px)` do painel (M2); (b) aviso amarelo com o código aparece também fora da prévia (H16); (c) o aceite fala em "localização autorizada" no Simples, que não coleta localização; (d) recusa sem nenhuma identificação: qualquer um com o link encerra o documento (I8); (e) `bjaUp` termina em `transform:none` e tira o `translateX(-50%)` do toast (a etapa 1 já corrigiu com `bjaToastUp`); (f) lembrete não existe no detalhe, só na lista e no menu (B).
