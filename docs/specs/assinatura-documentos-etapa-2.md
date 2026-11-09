# Assinatura de documentos: etapa 2 (contrato)

Etapa 2 da spec `docs/specs/assinatura-documentos.md` (seção 4): envio e assinatura Simples. Contrato da etapa 1:
`docs/specs/assinatura-documentos-etapa-1.md` (vale tudo o que não for mudado aqui). Desenho:
`docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (cópia principal do repositório). Inventário
item a item, com a linha do desenho: `docs/design/redesign-08-11-26/inventario-assinatura-etapa2-0911.md` (as
referências A1, C11, E2, H5... abaixo são dele).

## 1. Escopo

Entra:
- "Gerar links e enviar" no passo 4 e no "Continuar e enviar" do rascunho (inventário A);
- detalhe do documento enviado: Copiar link, E-mail (`mailto:`), WhatsApp, Tela do signatário (prévia), Cancelar
  envio com confirmação, cartão do código no estado "Gerado no envio" (inventário C, D, E);
- lembrete ao primeiro pendente: ícone da linha e menu de contexto da lista (inventário B, E);
- tela pública `/assinar/{token}` com todos os estados, assinatura Simples (código por e-mail, desenhar ou digitar,
  aceite), recusa com motivo e "Assinatura registrada" (inventário F a J, L, M);
- e-mails: convite, código, lembrete, aviso ao próximo da fila, recusa e conclusão ao remetente;
- evidências na trilha encadeada (inventário N).

Fica para depois (não aparece até a etapa dele):
- código `BJA-...`, QR de validação, "Baixar PDF com selo", aba Validar, Vincular à pasta (etapa 3);
- níveis gov.br, ICP-Brasil e Reforçada (etapas 5 e 7): o envio recusa signatário com nível diferente de Simples.

A tela, inclusive a pública, continua atrás da chave `assinatura` do Master até a etapa 3. **Sem migration**: tudo cabe
nas colunas da M1 e no `dados` dos eventos.

## 2. Decisões

Registro: `bluejus-coordenacao/decisoes-assinatura-etapa2-0911.md` (decisor imparcial pelas 3 regras do dono; T1
aprovado pelo Coordenador).

- **A.** Signatário Simples precisa de e-mail: o envio recusa com "Informe o e-mail de {nome} para enviar o código de
  assinatura." na linha de erro do assistente (A7). Nome e CPF válidos também são conferidos no servidor.
- **B.** A tela pública abre com o nome e o CPF cadastrados preenchidos, como o desenho; a conferência com o CPF
  cadastrado é do servidor.
- **C.** "E-mail" do detalhe é `mailto:` (E3). O convite automático e o lembrete saem pelo servidor.
- **D.** Sem código `BJA` na etapa 2: o detalhe mostra "Gerado no envio"; a folha fica no estado sem código do desenho,
  com o SHA-256 real de `hash_enviado`; a tela "Assinatura registrada" sai sem a caixa do código e sem "Baixar PDF
  com selo".
- **E.** Prévia do escritório só de leitura: botões visíveis e desabilitados, faixa e "Fechar pré-visualização" (F6);
  nenhum POST, nenhum código, nenhum evento.
- **F.** Texto que o desenho não tem (link inválido, recusado por outro, estados do código, e-mails, confirmação do
  Cancelar envio, eventos novos): no estilo e nos componentes do próprio desenho, o mínimo.
- **G1** `max-width:420px` como o desenho; **G2** o código nunca aparece na tela, no HTML, no JSON nem na trilha;
  **G3** texto do aceite como o desenho; **G4** recusa sem identificação, como o desenho.
- **T1, T2, T3**: seções 3 e 6.

## 3. Segredo, token e código

- Variável `ASSINATURA_SEGREDO` (segredo próprio, nunca o `APP_SECRET`), no mínimo 32 bytes. `app/.env` vazia;
  `app/.env.test` com valor fixo; `.env.prod.example` com o nome e um comentário; dev: o Samuel grava no `.env.local`.
  Parâmetro `assinatura.segredo` (`%env(string:default::ASSINATURA_SEGREDO)%`). Versão da chave: constante
  `ChaveDaAssinatura::VERSAO = 1` (troca futura acrescenta a versão nova sem apagar a antiga).
- Segredo vazio ou curto: o envio, o Copiar link (campo `link` nulo no Detalhe) e o pedido de código recusam com
  "Assinatura eletrônica sem chave configurada. Fale com o suporte."; a página pública abre (só precisa do sha256).
- **Token** = hex(HMAC-SHA256(segredo, `assinar|v{versao}|token|{signatario_id}|{enviado_em ISO 8601 UTC}`)), 64
  caracteres. Banco: `token_hash` = sha256(token). Rota: requisito `[a-f0-9]{64}`. O token nunca é gravado, logado nem
  posto em fila; só aparece no Detalhe (`link`), no e-mail e na URL.
- **Token conferido**: todo lugar que recalcula o token (Copiar link do Detalhe, convite, lembrete, aviso da vez) usa
  `ChaveDaAssinatura::tokenConferido`, que só devolve o token quando `sha256(token) === token_hash` do envio. Não bateu
  (segredo trocado depois do envio): `link` nulo no Detalhe (Copiar link, E-mail e WhatsApp mostram o aviso de sem
  chave) e o e-mail não sai (log sem o token e o evento `email_nao_enviado`; o lembrete recusa com o aviso de sem chave).
- **Trocar o segredo não derruba os links já enviados**: a página acha o signatário pelo `token_hash` guardado e abre
  até o fim do prazo. Nesses documentos param o Copiar link, o lembrete e o aviso da vez; o código pedido antes da troca
  deixa de conferir (o signatário pede outro). Para cortar um link vazado: Cancelar envio e enviar de novo (Duplicar).
  Detalhes no runbook `docs/runbooks/assinatura-segredo-em-producao.md`.
- **Token fora do log**: o processador Monolog `App\AssinaturaEletronica\Log\MascaraDoTokenNoLog`
  (`#[AsMonologProcessor]`, todos os canais) troca `/assinar/<64 hex>` por `/assinar/****` na mensagem, no contexto e
  no extra, em qualquer profundidade, e dentro de `route_parameters` troca o `token` por `****` (e qualquer sequência
  de 64 hex, como o `resto` da rota de reserva). É o "Matched route" do RouterListener, que o `fingers_crossed` de
  produção despeja junto com um erro. Objetos de exceção no contexto não são reescritos.
