# Inventário do desenho: etapa 7 da Assinatura (nível Reforçada)

Desenho: `docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (475 linhas, inteiro, termina em `</html>`),
na cópia principal do repositório. Só o que é do nível Reforçada (código, selfie com documento e localização).
Coluna **L** = linha do dc.html. Textos entre aspas simples são literais do desenho (marcação ou JS). `{x}` = valor que
vem do dado. Cores e medidas copiadas do CSS e dos estilos em linha. O resto da tela pública (cabeçalho, formulário
Simples, recusa, tela verde) é o inventário da etapa 2 (`inventario-assinatura-etapa2-0911.md`, regiões F a M), que
vale por baixo deste.

Busca feita no desenho e nos `bj-*.js` da pasta: `selfie`, `reforcada`, `Reforçada`, `geoloc`, `getCurrentPosition`,
`camera`, `capture`, `localiza`, `menor`. A câmera da linha 275 (`mCam`, "Aponte a câmera para o QR Code") é a leitura
de QR da aba Validar (etapa 3), não a selfie. O `bj-assina-pdf.js` e o `bj-central-docs.js` não falam de selfie; o
`bj-assina-modelos.js` só traz os papéis de menor (região E).

Contagem por região: A 4 · B 9 · C 6 · D 6 · E 4 · F 4 · G 3 · H 8 · I 1 · total 45.

---

## A. Passo 3 do assistente: "Forma de assinatura" (L190, 292, 459)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| A1 | Campo | `label.bjaL` 'Forma de assinatura' + `select.bjaF` com as 4 opções de `NIV`, por signatário | campo do desenho (etapa 1 já entregou) | sempre | 190 |
| A2 | Opção Reforçada | valor `reforcada`, texto 'Reforçada: código, selfie e localização' | opção | | 292 |
| A3 | Padrão | 'Adicionar' e 'Incluir-me' nascem com `nivel: 'simples'`; os signatários do modelo também | | | 459 |
| A4 | Classe da lei | `lei: 'Eletrônica com múltiplos fatores'` (só o manifesto usa, etapa 3) | | | 292, 418 |

## B. Tela pública: bloco Reforçada (L251, 464-465)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| B1 | Onde | dentro do `sc-if aEletr` (Simples e Reforçada), depois da linha do código (H14 da etapa 2) e do aviso amarelo da prévia; antes do aceite. Os dois elementos ficam soltos na coluna do painel (`display:flex; flex-direction:column; gap:11px`), sem caixa em volta | | `aRef` = nível do signatário é `reforcada` | 251, 464 |
| B2 | Botão da selfie | `label.bjaB.bjaN` `height:38px; cursor:pointer`; ícone `bi-camera`; estica na largura do painel (filho da coluna flex) | botão neutro do desenho | | 251 |
| B3 | Texto do botão | 'Enviar selfie com documento'; depois de escolher a foto, 'Selfie anexada' | | sem foto / com foto | 465 |
| B4 | Campo de arquivo | `input type="file" accept="image/*" capture="user"` escondido (`display:none`) dentro do `label`: o clique no botão abre a câmera frontal (ou a galeria) | | | 251 |
| B5 | Ao escolher | guarda a foto e limpa o erro do painel (`erro: ''`); escolher de novo troca a foto | | | 465 |
| B6 | Linha da localização | `div` `font-size:12px; color:#5b7383` logo abaixo do botão | texto do desenho | | 251 |
| B7 | Localização obtida | 'Localização: {latitude}, {longitude}' com 5 casas (`toFixed(5)`), separador ponto | | `geo` preenchida | 465, 376 |
| B8 | Sem localização | 'Localização não informada nesta pré-visualização.' (enquanto espera e quando o navegador recusa) | | `geo` nula ou vazia | 465 |
| B9 | Atributo `data-sem-central` | o protótipo não manda a selfie para a Central de documentos (`bj-central-docs.js`, L43) | | | 251 |

## C. Localização (L376)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| C1 | Quando pede | ao abrir a tela do signatário Reforçada (`abrirAss`), também na prévia | | | 376 |
| C2 | Como pede | `navigator.geolocation.getCurrentPosition(ok, erro, { timeout: 6000 })`; sem `enableHighAccuracy` nem `maximumAge` | | | 376 |
| C3 | Permitiu | `geo = latitude.toFixed(5) + ', ' + longitude.toFixed(5)`; a precisão não é guardada | | | 376 |
| C4 | Recusou ou expirou | `geo = ''` (a linha B8) | | | 376 |
| C5 | Sem geolocalização no navegador | nada é pedido; fica B8 | | | 376 |
| C6 | Assinar sem localização | o protótipo deixa: o método sai sem a parte da localização (D3) | | | 387 |

