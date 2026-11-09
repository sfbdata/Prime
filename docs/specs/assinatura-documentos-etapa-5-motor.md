# Assinatura de documentos: etapa 5, motor de conferência de PDF já assinado (contrato)

Parte independente da etapa 5 da spec `docs/specs/assinatura-documentos.md` (seções 1.7, 3.3 e "Etapa 5"). Porta para
PHP a conferência de integridade que o protótipo faz no navegador: `docs/design/redesign-08-11-26/bj-assina-pdf.js`
(funções `analisar`, `umaAss`, `confere`, `cert`, `prefixo`, `objeto` e `infla`). Risco MÉDIO: o arquivo vem de fora.

## 1. Escopo

Entra:
- conferência de cada assinatura digital do PDF: faixa de bytes, resumo (messageDigest), chave do certificado
  (RSA PKCS#1 v1.5, RSA-PSS e ECDSA P-256, P-384 e P-521), titular, emissor, validade, CPF ou CNPJ, hora declarada,
  cobertura do arquivo, DocMDP e o veredito com motivo em português;
- prefixo byte a byte com a versão anterior e o CPF esperado do signatário;
- leitura de objeto do PDF (inclusive dentro de fluxo de objetos comprimido) com inflate limitado, para o carimbo da
  etapa 5 usar depois.

Fica fora: `carimbar` e `guardar` (cópia com carimbo e gravação, etapa 5), `ler` (lê o original do IndexedDB; anda junto
com `guardar`), tela, rota, controller, migration, entidade, cadeia ICP-Brasil e gov.br, revogação e carimbo do tempo
(etapa 6, pyHanko). Sem dependência nova no composer: só as extensões `openssl`, `zlib` e `mbstring`, que já existem.

## 2. Interface pública

Tudo em `App\AssinaturaEletronica\Service\ConferenciaDePdf` (`app/src/AssinaturaEletronica/Service/ConferenciaDePdf/`).
Serviços sem estado, registrados pelo autowiring; também podem ser criados com `new` (os construtores têm padrão).

```php
final class ConferenciaDePdf
{
    public function conferir(
        string $pdf,                    // bytes do PDF
        ?string $versaoAnterior = null, // bytes da versão anterior (a nova precisa começar com eles)
        ?string $cpfEsperado = null,    // CPF do signatário, com ou sem pontuação
    ): ResultadoDaConferencia;
}

final class LeitorDeObjetosDoPdf
{
    /** Corpo do objeto "numero geracao obj" (o último do arquivo) ou de dentro de um /ObjStm; null se não achar. */
    public function objeto(string $pdf, int $numero, int $geracao): ?string;
}

final class InfladorLimitado
{
    /** zlib (FlateDecode); null se o dado for inválido ou passar de $limiteEmBytes. */
    public static function inflar(string $comprimido, int $limiteEmBytes): ?string;
}
```

Nenhum dos três lança exceção para arquivo ruim: PDF malformado vira resultado `ilegivel` com motivo, assinatura
malformada vira `naoverif` com motivo, objeto que não dá para ler vira `null`. Nada é escrito em disco.

As classes de apoio (`LeitorDer`, `NoDer`, `LeitorDeCertificado`, `CertificadoDoSignatario`, `LeitorDeAssinaturaCms`,
`VerificadorDeChave`, `ConferenciaDoCms`, `ResultadoDaChave`, `SemAvisos`, `FormatoNaoReconhecido`, `LimiteExcedido`)
são internas do motor: a etapa 5 só usa as três acima, os DTOs de resultado e `LimitesDaConferencia`.

## 3. Formato do resultado

### 3.1 `ResultadoDaConferencia` (readonly)

| Campo | Tipo | O que é |
|---|---|---|
| `situacao` | `SituacaoDaConferencia` | veredito do arquivo inteiro (3.4) |
| `motivo` | `string` | frase em português que explica a situação |
| `tamanho` | `int` | bytes do PDF recebido |
| `assinaturas` | `list<AssinaturaConferida>` | da mais antiga para a mais recente (ordem do fim da faixa, como o protótipo) |
| `docMdp` | `int` | 0 sem DocMDP; 1, 2 ou 3: o P da primeira assinatura que tem DocMDP |
| `alteradoDepoisDaCertificacao` | `bool` | DocMDP P=1 e o arquivo tem bytes depois dessa assinatura |
| `cifrado` | `bool` | o PDF tem `/Encrypt` |
| `permiteCarimbo` | `bool` | regra do protótipo: sem DocMDP 1 ou 2, sem cifra e sem assinatura alterada |
| `prefixo` | `ConferenciaDoPrefixo` | resultado da comparação com a versão anterior |
| `assinaturasNovas` | `int` | com versão anterior: quantas assinaturas terminam depois do fim dela; sem: 0 |
| `cpf` | `ConferenciaDoCpf` | resultado da comparação com o CPF esperado |

Métodos: `temAssinatura(): bool` e `ultimaAssinatura(): ?AssinaturaConferida`.

### 3.2 `AssinaturaConferida` (readonly)

| Campo | Tipo | Origem no protótipo |
|---|---|---|
| `ordem` | `int` | posição depois de ordenar (1 é a mais antiga) |
| `faixaDeBytes` | `list<int>` | os quatro números do `/ByteRange` (número com mais de 12 dígitos vira -1) |
| `faixaValida` | `bool` | regras da 4.1 |
| `fim` | `int` | `c + m` (`fim`) |
| `cobreODocumento` | `bool` | `c + m === tamanho` (`cobre`) |
| `bytesDepois` | `int` | `max(0, tamanho - c - m)` (`apos`) |
| `tipo` | `string` | `PAdES`, `PKCS#7`, `Carimbo do tempo` ou `Digital`; vazio com faixa inválida |
| `algoritmoDoResumo` | `?string` | `SHA-256`, `SHA-384`, `SHA-512` ou `SHA-1` |
| `resumoConfere` | `?bool` | messageDigest contra o hash da faixa (`digest`); null sem atributos assinados |
| `chaveConfere` | `?bool` | assinatura sobre os atributos assinados (`cripto`); null quando não deu para conferir |
| `titular` | `?string` | CN do titular sem o `:CPF` (`nome`) |
| `cpf` | `?string` | 11 dígitos do certificado |
| `cnpj` | `?string` | 14 dígitos do certificado |
| `documentoMascarado` | `?string` | CPF como o protótipo (`***.456.789-**`) ou CNPJ formatado (`doc`) |
| `emissor` | `?string` | CN do emissor (`emissor`) |
| `icpBrasil` | `bool` | O do emissor contém "ICP-Brasil" (`icp`) |
| `numeroDeSerie` | `?string` | número de série do certificado, em hexadecimal |
| `validoDesde` | `?DateTimeImmutable` | início da validade, UTC |
| `validoAte` | `?DateTimeImmutable` | fim da validade, UTC (`validade`) |
| `horaDeclarada` | `?DateTimeImmutable` | signingTime ou `/M`, UTC (`em`) |
| `docMdp` | `?int` | P do DocMDP desta assinatura (`mdp`) |
| `veredito` | `VereditoDaAssinatura` | `status` |
| `motivo` | `string` | `motivo`, sempre preenchido |

### 3.3 Enums (string)

`VereditoDaAssinatura` (os valores do protótipo): `Integra` = `integra`, `Alterada` = `alterada`, `NaoConferida` = `naoverif`.

`SituacaoDaConferencia`: `Ilegivel` = `ilegivel`, `SemAssinatura` = `sem_assinatura`, `Integro` = `integro`,
`Alterado` = `alterado`, `NaoConferido` = `nao_conferido`.

`ConferenciaDoPrefixo` (com `motivo()`): `NaoPedida`, `Confere`, `NaoConfere`.

`ConferenciaDoCpf` (com `motivo()`): `NaoPedida`, `Confere`, `NaoConfere`, `SemCpfNoCertificado`, `SemAssinatura`.

### 3.4 Situação do arquivo

Na ordem: arquivo que não dá para ler, `ilegivel`; sem assinatura, `sem_assinatura`; alguma assinatura `alterada` ou
`alteradoDepoisDaCertificacao`, `alterado`; alguma `naoverif` ou bytes depois da última assinatura, `nao_conferido`;
senão `integro`. Prefixo e CPF não entram na situação: a etapa 5 decide o que fazer com cada um.

### 3.5 Motivos

Por assinatura (os quatro primeiros são os textos do protótipo):

| Veredito | Motivo |
|---|---|
| `alterada` | Faixa de bytes inválida |
| `alterada` | O conteúdo não corresponde ao que foi assinado |
| `alterada` | A assinatura não confere com o certificado |
| `naoverif` | Formato de assinatura não reconhecido |
| `integra` | O conteúdo e a assinatura conferem com o certificado |
| `alterada` | Faixa de bytes inválida: a declaração da faixa fica fora do trecho assinado |
| `alterada` | Faixa de bytes inválida: o trecho fora da faixa não contém só a assinatura |
| `naoverif` | A assinatura passa do tamanho máximo de conferência |
| `naoverif` | A assinatura passa dos limites de conferência |
| `naoverif` | Algoritmo de resumo não suportado |
| `naoverif` | O certificado do signatário não está no arquivo |
| `naoverif` | Algoritmo de assinatura não suportado |
| `naoverif` | Curva elíptica não suportada |
| `naoverif` | Não foi possível conferir a chave do certificado |
| `naoverif` | Chave curta demais para conferir |
| `naoverif` | Assinatura sem atributos assinados: o resumo não foi conferido |
| `naoverif` | Carimbo do tempo do documento: a conferência fica para o validador do servidor |

Do arquivo: "O arquivo está vazio", "O arquivo passa do limite de 20 MB para conferência", "O arquivo não é um PDF",
"O PDF tem mais assinaturas do que o limite de conferência", "Não foi possível ler o PDF", "O PDF não tem assinatura
digital", "A assinatura confere com o conteúdo e com o certificado", "As N assinaturas conferem com o conteúdo e com os
certificados", "O primeiro signatário bloqueou alterações, mas o arquivo foi alterado depois da assinatura", "O
arquivo recebeu acréscimos depois da última assinatura, e esse trecho não foi conferido" e, para `alterado` ou
`nao_conferido` vindo de uma assinatura, o motivo dela com "(assinatura i de n)" quando há mais de uma.

## 4. Regras (porta do protótipo)

### 4.1 `analisar` e `umaAss`

- Procura `/ByteRange [a l c m]`; números iguais (mesmo texto) contam uma vez; ordena pelo fim (`c + m`).
- Faixa válida: `a = 0`, `c + m <= tamanho`, `a + l <= c` (protótipo) e mais: nenhum número com mais de 12 dígitos,
  pelo menos 2 bytes de fora (`<>`), a declaração `/ByteRange` dentro do trecho assinado e o trecho de fora com só
  `<`, dígitos hexadecimais, espaço em branco do PDF e `>`.
- Dicionário da assinatura: do último ` obj` antes de `l` (até 4.000 bytes antes) até `l`, mais 3.000 bytes depois de
  `c`, cortado no primeiro `endobj`. Dele saem o tipo (`ETSI.CAdES`, `adbe.pkcs7`, `ETSI.RFC3161`), o `/M` e o DocMDP
  (o primeiro `/P` a partir de 200 bytes antes de `/DocMDP`; sem `/P`, 2). Com faixa inválida nada disso é lido.
- CMS: hexadecimal do trecho de fora; SignedData; certificados `[0]`; pula CRLs `[1]`; só o primeiro SignerInfo. As
  CRLs, os outros SignerInfos, o adbe-revocationInfoArchival e os atributos não assinados não são lidos (M23).
- `permiteCarimbo`: `!(primeira com DocMDP && P < 3) && !cifrado && nenhuma alterada`.

### 4.2 `confere` e `cert`

- Resumo: `SHA-256`, `SHA-384`, `SHA-512` e `SHA-1` (OIDs do protótipo); outro, `naoverif` sem dados do certificado.
- Certificado: o do número de série do SignerInfo; sem par, o primeiro do CMS.
- Com atributos assinados: messageDigest contra o hash de `[0, l)` e `[c, c + m)`; signingTime vira a hora declarada;
  a assinatura é conferida sobre os atributos com a etiqueta trocada para SET (0x31). Sem atributos: resumo `null`, a
  assinatura é conferida sobre o próprio conteúdo.
- Chave: RSA PKCS#1 v1.5 (`rsaEncryption` usa o hash do resumo; `sha*WithRSAEncryption` usa o do OID), RSA-PSS (hash do
  resumo, MGF1 com o mesmo hash, sal do tamanho do hash) e ECDSA (hash do OID ou, com `ecPublicKey`, o do resumo).