- **Código**: `random_int` de 6 dígitos; `codigo_hash` = HMAC-SHA256(segredo, `assinar|v{versao}|codigo|{signatario_id}|{codigo}`);
  vale 15 min a partir de `codigo_enviado_em`; 5 erros queimam o código (`codigo_hash` nulo, pede outro); teto de 10
  envios e 15 erros em 24 h por signatário, contados pelos eventos da trilha; passado o teto, `bloqueado_ate` até o fim
  da janela. Conferência com `hash_equals`.
- Toda evidência gravada pela etapa 2 leva `dados.chave = 1` (a versão), além do que cada evento pede.

## 4. Domínio (`app/src/AssinaturaEletronica/`)

### 4.1 Transições

- `DocumentoParaAssinar::enviar(hash, agora)`: só Rascunho, fora da Lixeira, com pelo menos 1 signatário; vira
  Aguardando, `enviado_em = agora`, `vence_em = agora + prazo_dias`, `hash_enviado`. Conteúdo congelado (a etapa 1 já
  só deixa alterar rascunho).
- Assinar: só Aguardando, prazo não vencido, signatário Pendente, e com `em_ordem` todos os anteriores Assinados. O
  último que assina conclui o documento (Concluído, `concluido_em`).
- Recusar: só Aguardando e signatário Pendente da vez; o documento vira Recusado.
- Cancelar envio: só Aguardando; vira Cancelado. Mover para a Lixeira um Aguardando continua gravando
  `envio_cancelado_pela_exclusao` (etapa 1).
- Prazo vencido não muda a situação: é calculado (`agora > vence_em`).
- Toda ação pública roda numa transação que trava a linha do documento (PESSIMISTIC_WRITE), relê documento e
  signatário depois da trava e só então decide. Tenant lido do próprio documento.

### 4.2 Hash do conteúdo (T2)

`hash_enviado` = sha256 do JSON canônico (chaves ordenadas, sem espaços, UTF-8): `{formato: 1, nome, blocos, layout,
lateral, folhaBranca, emOrdem, mensagem, prazoDias, logo: {tipo, sha256|null}, arquivo: {sha256, paginas}|null,
signatarios: [{ordem, nome, email, cpf, papel, nivel}]}`. Serviço `ConteudoCanonicoDoDocumento`, testado com valor
fixo. PDF para assinar e PDF final: etapa 3.

### 4.3 Trilha

`TrilhaDoDocumento::acrescentar` passa a receber signatário e evidência (`EvidenciaDaRequisicao`: IP por
`getClientIp()`, porta em `dados.porta`, navegador cortado em 500, hora do servidor pelo relógio do sistema). Tipos
novos em `TipoDeEvento` (valor = nome em snake_case) e o texto no detalhe, no estilo do inventário N:

| Tipo | Texto | `dados` (além de `chave`) |
|---|---|---|
| `documento_enviado` | Documento enviado para assinatura | `{hash}` |
| `convite_enviado` | Convite enviado por e-mail a {nome} | `{para: e-mail mascarado}` |
| `email_nao_enviado` | E-mail não enviado a {nome} | `{motivo: 'falha_no_envio'}` |
| `link_aberto` | {nome} abriu o link | (uma vez por signatário) |
| `codigo_enviado` | Código de verificação enviado a {nome} | `{para: e-mail mascarado}` |
| `codigo_errado` | Código incorreto informado por {nome} | `{tentativa}` |
| `assinado` | {nome} assinou (Simples) | `{metodo: 'simples', modo: 'desenhada'|'digitada', rubrica_sha256, aceite: {versao, sha256}, cpf_confere: true}` |
| `recusado` | {nome} recusou: {motivo} | `{motivo}` |
| `documento_concluido` | Documento concluído: todas as assinaturas registradas | |
| `lembrete_enviado` | Lembrete enviado a {nome} | `{para}` |
| `envio_cancelado` | Envio cancelado | |

O código, o token e o link nunca entram em `dados`.

Os eventos de entrega (`convite_enviado`, `codigo_enviado`, `lembrete_enviado`) só são gravados depois que o e-mail
saiu. Falhou o envio ou o link não confere com o segredo (seção 3): só `email_nao_enviado` (o `motivo` continua
`falha_no_envio`; o log diz `link_indisponivel` ou a classe da exceção). Por isso o lembrete que falhou não gasta a
regra de 1 por hora, que conta os `lembrete_enviado`.

## 5. Rotas

### 5.1 Internas (prefixo de nome `assinatura_eletronica_`, `canAccessModule`, CSRF como a etapa 1)

| Método e caminho | Nome | Corpo | Resposta |
|---|---|---|---|
| POST `/assinatura/documentos/{id}/enviar` | `enviar` | | `{ok: true, documento: Detalhe}` ou 422 `{erro}` |
| POST `/assinatura/documentos/{id}/lembrete` | `lembrete` | | `{ok: true, para: nome}` ou 422 `{erro}` (sem pendente; 1 por hora por documento) |
| POST `/assinatura/documentos/{id}/cancelar` | `cancelar` | | `{ok: true, documento: Detalhe}` ou 422 |
| GET `/assinatura/documentos/{id}/previa/{ordem}` | `previa` | | página pública em modo prévia (seção 5.3) |
| GET `/assinatura/documentos/{id}/rubrica/{ordem}` | `rubrica` | | PNG da rubrica de quem já assinou |

O JS salva o rascunho (rota da etapa 1) antes de chamar `enviar`. `Detalhe` ganha `hashEnviado`, `enviadoEm`,
`venceEm`, `concluidoEm` e, por signatário, `link` (URL absoluta; só com documento Aguardando, signatário Pendente e
segredo configurado; senão nulo), `rubrica` (URL ou nulo), `recusadoMotivo` (ou nulo) e `nomeDigitado` (o nome
digitado ao assinar, item C5 do inventário; ou nulo). `data-url-enviar`,
`-lembrete`, `-cancelar`, `-previa`, `-rubrica` entram na raiz `.bja` (marcadores `0` para id e ordem).

### 5.2 Públicas (prefixo de nome `assinatura_eletronica_publica_`)

| Método e caminho | Nome | Corpo | Resposta |
|---|---|---|---|
| GET `/assinar/{token}` | `publica` | | página (seção 5.3) |
| POST `/assinar/{token}/codigo` | `publica_codigo` | | `{ok: true}` ou 422/429 `{erro}` |
| POST `/assinar/{token}` | `publica_assinar` | JSON `{nome, cpf, codigo, modo, rubrica (data URL PNG), aceite: true}` | `{ok: true, estado: Publico}` ou 422/429 `{erro}` |
| POST `/assinar/{token}/recusa` | `publica_recusa` | JSON `{motivo}` (1 a 500) | `{ok: true, estado: Publico}` ou 422 |
| GET `/assinar/{token}/arquivo` | `publica_arquivo` | | PDF enviado (inline, nome neutro `documento.pdf`) |
| GET `/assinar/{token}/logo` | `publica_logo` | | logo própria do documento |
| GET `/assinar/{token}/rubrica/{ordem}` | `publica_rubrica` | | PNG da rubrica de quem já assinou |

