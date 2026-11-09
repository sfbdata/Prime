# Assinatura de documentos: etapa 7 (contrato), nível Reforçada

Etapa 7 da spec `docs/specs/assinatura-documentos.md` (seções 1.3, 1.5, 3.4, 3.6, 5.2 e D1): selfie com documento e
localização. Vale tudo o que os contratos das etapas 1 e 2 dizem e este não muda
(`docs/specs/assinatura-documentos-etapa-1.md`, `docs/specs/assinatura-documentos-etapa-2.md`). Desenho:
`docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (cópia principal do repositório). Inventário item a
item, com a linha do desenho: `docs/design/redesign-08-11-26/inventario-assinatura-etapa7-0911.md` (as referências A1,
B3, H1... abaixo são dele). Risco MÉDIO: rota pública, dado pessoal sensível, cifra.

## 1. Escopo

Entra:
- o envio aceita signatário Reforçada (código por e-mail, selfie e localização), com as conferências da seção 4.3;
- tela pública: aviso de LGPD, botão da selfie (câmera frontal), linha da localização e as conferências do desenho
  (inventário B, C e D); prévia do escritório com os mesmos elementos, só de leitura;
- "Assinar" Reforçado: a foto conferida, regravada em JPEG pelo GD e cifrada antes de ir ao disco; a localização nas
  colunas da M1; evidências na trilha encadeada;
- detalhe do documento: o método "E-mail e código, selfie, localização {lat}, {long}" de quem assinou (F2);
- o serviço que abre a selfie para a etapa 3 (manifesto), sem rota nesta etapa (decisão 7);
- migration M5 `Version20261109270000`, variável `ASSINATURA_SELFIE_CHAVE` e o runbook
  `docs/runbooks/assinatura-selfie-chave-em-producao.md`.

Fica fora:
- comparação de face (D1) e purga automática da selfie (decisão 3; o prazo vai ao pacote jurídico);
- ver a selfie dentro do sistema (o desenho não mostra, F3 e G2: lacuna L1);
- manifesto e PDF final (etapa 3); gov.br e ICP (etapa 5): o envio continua recusando esses dois níveis;
- qualquer nginx (decisão 9; seção 10).

A tela, inclusive a pública, continua atrás da chave `assinatura` do Master.

## 2. Decisões

Do Coordenador (09/11), numeradas como na delegação:

1. **Selfie cifrada** com segredo próprio `ASSINATURA_SELFIE_CHAVE` (64 hexadecimais = 32 bytes), nunca o `APP_SECRET`
   nem o `ASSINATURA_SEGREDO`. AEAD do libsodium (XChaCha20-Poly1305-IETF) com dados associados que amarram o arquivo ao
   escritório, ao documento e ao signatário; versão da chave no cabeçalho de cada arquivo (seção 3). Sem a chave, o
   envio com signatário Reforçada recusa: "Assinatura reforçada sem chave configurada. Fale com o suporte."
2. **M5 = `Version20261109270000`**, só as colunas da selfie no signatário (seção 4.1). Latitude, longitude e precisão já
   existem desde a M1 (`NUMERIC(10,8)`, `NUMERIC(11,8)`, `NUMERIC(10,2)`): reaproveitadas.
3. Sem comparação de face (D1). Sem purga automática da selfie nesta etapa.
4. **Menor**: o envio recusa Reforçada para o papel de menor (seção 4.3).
5. **Aviso de LGPD** antes da captura: o desenho não tem (H1); texto curto no componente de texto pequeno do próprio
   desenho (B6, 12px `#5b7383`), acima do botão da selfie (seção 7.1). Texto provisório até o pacote jurídico.
6. **A imagem recebida**: limite de tamanho, tipo pelo conteúdo, regravada em JPEG pelo GD (sai EXIF e qualquer conteúdo
   escondido) antes de cifrar; o sha256 do JPEG regravado vai para a trilha com a versão da chave e a localização.
   Gravação pela fábrica `ChavesDeAssinaturaEletronica` (categoria `ASSINATURA_DOCUMENTO`) com `TransacaoComArquivoNovo`;
   o token nunca entra em log (o processador da etapa 2 continua valendo).
