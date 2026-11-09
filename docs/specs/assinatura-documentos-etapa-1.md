# Assinatura de documentos: etapa 1 (contrato)

Etapa 1 da spec `docs/specs/assinatura-documentos.md` (seção 4): domínio, módulo, lista e assistente com rascunho.
Este arquivo fixa os contratos entre o servidor e a tela antes da implementação. Desenho:
`docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (cópia principal do repositório).

## 1. Escopo

Entra:
- tela `/assinatura` (moldura do sistema, `base.html.twig`), uma página que o JS desenha a partir de JSON, como a Agenda 1A;
- aba **Documentos** inteira (chips com contador, busca, pasta, cor, seleção, menu de contexto, Lixeira, Duplicar,
  Excluir definitivamente só rascunho);
- aba **Modelos** só com os 13 modelos prontos (clicar abre o passo 2);
- assistente **Novo documento**, passos 1 a 4: modelo pronto, PDF ou .docx, Dados com prévia A4, Signatários,
  Enviar (mensagem, prazo, resumo) com **Salvar rascunho**;
- **detalhe** do documento em leitura (Signatários, cartão do código com "Continuar e enviar" no rascunho, Trilha de
  auditoria, prévia), sem "Excluir": o desenho não o tem no detalhe; excluir fica no menu de contexto e na barra de
  seleção da lista;
- módulo `assinatura_eletronica`, recurso `assinatura` do Master, migration M1 (`Version20261109150000`).

Fica para depois (o botão ou a aba **não aparece** até a etapa dele; nada de botão morto):
- "Gerar links e enviar", Copiar link, Enviar lembrete, Cancelar envio, tela pública (etapa 2);
- "Baixar PDF com selo", aba Validar, Vincular à pasta (etapa 3);
- "Criar novo modelo", "Duplicar e adaptar", editor (etapa 4);
- quadro "Documento já assinado" (etapa 5).

A tela fica atrás da chave `assinatura` do Master, desligada, até a etapa 3.

## 2. Decisões de implementação

- **Blocos montados no navegador.** O desenho guarda no documento só os blocos já resolvidos (`retomar()` volta ao
  passo 3 com `up: d.blocos`); os valores dos campos não são guardados. O sistema faz igual: o JS do desenho monta os
  blocos (modelos, `[[campo]]`, cláusulas, condições) e o servidor recebe, **valida a estrutura** e guarda. Não há
  motor de modelos em PHP. O conteúdo sempre sai escapado (Twig e `textContent`); `{b}...{/b}` é o único realce.
- **Modelos prontos em JS.** `bj-assina-modelos.js` vira `public/js/assinatura-modelos.js` sem as duas logos embutidas
  e sem os valores fixos do escritório do Farlei. A logo BlueJus vira arquivo em `public/images/assinatura/`.
- **PDF enviado guardado inteiro** (categoria nova `ASSINATURA_DOCUMENTO`, isolamento por escritório). A prévia
  desenha as páginas com o pdf.js 3.11.174 (o mesmo do desenho, do cdnjs com `integrity`), a partir do arquivo local
  antes de salvar e da URL do servidor depois. No bloco, a página vira `{k:'pg', n:<página>}` em vez da imagem em
  base64 do protótipo.
- **.docx**: só o texto, no navegador, com o `mammoth` já vendorizado em `public/js/vendor/mammoth` (como o desenho).
  O arquivo .docx não é enviado.
- **Arquivo vai junto do rascunho.** Não existe upload solto: "Salvar rascunho" manda `multipart` com o JSON e, se
  houver, o PDF e a logo própria. Nada fica gravado antes de salvar (o desenho também não grava). O PDF só fica
  guardado enquanto algum bloco é página dele (`pg`): salvar sem nenhum bloco `pg` tira o PDF do documento (o arquivo
  sai do disco depois do COMMIT) e o `Detalhe` volta com `arquivo: null`; Duplicar só copia o PDF quando há `pg`.
- **Logo**: `bluejus` (arquivo estático), `escritorio` (a logo de Meu Escritório, se houver) ou `propria` (enviada
  no documento). `escritorio` sem logo cadastrada em Meu Escritório é gravado como `bluejus` (a tela nem oferece a
  opção). "Usar esta logo nos próximos documentos" grava a logo padrão **do escritório** numa tabela própria
  (não mexe em `Tenant`) e, desmarcada, limpa o padrão (volta à BlueJus), como o `tgLogoFix` do desenho. A imagem da
  logo própria padrão é sempre uma cópia só dela: ao ser trocada ou limpa, sai do disco depois do COMMIT (nenhum
  documento aponta para ela).
- **Cor da linha**: por documento, como o protótipo.
- **Expirado**: calculado no JS (Aguardando com prazo vencido), como o desenho.
- **Trilha**: só acréscimo, encadeada por `hash_anterior`. Texto de cada evento sai do servidor.

## 3. Domínio (`app/src/AssinaturaEletronica/`)

### 3.1 Enums (`Enum/`)

| Enum | Casos (valor) |
|---|---|
| `SituacaoDoDocumento` | `Rascunho` rascunho, `Aguardando` aguardando, `Concluido` concluido, `Recusado` recusado, `Cancelado` cancelado |
| `SituacaoDoSignatario` | `Pendente` pendente, `Assinado` assinado, `Recusado` recusado |
| `NivelDeAssinatura` | `Simples` simples, `Reforcada` reforcada, `Govbr` govbr, `Icp` icp |
| `CorDaLinha` | `Nenhuma` '', `Azul` azul, `Verde` verde, `Amarelo` amarelo, `Laranja` laranja, `Vermelho` vermelho, `Roxo` roxo, `Cinza` cinza |
| `TipoDeLogo` | `Bluejus` bluejus, `Escritorio` escritorio, `Propria` propria |
| `TipoDeEvento` | `RascunhoSalvo` rascunho_salvo, `DocumentoCriado` documento_criado, `Duplicado` duplicado, `MovidoParaLixeira` movido_para_lixeira, `Restaurado` restaurado, `EnvioCanceladoPelaExclusao` envio_cancelado_pela_exclusao |

Textos dos eventos (literais do desenho): "Rascunho salvo", "Documento criado", "Duplicado de {nome}",
"Movido para a Lixeira. Evidências preservadas", "Restaurado da Lixeira",
"Envio cancelado pela exclusão. Links desativados". As etapas seguintes acrescentam casos.

### 3.2 Tabelas (M1 `Version20261109150000`)

Todas com `tenant_id` entram no `PurgarEscritorioUseCase`.

- `assinatura_eletronica_documento` (`DocumentoParaAssinar`, TenantAware, Descartavel, Auditavel): id, tenant_id,
  criado_por_id (user, SET NULL), nome (200), pasta_rotulo (200, nulo), modelo_chave (60, nulo), categoria (80),
  blocos (JSON), layout ('' ou '2col'), lateral (texto, nulo), folha_branca (bool), logo_tipo (enum), logo_chave
  (nulo), em_ordem (bool), mensagem (texto), prazo_dias (smallint, 1 a 90, padrão 7), situacao (enum), cor (enum),
  criado_em, alterado_em, enviado_em (nulo), vence_em (nulo), concluido_em (nulo), hash_enviado (char 64, nulo),
  arquivo_enviado_chave (nulo), arquivo_enviado_nome (nulo), arquivo_enviado_paginas (nulo), excluido_em, excluido_por_id.
- `assinatura_eletronica_signatario` (`Signatario`): id, documento_id (CASCADE), ordem, nome (200), email (180, nulo),
  cpf (11, nulo), papel (120), nivel (enum), situacao (enum), e as colunas da etapa 2 já criadas aqui, todas nulas:
  token_hash (char 64, único), codigo_hash, codigo_enviado_em, tentativas (smallint, padrão 0), bloqueado_ate,
  assinado_em, nome_digitado, cpf_digitado, rubrica_chave, metodo, motivo_recusa, ip, user_agent, latitude, longitude,
  precisao.
- `assinatura_eletronica_evento` (`EventoDaTrilha`): id, documento_id (CASCADE), signatario_id (SET NULL, nulo),
  usuario_id (SET NULL, nulo), signatario_ref (int, nulo, sem FK), usuario_ref (int, nulo, sem FK), tipo (enum),
  dados (JSON), ip (nulo), user_agent (nulo), criado_em (hora do servidor), hash_anterior (char 64, nulo no primeiro),
  hash (char 64). Sem setter: só se cria. `signatario_ref` e `usuario_ref` guardam, no construtor, o id de quem agiu e
  nunca mudam; as relações ficam só para mostrar o nome.
  `hash = sha256(hash_anterior | documento_id | tipo | dados em JSON com chaves ordenadas | criado_em ISO 8601 | signatario_ref | usuario_ref | ip | user_agent)`.
  O hash usa as colunas `_ref` porque o `SET NULL` das FKs (usuário ou signatário apagado) não pode quebrar a
  conferência dos eventos antigos. O acréscimo trava a linha do documento (`PESSIMISTIC_WRITE`) para a corrente não
  bifurcar.
- `assinatura_eletronica_configuracao` (`ConfiguracaoDaAssinatura`, TenantAware): id, tenant_id (único),
  logo_padrao_tipo (enum), logo_padrao_chave (nulo).
- Permissão `modules.assinatura_eletronica.view`: INSERT idempotente e concessão aos perfis que já têm
  `modules.pastas.view` (receita do Cálculo, `Version20261030110000`).

Apagar rascunho de vez apaga o documento, os signatários e os eventos (CASCADE) e, **depois do COMMIT**, os arquivos
(PDF e logo própria) pela `RemocaoAposTransacao`.

### 3.3 Bloco (JSON em `blocos`)

```
{ "k": "t"|"s"|"h"|"l"|"p"|"d"|"n", "x": "<texto>", "full": 0|1 }   título, subtítulo, seção, linha, parágrafo, data, número
{ "k": "a", "i": <índice do signatário>, "r": "<papel>" }            lugar de assinatura
{ "k": "pg", "n": <página do PDF enviado, a partir de 1> }           página do arquivo enviado
```

Validação no servidor: no máximo 2.000 blocos; `x` até 20.000 caracteres; `k` fora da lista recusa; `i` inteiro
maior ou igual a 0; `r` até 500 caracteres (vem dos campos do modelo, com dado do cliente: a razão social vai até 150;
o papel do signatário continua até 120); `n` entre 1 e o número de páginas do PDF guardado; nenhum outro campo é
guardado.

## 4. Rotas (prefixo de nome `assinatura_eletronica_`, todas com `canAccessModule('assinatura_eletronica')`)

| Método e caminho | Nome | Entrada | Saída |
|---|---|---|---|
| GET `/assinatura` | `index` | | página (Twig `assinatura_eletronica/index.html.twig`) |
| GET `/assinatura/documentos` | `documentos` | | `{documentos: [Resumo], pastas: [string]}` (inclui os da Lixeira; a consulta lê só os campos do `Resumo`, sem `blocos` nem `lateral`) |
| GET `/assinatura/documentos/{id}` | `documento` | | `Detalhe` |
| POST `/assinatura/documentos` | `criar` | multipart: `dados` (JSON `Rascunho`), `arquivo` (PDF, opcional), `logo` (PNG ou JPEG, opcional) | `{id}` 201 |
| POST `/assinatura/documentos/{id}` | `salvar` | igual; só rascunho; com bloco `pg` e sem `arquivo`, mantém o PDF atual; sem bloco `pg`, o PDF sai | `{id}` |
| POST `/assinatura/documentos/{id}/duplicar` | `duplicar` | | `{id}` 201 |
| POST `/assinatura/documentos/lixeira` | `lixeira` | JSON `{ids}` | `{ok: true}` |
| POST `/assinatura/documentos/restaurar` | `restaurar` | JSON `{ids}` | `{ok: true}` |
| POST `/assinatura/documentos/excluir` | `excluir` | JSON `{ids}` (rascunhos na Lixeira) | `{ok: true}`, ou 422 `{erro}` |
| POST `/assinatura/documentos/cor` | `cor` | JSON `{ids, cor}` | `{ok: true}` |
| GET `/assinatura/documentos/{id}/arquivo` | `arquivo` | | PDF enviado (inline) |
| GET `/assinatura/documentos/{id}/logo` | `logo` | | logo própria do documento |
| GET `/assinatura/logo-padrao` | `logo_padrao` | | logo própria padrão do escritório |

POST com CSRF pelo padrão do sistema: o `base.html.twig` injeta o cabeçalho `X-CSRF-Token` (token `ajax`) em todo
`fetch`, e o controller usa o `ValidaCsrfAjaxTrait`, como o `CompromissoController` da Agenda. Documento de outro escritório: 404. Ação proibida pela situação: 422 com `{erro: "<texto>"}`.

Textos de erro (literais do desenho): "Documentos enviados permanecem na Lixeira para preservar as evidências."
(excluir algo que não é rascunho), e para salvar fora do rascunho: "Só rascunho pode ser alterado.". O servidor também
recusa excluir o que não está na Lixeira: "Só documentos na Lixeira podem ser excluídos definitivamente." (a tela só
oferece a exclusão definitiva dentro da Lixeira; o texto protege a rota).

As ações em lote (Lixeira, restaurar, excluir de vez) travam a linha de cada documento (`PESSIMISTIC_WRITE`) e releem
o estado dentro da transação antes de decidir pela situação ou pela Lixeira: o que já está na Lixeira não ganha evento
novo, e um envio ou uma restauração que chegou antes não é apagado.

### 4.1 Formas do JSON

```
Resumo = { id, nome, categoria, pasta (string|null), situacao, criadoEm (ISO), venceEm (ISO|null), cor ('' ou chave),
           naLixeira (bool), assinados (int), total (int), signatarios: [{nome, situacao}] }