- `security.yaml`: `^/assinar/` com `PUBLIC_ACCESS`. Os 7 nomes entram nas listas de `TenantContextValidatorListener`
  e `TermoAceiteListener`. Um listener (prioridade 3, como o do Push) desliga o filtro de escritório pelo nome da rota:
  exatamente `assinatura_eletronica_publica` ou começando com `assinatura_eletronica_publica_` (com o sublinhado; um
  nome como `assinatura_eletronica_publicacao_x` não desliga); o documento é achado por `token_hash` com a Lixeira
  visível.
- Token malformado, desconhecido, de documento purgado ou de signatário que não existe: a mesma página "Link
  inválido." com 404. Documento na Lixeira ou Cancelado: "Envio cancelado". O token malformado cai numa rota de
  reserva, `publica_invalido` (GET e POST `/assinar/{resto}`, prioridade -10), que também entra nas duas listas.
- Toda resposta de rota pública ou cujo caminho começa com `/assinar` (inclusive 404, 405, 422 e 429, e o 404 de
  `/assinar` sem barra, que não casa rota nenhuma): `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`,
  `Referrer-Policy: no-referrer`.
- CSRF: token de sessão com id `assinar_{token_hash}` (nunca o token cru), no cabeçalho `X-CSRF-Token`.
- Limitadores em `config/packages/assinatura_eletronica.yaml`: na página e nos POST, `assinatura_publica` 30/min por
  IP e `assinatura_publica_token` 60 por 10 min por `token_hash`; nos arquivos (GET `arquivo`, `logo` e `rubrica`, que
  a página pede várias vezes), o par próprio `assinatura_publica_arquivos` 600/min por IP e
  `assinatura_publica_arquivos_token` 300 por 10 min por `token_hash`, sem gastar a cota da página;
  `assinatura_codigo_envio` 3 por 15 min por signatário; teto diário pela trilha (seção 3). Estourou: 429 com "Muitas tentativas. Aguarde alguns minutos e tente
  de novo.".
- Rubrica: PNG (assinatura de arquivo conferida), até 200 KB, gravada na categoria `ASSINATURA_DOCUMENTO` pela
  fábrica `ChavesDeAssinatura` e `TransacaoComArquivoNovo`; `rubrica_chave` no signatário; sha256 no evento. A rubrica
  digitada também chega como PNG (o JS desenha o nome na fonte escolhida).
- `link_aberto` grava uma vez por signatário (não a cada recarga).

### 5.3 O que a página pública recebe

Template próprio, sem a moldura do sistema (cabeçalho do desenho, inventário F). Atributo `data-estado` com o JSON
`Publico`, `data-csrf`, e as URLs `data-url-*` das rotas da seção 5.2 (na prévia, nenhuma URL de POST).

```
Publico = { estado: 'pronto'|'aguardando_anterior'|'prazo_encerrado'|'cancelado'|'recusado'|'concluido'|'ja_assinado'|'invalido',
            previa (bool), anterior (nome cadastrado de quem falta assinar antes)|null,
            documento: { nome, mensagem, blocos, layout, lateral, folhaBranca, logo: {tipo, url|null},
                         arquivo: {url, paginas}|null, hashEnviado },
            lugares: [ { rotulo, rubrica (url|null), assinadoEm (ISO|null) } ],   na ordem dos signatários
            eu: { nome, cpf, posicao (índice em lugares) }|null,
            aceite: { versao, texto },
            codigo: { enviado (bool), validoAte (ISO|null), bloqueadoAte (ISO|null) } }
```

Nada além disso (inventário L): sem nome do escritório, usuário, pasta, categoria, prazo, situação, trilha, e-mail,
papel ou nível; sem e-mail, CPF, papel, situação ou motivo de outros signatários; sem ids internos. `rotulo` = texto
`r` do bloco de assinatura daquele signatário ou, sem ele, o nome cadastrado. Estado `invalido` não traz `documento`.
Texto do aceite versionado (`aceite.versao` = 1) no catálogo, sha256 na trilha.

`estado` = `recusado` só quando ESTE signatário recusou (texto G6). Documento recusado por OUTRO signatário devolve
`cancelado` (texto G7, sem dizer quem recusou). Nos estados de bloqueio, `documento` vem só com `nome` e `hashEnviado`
(o cabeçalho), sem blocos, mensagem, layout, logo nem arquivo; `lugares` vazio e `eu` nulo. Os arquivos públicos (PDF, logo,
rubricas) só abrem no estado `pronto`.

## 6. E-mails (Mailer síncrono, depois do COMMIT)