7. **Ver a selfie**: o desenho não mostra a foto em lugar nenhum (F3, G2). Entra só o serviço `SelfieDoSignatario`
   (seção 5.3), para a etapa 3 usar no manifesto; sem rota interna. Lacuna L1.
8. **Localização**: segue o desenho onde ele diz (pedida ao abrir, `timeout: 6000`, 5 casas na tela). Onde ele é
   omisso sobre a recusa fora da prévia (H2, H3), a Reforçada exige localização, com mensagem clara (seção 8).
9. **nginx**: nenhum nginx foi editado. O que precisa mudar está na seção 10 e no runbook.

Desta implementação (para o Coordenador revisar):

- **I1. Transporte da selfie**: no mesmo JSON do "Assinar", em data URL, como a rubrica da etapa 2. O navegador reduz a
  foto antes de mandar (lado maior até 1600 px, JPEG qualidade 0,85); o servidor confere e regrava de novo (seção 6).
  Uma rota de envio separada não entrou: a foto só vale junto com a assinatura e o código conferido.
- **I2. `Publico` ganha `reforcada` (bool) no topo**; o `eu` fica igual (`{nome, cpf, posicao}`), sem papel nem nível.
  `reforcada` só é verdadeiro no estado `pronto` (e na prévia) de signatário Reforçada.
- **I3. Ordem das conferências**: a do desenho (D1) até onde o navegador consegue conferir; a tela confere a selfie e
  a localização antes de mandar. O servidor confere nome, CPF, CPF do cadastro, rubrica, código pedido e formato do
  código, depois selfie e localização, depois o aceite; o código só é conferido na transação. Diferença do desenho:
  com o código errado e sem selfie, a tela fala da selfie primeiro (o navegador não sabe que o código está errado).
- **I4. Prévia do escritório**: mostra o aviso, o botão da selfie (visível e desabilitado, decisão E da etapa 2) e
  pede a localização como o desenho (C1); sem localização, o texto do desenho "Localização não informada nesta
  pré-visualização." (B8).
- **I5. Texto da trilha**: no estilo do da etapa 2 ("{nome} assinou (Simples)"), com a localização do desenho (D5):
  "{nome} assinou (Reforçada): selfie e localização {lat}, {long}" (5 casas, separador ponto, como o desenho).
- **I6. E-mail exigido** também na Reforçada: o código vai por e-mail, como no Simples (decisão A da etapa 2).
- **I7. Chave tirada depois do envio**: o "Assinar" Reforçado também recusa com o aviso de sem chave, antes de qualquer
  conferência, sem gravar nada.