- Titular e emissor pelo CN (2.5.4.3), organização do emissor pelo O (2.5.4.10), validade pelo `notBefore` e
  `notAfter`.

### 4.3 `prefixo`, `objeto` e `infla`

- Prefixo: a nova versão tem pelo menos o tamanho da anterior e começa byte a byte com ela. Versão anterior vazia não
  confere.
- `objeto`: a última ocorrência de `numero geracao obj`; sem ela, procura nos `/ObjStm` do último para o primeiro,
  inflando os que têm `FlateDecode` (sem os CR e LF do fim, como o protótipo).
- `infla`: formato zlib, em pedaços de 1 KB, parando ao passar do limite.

## 5. Limites (`LimitesDaConferencia`)

| Constante | Valor | O que acontece ao passar |
|---|---|---|
| `PDF_MAXIMO_EM_BYTES` | 20 MB | `ilegivel` ("passa do limite de 20 MB"); `objeto` devolve null |
| `DECLARACOES_DE_FAIXA_MAXIMAS` | 100 ocorrências de `/ByteRange` | `ilegivel` |
| `ASSINATURAS_MAXIMAS` | 30 faixas diferentes | `ilegivel` |
| `DIGITOS_MAXIMOS_DA_FAIXA` | 12 | a assinatura vira `alterada` (faixa inválida) |
| `TRECHO_DA_ASSINATURA_MAXIMO_EM_BYTES` | 16 MB de hexadecimal (8 MB de CMS) | a assinatura vira `naoverif` |
| `PROFUNDIDADE_MAXIMA_DER` | 32 níveis | a assinatura vira `naoverif` ("limites de conferência") |
| `ELEMENTOS_MAXIMOS_DER` | 50.000 elementos por estrutura, sem o interior dos containers não lidos (M23) | idem |
| `CERTIFICADOS_MAXIMOS` | 30 certificados no CMS | idem |
| `BYTES_DE_TAMANHO_MAXIMOS_DER` | 4 (tamanho até 4 GB) | `naoverif` ("Formato de assinatura não reconhecido") |
| `OBJETOS_MAXIMOS` | 10.000 (cabeçalhos `obj` vistos, `/ObjStm` e `/N` de cada um) | `objeto` devolve null |
| `INFLADO_MAXIMO_EM_BYTES` | 10 MB | `inflar` e `objeto` devolvem null |
| `TRABALHO_MAXIMO_EM_BYTES` | 100 MB somados por leitura (copiados mais inflados) | `objeto` devolve null |
| `CARACTERES_MAXIMOS_DE_NOME` | 256 | o nome é cortado |