Remetente pelo serviço de identidade do sistema; nome "{Escritório} via BlueJus". Reply-To: no convite e no lembrete,
o usuário que clicou; no aviso da vez e no código, quem enviou o documento (o `usuario` do último `documento_enviado`);
sem ele, sem Reply-To.
Molde de `templates/email`. Convite (assunto "Documento para assinatura: {nome do documento}", mensagem do escritório
escapada, link), código (6 dígitos, validade de 15 min, sem link), lembrete, aviso ao próximo da fila (com `em_ordem`),
recusa e conclusão ao remetente (`criado_por`; nulo, não manda). Com `em_ordem` o convite vai só ao primeiro; sem,
a todos. Falha num destinatário (ou link que não confere com o segredo, seção 3) vira log sem o token e o evento
`email_nao_enviado`, sem desfazer nada; o evento de entrega só é gravado depois do envio (seção 4.3). O nome do
escritório no cabeçalho da moldura sai escapado uma vez só (nada de filtro sobre `block()`).

## 7. Tela interna e tela pública (JS e CSS)

- Componentes e valores do desenho (inventário A a M), textos pelo catálogo (`assinatura_eletronica_tela` para a
  interna; `assinatura_eletronica_publica` para a pública), chave dinâmica com `[...].join('.')`.
- Passo 4 e "Continuar e enviar": salvar, enviar, toast "Documento pronto. Copie o link ou envie por e-mail.", abrir o
  detalhe. Erro do servidor na linha de erro do assistente.
- Detalhe: Copiar link, E-mail (E3), WhatsApp (E2, sem número), Tela do signatário (abre a prévia em nova aba),
  Cancelar envio com o modal do Excluir (D) e o texto "Cancelar o envio? As assinaturas já colhidas deixam de valer e
  os links param de funcionar." com os botões do modal.
- Lista: ícone de lembrete na linha e item no menu de contexto (B), só com documento Aguardando.
- Pública: estados de bloqueio (G), formulário Simples (H) com canvas e digitação, recusa (I), "Assinatura
  registrada" (J) sem código e sem PDF; o código só no e-mail.
- pdf.js da etapa 1 (cdnjs com SRI) também na pública, para a prévia das páginas do PDF.

## 8. Divisão do trabalho

- **Servidor (A)**: `app/src/AssinaturaEletronica/**` (inclui o controller público e o listener), `app/templates/
  assinatura_eletronica/publica.html.twig` (esqueleto com os `data-*` da seção 5.3 e as tags de CSS e JS com os nomes
  da seção abaixo), `app/templates/email/assinatura/**`, `app/translations/assinatura_eletronica/**` (textos do
  servidor e dos e-mails), `app/config/packages/security.yaml` (só a linha `^/assinar/`), `app/config/packages/
  assinatura_eletronica.yaml`, `app/config/services.yaml` (só o parâmetro do segredo), `app/.env`, `app/.env.test`,
  `.env.prod.example`, os dois listeners de listas de rotas, `app/tests/AssinaturaEletronica/**` (exceto os de tela).
- **Tela (B)**: `app/public/js/assinatura.js`, `app/public/js/assinatura-publica.js` (novo), `app/public/css/
  assinatura.css`, `app/public/css/assinatura-publica.css` (novo), `app/translations/assinatura_eletronica_tela/**`,
  `app/translations/assinatura_eletronica_publica/**` (novo), `app/templates/assinatura_eletronica/index.html.twig`
  (só os `data-url-*` novos), `app/tests/Arquitetura/templates-extraidos.json` (bloco da assinatura),
  `app/tests/AssinaturaEletronica/Functional/*Tela*Test.php`.

## 9. Testes

- Unit: token e código (valores fixos), conteúdo canônico (valor fixo), transições (ordem, prazo, conclusão, recusa,
  cancelamento), evidência.
- Functional internas: 403 sem módulo, 404 de outro escritório, 422 por situação, e-mail exigido, nível não Simples
  recusado, `link` nulo sem segredo, lembrete 1 por hora, cancelar.
- Functional públicas: token inválido, desconhecido e malformado dão a mesma 404; cabeçalhos em toda resposta; CSRF;
  429 dos limitadores; código errado 5 vezes queima; código vencido; teto diário; ordem; prazo; cancelado (inclusive
  na Lixeira); recusado; concluído; prévia não grava nada; e-mails enviados (asserções do Mailer); a resposta pública
  não contém nome do escritório, e-mail, CPF ou nome de outro signatário fora de `lugares`, nem ids.
- `node --check` em todo JS novo ou tocado; arranjo por filho direto nas duas telas.

## 10. Produção (pendência do Samuel)

Antes de ligar a chave `assinatura` (etapa 3): criar `ASSINATURA_SEGREDO` em produção. Passo a passo em
`docs/runbooks/assinatura-segredo-em-producao.md`.