## D. Assinar (L386-390)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| D1 | Ordem das conferências | as da etapa 2 (nome, CPF, CPF do cadastro, rubrica, código pedido, código certo); depois 'Envie a selfie com o documento.' (sem foto); por último 'Marque a confirmação de leitura.' | erro do painel (H18 da etapa 2) | | 386-388 |
| D2 | Localização | não é conferida no `assinar()` | | omissão (ver H3) | 387 |
| D3 | Método gravado | 'E-mail e código, selfie, localização {geo}'; sem localização, 'E-mail e código, selfie' | | | 387 |
| D4 | Registro | `selfie: 1` no signatário (o protótipo guarda só o nome do arquivo); o resto igual ao Simples (data, rubrica, nome e CPF digitados) | | | 390 |
| D5 | Trilha | 'Assinado por {nome digitado} · {método}' | | | 390 |
| D6 | Efeito | o mesmo do Simples: som, vibração, tela verde (J da etapa 2) | | | 389-391 |

## E. Papéis de menor nos modelos (bj-assina-modelos.js)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| E1 | Procuração de menor | signatários 'Responsável legal' e 'Menor assistido(a)' (este só quando `ato=assistido(a)`) | | | modelos, 9048-9215 |
| E2 | Outros textos | 'Menor Representado ou Assistido', 'E-mail do(a) menor (se assistido)' | | | modelos |
| E3 | Nível do menor | nasce Simples como todos; o desenho não impede Reforçada para o menor | | omissão (ver H4) | 459 |
| E4 | Papel | texto livre no passo 3: o escritório pode mudar o nome do papel | campo | | 190 |

## F. Detalhe do documento (L463)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| F1 | Papel e nível | '{papel} · Reforçada: código, selfie e localização' (12.5px `#5b7383`, C7 da etapa 2) | | sempre | 463 |
| F2 | Quem assinou | '{data e hora pt-BR} · E-mail e código, selfie, localização {lat}, {long}' (C8 da etapa 2, com o método D3) | | assinado | 463 |
| F3 | Selfie no detalhe | **não aparece**: o detalhe não mostra a foto, nem link para ela | | | 463 |
| F4 | Trilha do detalhe | o texto D5 | | | 390 |

## G. Manifesto (L418, etapa 3)

| # | Item | Valor do desenho | Componente | Estado(s) | L |
|---|---|---|---|---|---|
| G1 | Tipo | 'Tipo: Eletrônica com múltiplos fatores · {método}' | | etapa 3 | 418 |
| G2 | Selfie no manifesto | **não aparece** | | | 418 |
| G3 | Trilha no manifesto | os mesmos textos da trilha | | etapa 3 | 418 |

## H. O que o desenho não mostra (omissões com consequência)

| # | Omissão | Consequência |
|---|---|---|
| H1 | Aviso de LGPD antes da selfie | a selfie é dado pessoal e pode virar biométrico (LGPD art. 5º, II); a lei pede informar finalidade antes de colher (art. 9º). O desenho só tem o aceite 'Serão registrados data, hora, localização autorizada e dados de identificação.' (L257) |
| H2 | Estado de espera da localização e texto da recusa fora da prévia | B8 diz "nesta pré-visualização" também no link do signatário |
| H3 | Localização recusada | o protótipo assina sem ela (C6); a Reforçada promete 'código, selfie e localização' |
| H4 | Menor com Reforçada | a ESPEC manda não colher dado de criança sem cuidado (LGPD art. 14) |
| H5 | Limite e tipo da foto | o protótipo aceita qualquer arquivo (só guarda o nome) |
| H6 | Ver a selfie depois | nenhum lugar do desenho mostra a foto guardada (F3, G2) |
| H7 | Prévia do escritório | o botão da selfie funciona no protótipo; a etapa 2 deixa a prévia só de leitura (decisão E) |
| H8 | Sem chave de cifra no servidor | não existe no protótipo |

## I. Travessões

| # | Item | Situação |
|---|---|---|
| I1 | Os textos da Reforçada (regiões A a G) não têm travessão; usam ',' e '·'. Nada a reescrever. | ✓ |