Leitura sem regex que volta atrás (quantificadores possessivos e busca por `strpos`), avisos do PHP silenciados só em
volta das chamadas de `openssl` e `zlib`, e toda falha capturada (inclusive `\Error`) antes de sair do motor.

## 6. Decisões

Tudo o que não estava no protótipo nem na spec, decidido nesta tarefa (o orquestrador revisa):

- **M1. Onde mora**: serviços, DTOs e enums juntos em `Service/ConferenciaDePdf/`, para o motor ficar num lugar só.
- **M2. Teto de 20 MB**: o dobro do upload de PDF da assinatura (10 MB), para caber as versões assinadas que voltam.
  Os demais limites estão na seção 5.
- **M3. Arquivo que não é PDF**: sem `%PDF-` nos primeiros 1.024 bytes vira `ilegivel`. O protótipo diria "sem
  assinatura".
- **M4. Faixa mais rígida que o protótipo** (4.1): números absurdos, trecho de fora menor que `<>`, declaração da faixa
  fora do trecho assinado e trecho de fora que não é só hexadecimal viram `alterada`. O último fecha o ataque de
  "signature wrapping" (objetos escondidos no espaço reservado da assinatura), que o protótipo aceitava como íntegro
  porque apagava os caracteres que não eram hexadecimais.