- **I8. Menor**: papel cujo texto, sem caixa, tem a palavra inteira "menor", "criança" ou "adolescente", no singular
  ou no plural, com ou sem acento (ex.: "Menor assistido(a)", "Menor Representado ou Assistido", "Menores
  representados", "Crianças", "crianca", "Adolescente assistido"). Um papel como "Responsável pelo menor" também cai
  na regra (falso positivo assumido: o escritório troca a forma de assinatura do menor ou o texto do papel). **Risco
  que fica:** o papel é texto livre; um menor cadastrado com papel sem essas palavras (ex.: "Filho", "Herdeiro",
  "Neto") passa pela regra e assina com selfie. A trava completa pediria a data de nascimento do signatário, que o
  sistema não colhe; fica para quando houver esse dado.
- **I9. Selfie de quem é Simples**: o servidor ignora `selfie` e `localizacao` no corpo de quem é Simples (nada gravado).
- **I10. Textos novos** (o desenho não tem): espera e recusa da localização, foto que o navegador não lê, erros do
  servidor sobre a imagem e a localização, aviso de LGPD, mensagem do menor e da chave (seção 8). Todos no estilo e nos
  componentes do desenho, o mínimo.

## 3. Chave da selfie e formato do arquivo cifrado

- Variável `ASSINATURA_SELFIE_CHAVE`: 64 caracteres hexadecimais (32 bytes, o tamanho da chave do
  XChaCha20-Poly1305-IETF). `app/.env` vazia; `app/.env.test` com valor fixo; `.env.prod.example` com o nome vazio e um
  comentário; dev: o Samuel grava no `.env.local`. Parâmetro `assinatura.selfie_chave`
  (`%env(string:default::ASSINATURA_SELFIE_CHAVE)%`). Valor vazio, com outro tamanho ou com caractere que não é
  hexadecimal: "não configurada".
- Serviço `App\AssinaturaEletronica\Service\CofreDaSelfie`. Versão da chave: constante `CofreDaSelfie::VERSAO = 1`.
  Troca futura: o código ganha a versão nova (mapa versão para chave) sem apagar a antiga; até lá, arquivo com outra
  versão não abre.
- Arquivo gravado (bytes):

| Posição | Tamanho | Conteúdo |
|---|---|---|
| 0 | 8 | marca `BJSELFIE` (ASCII) |
| 8 | 1 | versão da chave (1 a 255; hoje 1) |
| 9 | 24 | nonce aleatório (`random_bytes`) |
| 33 | n + 16 | `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(jpeg, dados_associados, nonce, chave)` (o JPEG cifrado e a etiqueta de 16 bytes) |

- `dados_associados` = os 9 bytes do cabeçalho seguidos de `|escritorio:{tenant_id}|documento:{documento_id}|signatario:{signatario_id}`.
  Trocar a marca, a versão, o nonce ou um byte do texto cifrado, ou abrir o arquivo como se fosse de outro signatário,
  documento ou escritório, faz a decifração falhar.
- Extensão da chave no disco: `.cifrado` (`<128 bits em hex>.cifrado`), na pasta do escritório da categoria
  `ASSINATURA_DOCUMENTO` (`uploads/pastas/assinatura/<tenantId>/`, a mesma da rubrica e do PDF). A purga do escritório
  apaga junto (prefixo isolado).
- A chave nunca é logada nem impressa; `#[\SensitiveParameter]` no construtor; a cópia binária é apagada da memória
  (`sodium_memzero`) depois de cada uso.

## 4. Domínio

### 4.1 Migration M5 (`Version20261109270000`)

`assinatura_eletronica_signatario` ganha:

| Coluna | Tipo | O que guarda |
|---|---|---|
| `selfie_chave` | `VARCHAR(255) NULL` | nome da chave do arquivo cifrado |
| `selfie_sha256` | `CHAR(64) NULL` | sha256 do JPEG regravado (antes de cifrar) |
| `selfie_versao` | `SMALLINT NULL` | versão da chave da selfie usada no arquivo |

As três ficam nulas de quem não é Reforçada e de quem ainda não assinou. Latitude, longitude e precisão (M1) passam a
ser gravadas na assinatura Reforçada: latitude e longitude com 8 casas, precisão (metros) com 2.

### 4.2 Assinatura Reforçada

- `Signatario::registrarReforco(selfieChave, selfieSha256, versao, LocalizacaoDoSignatario)`: só signatário pendente
  e de nível Reforçada; chamado na mesma transação, antes da transição `DocumentoParaAssinar::assinar`.
- `metodo` gravado no signatário: `reforcada` (o Simples continua `simples`).
- O mesmo fluxo da etapa 2 (trava, vez, bloqueio, código, rubrica, conclusão, e-mails): a selfie cifrada é gravada
  dentro da transação, só depois do código conferido, e entra na lista de arquivos novos da `TransacaoComArquivoNovo`
  (falhou antes do COMMIT, o arquivo sai). Código errado não grava selfie.

### 4.3 Conferências do envio (`ConferenciaDoEnvio`)

Na ordem: nomes; CPFs; por signatário, nível (gov.br e ICP continuam recusados com a mensagem da etapa 2), menor com
Reforçada, e-mail (Simples e Reforçada); por último, havendo Reforçada, a chave da selfie configurada.

## 5. Rotas e formas

### 5.1 Sem rota nova

As rotas públicas e internas são as da etapa 2. Mudam o corpo do "Assinar", o `Publico` e o `Detalhe`.

### 5.2 POST `/assinar/{token}` (`publica_assinar`)

Corpo JSON da etapa 2 mais, só para Reforçada:

```
{ ..., selfie: 'data:image/jpeg;base64,...' (ou image/png),
       localizacao: { latitude: número, longitude: número, precisao: número (metros) } }
```

Erros 422 com `{erro}` (seção 8). Sucesso: o `Publico` de sempre.

### 5.3 Formas

- `Publico` (etapa 2, seção 5.3) ganha `reforcada: bool` no topo (I2).
- `Detalhe`, por signatário, ganha `selfie: bool` (há selfie guardada) e `localizacao: {latitude, longitude, precisao}|null`
  (textos decimais como gravados). A imagem não sai no `Detalhe`.
- `SelfieDoSignatario::abrir(Signatario, Tenant): ?string`: o JPEG decifrado (nulo sem selfie). Confere o escritório
  do documento com o informado (`DocumentoNaoEncontrado` se outro) e o sha256 do JPEG com o gravado; arquivo que não
  abre ou não confere: `SelfieIlegivel`. Quem chamar (etapa 3) confere antes módulo e acesso ao documento, responde
  com `no-store` e grava na trilha "selfie visualizada" com o usuário, se mostrar a foto a alguém.

## 6. Limites

| Item | Limite |
|---|---|
| Selfie no corpo | data URL `data:image/...;base64,`; até 4 MB depois de decodificada (a conta é feita no tamanho do base64 antes de decodificar) |
| Tipo | JPEG ou PNG, conferido pelo conteúdo (`getimagesizefromstring`), nunca pelo prefixo da data URL |
| Dimensões | de 240 a 4.096 px em cada lado e até 12,5 megapixels (o GD abre a imagem inteira na memória) |
| Regravação | JPEG qualidade 85, fundo branco (PNG transparente), lado maior até 1.600 px (só reduz) |
| No navegador | reduz para lado maior até 1.600 px, JPEG 0,85, antes de mandar |
| Latitude | número de -90 a 90 |
| Longitude | número de -180 a 180 |
| Precisão | número de 0 a 99.999.999,99 metros |
| Limitadores | os da etapa 2 (a página e os POST), sem limitador novo |

## 7. Tela pública e detalhe

### 7.1 Tela pública (`assinatura-publica.js`, `assinatura-publica.css`, catálogo `assinatura_eletronica_publica`)

Dentro do painel (`.bjaPainel`), soltos na coluna, depois da linha do código (`.bjaCodLin`) e antes do aceite (B1):

1. `div.bjaSelfieAviso` (12px `#5b7383`): o aviso de LGPD (decisão 5);
2. `label.bjaB.bjaN.bjaSelfie` (38px, ícone `bi-camera`) com o `input type="file" accept="image/*" capture="user"`
   escondido: "Enviar selfie com documento" e, com foto, "Selfie anexada" (B2 a B5);
3. `div.bjaGeo` (12px `#5b7383`): a localização (B6 a B8 e seção 8).

A localização é pedida ao abrir (C1, C2). Ao "Assinar", depois do formato do código: sem foto, "Envie a selfie com o
documento." (D1); sem localização, "Permita o acesso à localização para assinar." e a tela pede de novo. Na prévia, o
botão fica visível e desabilitado (I4).

### 7.2 Detalhe (`assinatura.js`, só o trecho do detalhe; catálogo `assinatura_eletronica_tela`, bloco próprio no fim)

O método de quem assinou Reforçada: "E-mail e código, selfie, localização {lat}, {long}" (5 casas); sem localização
gravada, "E-mail e código, selfie" (D3, F2). Nada mais muda no detalhe; o passo 3 já tem a opção Reforçada (A1, A2).

## 8. Mensagens

| Onde | Chave | Texto |
|---|---|---|
| envio | `erro.reforcada_sem_chave` | Assinatura reforçada sem chave configurada. Fale com o suporte. |
| envio | `erro.envio_reforcada_menor` | A assinatura Reforçada colhe selfie e não é usada para menor ({nome}). Escolha outra forma de assinatura. |
| assinar | `erro.reforcada_sem_chave` | (o mesmo texto) |
| assinar | `erro.assinar_selfie` | Envie a selfie com o documento. (desenho, D1) |
| assinar | `erro.selfie_invalida` | A selfie não pôde ser lida. Envie uma foto JPEG ou PNG. |
| assinar | `erro.selfie_grande` | A selfie pode ter até 4 MB. Tire a foto de novo. |
| assinar | `erro.selfie_tamanho` | O tamanho da foto não serve. Tire a selfie de novo. |
| assinar | `erro.assinar_localizacao` | Permita o acesso à localização para assinar. |
| assinar | `erro.localizacao_invalida` | A localização recebida não é válida. Recarregue a página e tente de novo. |
| tela pública | `js.selfie.aviso` | A selfie com o documento e a localização servem só para comprovar esta assinatura. Ficam guardadas cifradas, com acesso restrito ao remetente do documento. |
| tela pública | `js.selfie.enviar` | Enviar selfie com documento (desenho, B3) |
| tela pública | `js.selfie.anexada` | Selfie anexada (desenho, B3) |
| tela pública | `js.selfie.ilegivel` | A foto não pôde ser lida. Tire a selfie de novo. |
| tela pública | `js.local.obtida` | Localização: {local} (desenho, B7) |
| tela pública | `js.local.previa` | Localização não informada nesta pré-visualização. (desenho, B8) |
| tela pública | `js.local.buscando` | Obtendo a localização... |
| tela pública | `js.local.recusada` | Localização não autorizada. Permita o acesso à localização no navegador para assinar. |
| tela pública | `js.erro.selfie` | Envie a selfie com o documento. (desenho, D1) |
| tela pública | `js.erro.localizacao` | Permita o acesso à localização para assinar. |
| detalhe | `js.reforcada.metodo` | E-mail e código, selfie, localização {local} (desenho, D3) |
| detalhe | `js.reforcada.metodo_sem_local` | E-mail e código, selfie (desenho, D3) |
| trilha | `evento.assinado` | Simples: "{nome} assinou (Simples)" (etapa 2); Reforçada: "{nome} assinou (Reforçada): selfie e localização {local}" (I5) |

## 9. Trilha

O evento `assinado` da Reforçada leva, além do que a etapa 2 grava (`metodo`, `modo`, `rubrica_sha256`, `aceite`,
`cpf_confere`, `chave`, `porta`):

| Campo | Valor |
|---|---|
| `metodo` | `reforcada` |
| `selfie_sha256` | sha256 do JPEG regravado (o mesmo de `selfie_sha256` no signatário) |
| `selfie_versao` | versão da chave da selfie (`CofreDaSelfie::VERSAO`), o mesmo nome da coluna do signatário; `selfie_chave` é só a coluna com o nome do arquivo |
| `latitude`, `longitude` | texto decimal com 8 casas, como na coluna |
| `precisao` | texto decimal com 2 casas (metros) |

Texto: "{nome} assinou (Reforçada): selfie e localização {local}", com `{local}` montado na apresentação a partir de
`latitude` e `longitude` (5 casas). A imagem, o nonce e a chave nunca entram em `dados`; o token e o código continuam
fora (etapa 2).

## 10. Produção (pendência do Samuel)

- Criar `ASSINATURA_SELFIE_CHAVE` no `.env.prod` e guardar no cofre de senhas antes de ligar a chave `assinatura` para
  quem vai usar a Reforçada. Passo a passo: `docs/runbooks/assinatura-selfie-chave-em-producao.md`.
- nginx de produção (`nginx/conf.d/nginx.prod.conf`, linhas 51 e 233): `Permissions-Policy "geolocation=(self),
  microphone=(), camera=()"`. A localização já está liberada (`geolocation=(self)`). A selfie usa o campo de arquivo com
  `capture="user"` (abre o aplicativo de câmera do celular, sem `getUserMedia`); a leitura de QR da etapa 3 usa
  `getUserMedia` e precisa de `camera=(self)`. Mudar as duas linhas para `camera=(self)` e conferir a selfie num celular
  depois de publicar; editar e publicar o nginx é do Samuel.

## 11. Testes

- Unit: `CofreDaSelfie` (cifra e decifra, cabeçalho e versão, dado associado de outro signatário, documento ou
  escritório, versão desconhecida, chave ausente ou inválida, arquivo adulterado e cortado, chave trocada);
  `RegravacaoDaSelfie` (JPEG e PNG regravados sem EXIF nem bytes escondidos, tipo pelo conteúdo, tamanho, dimensões,
  redução); `LocalizacaoDoSignatario` (faixas, tipos); `ConferenciaDoEnvio` (Reforçada aceita, menor, sem chave, sem
  e-mail); `AssinarDocumentoUseCase` Reforçado (completo, sem selfie, sem localização, imagem inválida, sem chave,
  código errado não grava selfie); `SelfieDoSignatario` (abre, outro escritório, sha256 que não confere).
- Functional: envio com Reforçada (aceito, menor, sem chave); tela pública Reforçada completa (selfie cifrada no
  disco, colunas, trilha, detalhe), sem selfie, sem localização, imagem inválida, imagem grande, sem chave; `Publico`
  com `reforcada`; prévia.
- Arranjo: a tela pública é desenhada pelo JS (o container não tem node). O teste lê o `assinatura-publica.js`, monta o
  esqueleto HTML das funções do painel (só os textos literais do nível de fora da concatenação) e confere por filho
  direto: `.bjaPainel > .bjaCodLin + .bjaSelfieAviso + label.bjaSelfie + .bjaGeo + label.bjaAceite`.
- `node --check` nos dois JS tocados; `SemTravessaoNovoArquiteturaTest`, `MetodoLongoArquiteturaTest`.

## 12. Lacunas

- **L1.** Ver a selfie dentro do sistema: o desenho não mostra (F3, G2). Só o serviço `SelfieDoSignatario`; rota,
  evento "selfie visualizada" e lugar na tela ficam para quando o desenho mostrar (ou para o manifesto da etapa 3).
- **L2.** Prazo de guarda e purga automática da selfie: pacote jurídico (decisão 3). Até lá, a selfie fica enquanto o
  documento existir; a purga do escritório apaga junto.
- **L3.** Texto do aviso de LGPD e base legal (art. 11, II, "g", se a selfie for usada para identificar pelo rosto):
  pacote jurídico; o texto da seção 8 é provisório.
- **L4.** Câmera no nginx de produção (seção 10): do Samuel.
- **L5.** As fontes de referência do espelho no R2 (`Shared/EspelhoRemoto/Reconciliacao/FontesDeReferencia`) não
  listam a rubrica (etapa 2) nem a selfie: a reconciliação não confere esses arquivos no espelho. Fora do escopo desta
  etapa (arquivo do `Shared`).
- **L6.** O `ConferirSegredosCommand` (auditoria dos segredos de produção) não confere o `ASSINATURA_SEGREDO` nem a
  `ASSINATURA_SELFIE_CHAVE`. Fora do escopo (arquivo do `Shared`).