Detalhe = Resumo + { modelo (string|null), blocos: [Bloco], layout, lateral, folhaBranca, emOrdem, mensagem,
           prazoDias, logo: {tipo, url|null}, arquivo: {url, nome, paginas}|null,
           signatarios: [{id, ordem, nome, email, cpf, papel, nivel, situacao, assinadoEm|null, metodo|null}],
           eventos: [{em (ISO), texto}] }   eventos do mais recente para o mais antigo

Rascunho = { nome, pasta, categoria, modelo, blocos, layout, lateral, folhaBranca, emOrdem, mensagem, prazoDias,
             logo: {tipo}, usarLogoComoPadrao (bool, opcional),
             signatarios: [{nome, email, cpf, papel, nivel}] }
```

`usarLogoComoPadrao`: `true` grava a logo do documento como padrão do escritório; `false` limpa o padrão (volta à
BlueJus); ausente não mexe no padrão. A tela só manda o campo quando a caixa "Usar esta logo nos próximos documentos"
esteve à vista (passo 2); rascunho retomado e arquivo enviado pulam o passo 2 e não o mandam.

Limites do `Rascunho`: nome até 200 (vazio vira "Documento"), pasta até 200, mensagem até 2.000, prazoDias 1 a 90,
até 50 signatários, nome e papel até 200 e 120, e-mail válido quando preenchido, CPF só dígitos (11) quando
preenchido. O dígito do CPF e o nome obrigatório de cada signatário são conferidos no envio (etapa 2), como o desenho.

### 4.2 O que a página recebe (atributos `data-*` da raiz `.bja`)

`data-eu` `{nome, email}`; `data-padrao` (valores iniciais dos campos do escritório: `adv_trat`, `adv_nome`,
`adv_seccional`, `adv_oab`, `adv_email`, `esc_tel`, `esc_end`, `esc_cep`, `esc_razao`, `esc_reg`, `esc_cnpj`,
`cidade`, tirados de Meu Escritório e do usuário; campo sem dado vem vazio); `data-logos`
`{bluejus: url, escritorio: url|null, padrao: {tipo, url|null}}`; e uma URL `data-url-*` por rota da tabela acima, com
o marcador `0` no lugar do `{id}`, como a Agenda 1A. URL de etapa futura não existe no atributo, e o JS esconde o
botão quando a URL falta.

## 5. Módulo, menu e Master

- `PermissionFixture`, `GruposDePermissaoDoPerfil` (rótulo "Assinatura de documentos"), `docs/AUTORIZACAO.md`
  (tabela de módulos).
- Menu lateral existente: item "Assinatura" (`bi-pen`), visível com o módulo e a chave do Master ligada.
- `CatalogoDeRecursos`: chave `assinatura`, título "Assinatura de documentos", ícone `bi-pen`, grupo Telas; rotas
  `assinatura_eletronica_`. Nasce **desligada** até a etapa 3.

## 6. Testes

- Unit: entidades (transições de situação, Lixeira, trilha encadeada), validação dos blocos e do rascunho, UseCases.
- Functional: cada rota (sem módulo = 403; outro escritório = 404; Lixeira; excluir só rascunho; upload de PDF e
  logo; CSRF), página com o arranjo por filho direto (`.bja > ...`), `PurgaCoberturaSchemaTest`,
  `TenantAwareCoberturaTest`, `AuditavelCoberturaTest`.
- `node --check` em todo JS novo.