- **M5. Assinatura grande demais**: `naoverif`, não `alterada` (passar do limite não prova alteração).
- **M6. RSA-PSS sem biblioteca**: o PHP 8.2 não tem PSS no `openssl_verify`. O motor abre a assinatura com
  `openssl_public_decrypt` sem padding e faz a conferência EMSA-PSS da RFC 8017 em PHP. Usa os mesmos parâmetros do
  protótipo (hash do resumo, MGF1 com ele, sal do tamanho do hash) e, como o protótipo, ignora os parâmetros declarados
  no AlgorithmIdentifier: PSS com sal diferente sai `alterada` nos dois.
- **M7. ECDSA**: `r` e `s` são relidos e recodificados em DER canônico antes do `openssl_verify` (o mesmo que o `r||s`
  do WebCrypto faz). Assinatura que não é DER com dois elementos, ou com número maior que a curva, fica `naoverif`
  como no protótipo. Só P-256, P-384 e P-521, como o protótipo, mesmo o OpenSSL conferindo outras curvas.
- **M8. Tipo da chave**: antes de conferir, o tipo da chave do certificado precisa bater com o algoritmo (RSA com RSA,
  EC com ECDSA); sem isso fica `naoverif`, como o erro de importação do WebCrypto.
- **M9. SubjectKeyIdentifier**: quando o SignerInfo identifica o certificado pelo identificador da chave, o motor
  procura o certificado com aquele identificador (o protótipo ia direto para o primeiro). Sem par, o primeiro.
- **M10. CPF e CNPJ**: primeiro o subjectAltName da ICP-Brasil, OID 2.16.76.1.3.1 (CPF nas posições 9 a 19 do valor)
  e 2.16.76.1.3.3 (CNPJ); sem ele, o sufixo do CN (`NOME:11 dígitos` ou `NOME:14 dígitos`), como o protótipo lê. A
  máscara do CPF é a do protótipo; o CNPJ sai formatado e sem máscara (é dado público). O CPF do responsável do e-CNPJ
  (2.16.76.1.3.4) não é lido. Falha ao ler o subjectAltName só deixa o CPF de fora, não derruba a assinatura.
- **M11. Hora declarada com fuso**: o signingTime manda, como no protótipo; sem ele, o `/M` é lido com o fuso
  (`-03'00'`), que o protótipo ignorava. Data impossível no certificado ou no signingTime vira "Formato de assinatura
  não reconhecido" (no protótipo o `Date` inválido lançava erro e caía no mesmo motivo).
- **M12. DocMDP**: `/P` fora de 1 a 3 vira 2 (o padrão da norma); o protótipo guardava o número lido. P=1 com bytes
  depois da assinatura certificadora marca `alteradoDepoisDaCertificacao` e o arquivo `alterado`. Acréscimo só de
  dados de validação (DSS), que a norma permite mesmo com P=1, também é marcado: separar isso fica para a etapa 6. Com
  P=2 e P=3 o motor não confere o tipo do acréscimo.
- **M13. Situação do arquivo e motivo**: não existiam no protótipo; regras na 3.4.
- **M14. Bytes depois da última assinatura**: as assinaturas continuam íntegras (protótipo), mas o arquivo fica
  `nao_conferido`, porque o trecho sem assinatura pode mudar o que aparece na tela.
- **M15. Carimbo do tempo do documento** (`/SubFilter /ETSI.RFC3161`): fica `naoverif` com motivo próprio. O protótipo
  comparava o messageDigest do carimbo com o hash da faixa e dizia `alterada`, o que é falso alarme em PDF com
  validação de longo prazo. O carimbo não entra na conferência do CPF.
- **M16. CPF esperado**: conferido na assinatura mais recente que não é carimbo do tempo. Com versão anterior, ela
  precisa terminar depois do fim da anterior; senão, `SemAssinatura`. O CPF esperado é comparado só pelos dígitos;
  sem 11 dígitos, `NaoConfere`.
- **M17. Iguais ao protótipo, de propósito**: sem atributos assinados fica `naoverif` mesmo com a chave conferindo;
  algoritmo de resumo desconhecido não mostra o certificado; BER com tamanho indefinido é "Formato de assinatura não
  reconhecido"; SHA-1 é aceito; só o primeiro SignerInfo é lido; hexadecimal com número ímpar de dígitos perde o
  último.
- **M18. Textos do certificado**: BMPString como UTF-16, UTF8String com os bytes inválidos trocados, os demais como
  Latin-1; cortados em 256 caracteres. Nome ausente sai `null` (a tela escreve "Não identificado", como o protótipo).
- **M19. `/Encrypt`**: reconhecido seguido de espaço ou de `<<` (o protótipo só reconhecia o espaço).
- **M20. `ler` não é portado**: ele só lê o original guardado no IndexedDB e depende de `guardar`, que é gravação
  (etapa 5). `objeto` e `infla` saem como serviços próprios para o carimbo da etapa 5.

Decisões da revisão do motor (feature-review-agent):

- **M21. Forma da assinatura ECDSA e chave RSA curta**: a assinatura ECDSA só é conferida quando é exatamente uma
  SEQUENCE com dois INTEGER (`r` e `s`); elemento a mais, a menos, etiqueta trocada ou SET no lugar da SEQUENCE ficam
  `naoverif` ("Não foi possível conferir a chave do certificado"), em vez de o motor aceitar o que vier depois. Chave
  RSA com menos de 512 bits no RSA-PSS fica `naoverif` com "Chave curta demais para conferir" (antes saía `alterada`,
  o que acusava alteração sem prova).
- **M22. Teto de trabalho do `objeto`**: cada `/Type/ObjStm` refazia a busca do `endstream`, a cópia e o inflate do
  mesmo fluxo (1.000 marcadores e um fluxo de 15 MB levavam 20 s). Agora o fluxo é identificado pela posição da
  palavra `stream` e lido uma vez só; o ` obj` precisa estar até 4.096 bytes antes do marcador e o `stream` até 4.096
  bytes depois (senão o fluxo é ilegível e `objeto` devolve null, como antes); e a leitura inteira soma o que copia e
  o que infla, parando com null ao passar de `TRABALHO_MAXIMO_EM_BYTES` (100 MB).
- **M23. Containers que o motor não lê**: PDF com validação de longo prazo traz LCRs no campo crls (`[1]`) do
  SignedData e no atributo assinado adbe-revocationInfoArchival (1.2.840.113583.1.1.8), e carimbos nos atributos não
  assinados; uma LCR real passa fácil de 50.000 elementos. Esses containers, e os SignerInfos depois do primeiro
  (que o motor também não lê), viram blocos opacos: a validação confere que cabem no pai e não desce neles, então o
  teto de elementos e de profundidade vale só para o que o motor de fato analisa. O teto do trecho subiu de 2 MB para
  16 MB de hexadecimal (8 MB de CMS), para caber um /Contents real com LCRs dentro do PDF de 20 MB.

## 7. Testes

`app/tests/AssinaturaEletronica/Unit/ConferenciaDePdf/`, `TestCase` puro. PDFs montados no próprio teste, com o CMS
destacado gerado por `openssl_cms_sign` (DER) e certificado autoassinado criado no teste (CPF no otherName ICP-Brasil
por um arquivo de configuração temporário do OpenSSL). O RSA-PSS (que o PHP 8.2 não assina) tem um vetor gerado pelo
`openssl` de linha de comando, guardado no teste, e um CMS montado à mão no teste.

Casos: RSA válido, ECDSA válido, RSA-PSS válido e com bit trocado, byte alterado na faixa, assinatura de outra chave,
faixa que não cobre o arquivo, segunda assinatura incremental que começa com a primeira versão, versão nova que não
começa com a anterior, DocMDP P=1, P=2 e P=3, CPF esperado igual e diferente, CPF pelo OID e pelo CN, CNPJ, cada limite
da seção 5, PDF sem assinatura, lixo, arquivo vazio, arquivo truncado e mutações aleatórias do CMS (nenhuma lança).

## 8. Pendências para o orquestrador

- PSS com parâmetros diferentes do protótipo (M6) e DSS depois de P=1 (M12) ficam para o pyHanko da etapa 6.
- `adbe.pkcs7.sha1` (resumo dentro do eContent) sai `alterada` como no protótipo; é formato obsoleto, mas fica anotado.
- BER com tamanho indefinido (M17): alguns assinadores antigos geram; se aparecer no teste com o assinador.iti.br,
  decidir se o motor passa a ler.
