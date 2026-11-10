# Assinatura: levantamento das etapas 5 (resto), 6 e 8

Somente leitura, 09/11/2026. Código: worktree `assinatura-desenho`, caminhos relativos a `app/`. Desenho `17 - Assinatura -
VS 08.11.26.dc.html` (cópia principal) como dc:NNN; `bj-assina-pdf.js` como pdf.js:NN. Inventário do script (1.209
linhas) conferido nas regiões abaixo.

## 0. Achados que mudam o plano

1. **Não existe nível "presencial" no desenho.** `pres` (dc:373-390, 462-466) é o PDF que **já chegou com assinaturas
   digitais** ("documento já assinado"), não assinatura presencial. O inventário da etapa 3
   (`docs/specs/assinatura-documentos-etapa-3-inventario.md:103`) escreve "presencial/gov.br/ICP": corrigir para
   "documento já assinado ou que recebeu assinatura gov.br/ICP".
2. **A etapa 3 ainda não está no código** (sem `src/AssinaturaEletronica/Pdf/`, sem `ChaveDoSelo`, M2 não criada). O
   "Baixar PDF para assinar" de documento sem assinatura usa `pdf(dA,'assinar')` (dc:466), o código BJA e o manifesto da
   etapa 3. A etapa 5 pode começar pelo que não depende disso (seção e).
3. **O desenho não confere o prefixo no primeiro signatário gov.br/ICP** de documento sem assinatura prévia
   (`assinadoVolta`, dc:385: só confere se `dA.pres`), e regera o PDF para assinar a cada clique ("Emitido em" muda,
   dc:411). Qualquer PDF assinado seria aceito. O servidor precisa **congelar a versão "para assinar"** na primeira
   geração e conferir o prefixo contra ela (spec 3.2: "conteúdo igual ao enviado").
4. **Sem a etapa 6, gov.br e ICP são falsificáveis**: o motor só lê o que o certificado declara (M25); um certificado
   autoassinado com qualquer CPF passa. O manifesto imprimiria "Qualificada (ICP-Brasil)" sem lastro. Recomendação: 5 e
   6 entram juntas (a branch já sai num PR só) e o envio só aceita gov.br/ICP com o validador configurado.
5. **Rede da chave do e-CNPJ (etapa 8)**: o `blog_builder` roda npm de terceiros na `saas_net`
   (`docker-compose.prod.yml`, serviço `blog_builder`). Um contêiner que assina com a chave do BlueJus não pode responder
   nessa rede.
6. **Duplicar no desenho leva `pres.atual`** (dc:368: `Object.assign({}, d, ...)`), isto é, a versão já assinada pelos
   signatários gov.br do outro documento. O servidor deve copiar só o original.

## a) Etapa 5 (resto): inventário do desenho

| Região | Item | Valor do desenho (dc:linha) | Estado(s) |
|---|---|---|---|
| Níveis | opções do select "Forma de assinatura" | "Simples: e-mail e código", "Reforçada: código, selfie e localização", "Conta gov.br", "Certificado ICP-Brasil" (dc:292, 190) | todos no passo 3 |
| Níveis | classe no manifesto | gov.br "Avançada (gov.br)", ICP "Qualificada (ICP-Brasil)" (dc:292, 418) | |
| Passo 1 | upload | `accept=".pdf,.docx"`; PDF lido, `BJAssinaPdf.analisar`; com assinatura vira `pres = {chave: sha256, ana}` e o original é guardado byte a byte (dc:373, pdf.js:25) | vai direto ao passo 3; erro "Não foi possível ler este arquivo. Envie um PDF ou .docx." |
| Passo 3 | quadro "já assinado" | caixa `border:1px solid #bcd6e5; border-left:4px solid {cor}; background:#f5fafd; padding:12px 14px; gap:8px; margin-bottom:10px`, título 14.5px bold `#0b3550` com `bi-patch-check-fill` na cor (dc:188) | só com `pres` (`wPrTem`, dc:466) |
| Quadro | cor e título | verde `#1e7b45` "Documento já assinado: 1 assinatura digital reconhecida" / "...: N assinaturas digitais reconhecidas"; vermelho `#a3232b` "Atenção: há assinatura que não confere com o arquivo" (dc:381) | alguma `alterada` muda tudo para vermelho |
| Quadro | linha por assinatura | ícone 15px: `bi-check-circle-fill` `#1e7b45` (íntegra), `bi-x-circle-fill` `#a3232b` (alterada), `bi-question-circle-fill` `#8a6200` (não conferida); `<b>nome</b>` + doc `#5b7383`; det 13px `#34505f` (dc:188, 381) | |
| Quadro | det | `[data pt-BR ou "data não informada", tipo, situação, "Emissor: X"]` com " · "; situação: "Íntegra: arquivo sem alteração desde esta assinatura" (cobre) / "Íntegra: esta versão está preservada e há acréscimos posteriores" / motivo da alterada / motivo ou "Não foi possível conferir" (dc:381) | nome ausente "Não identificado" (pdf.js:17) |
| Quadro | nota | "O arquivo original fica guardado sem alteração, e novas assinaturas digitais entram ao final dele, sem apagar as anteriores. Aqui se confere a integridade; a validade do certificado e da cadeia ICP-Brasil é confirmada no Validar do ITI." + com DocMDP 1: " O primeiro signatário bloqueou qualquer alteração: nova assinatura digital invalidaria o documento." 12.5px `#34505f` (dc:381) | |
| Conteúdo | parágrafo inserido | "Este documento já continha N assinatura(s) digital(is) de {nomes}. O arquivo original foi preservado sem alteração, com hash SHA-256 {sha}." antes do primeiro lugar de assinatura (dc:374) | entra no PDF com selo e na prévia |
| Detalhe | quadro "já assinado" | o mesmo quadro (dc:218) com botões: "Baixar original com as assinaturas" `bi-shield-lock` (`presOrig`, nome "{nome} - original.pdf", dc:383); "Cópia com carimbo visível" `bi-stamp` (só `ana.carimbo` e nenhuma alterada, dc:466); link `.bjaB` "Conferir no Validar (ITI)" `https://validar.iti.gov.br` `bi-box-arrow-up-right` | `dPrTem` (documento com `pres`, inclusive o que ganhou assinatura gov.br/ICP depois, dc:390) |
| Detalhe | aviso `dPrAv` | 12.5px `#8a6200`: com carimbo possível "Para validar as assinaturas, use o original. O carimbo é um acréscimo visual: as assinaturas continuam íntegras, mas os validadores indicam alteração posterior."; sem: "O primeiro signatário bloqueou alterações neste PDF: use o original e o manifesto com selo." (dc:466) | o segundo texto também aparece para PDF cifrado ou com alterada (o desenho não distingue) |
| Detalhe | botão primário | rótulo "Baixar manifesto com selo" quando `pres` (dc:462); mesmo `pdf(D)` | |
| Detalhe | signatário | "{papel} · Conta gov.br" / "Certificado ICP-Brasil"; info "{data} · {metodo}" (dc:463) | |
| Tela pública | bloco certificado (`aCert`) | some desenhar/digitar, código e selfie (`aEletr = !aCert`, dc:464); `<ol>` 13px `#243845` entrelinha 1.6, 3 itens `.bjaB` margin 2px 0: "Baixar PDF para assinar" `bi-download`; link "Assinar no portal gov.br" (`https://assinador.iti.br/`) ou "Assinar com o seu certificado" (`https://www.gov.br/iti/pt-br/assuntos/certificado-digital`) `bi-box-arrow-up-right`; label "Enviar PDF assinado" `bi-upload` com `input accept="application/pdf"` (dc:254, 466) | nome, CPF e aceite continuam (dc:242-243, 257) |
| Tela pública | situação do envio | 12.5px, entrelinha 1.45: cinza `#5b7383` "Depois de assinar, envie o PDF aqui."; verde `#145a32` "{N} assinatura(s) íntegra(s) em {arquivo}[, com as anteriores preservadas]. A validade do certificado é confirmada no Validar do ITI."; vermelho `#a3232b` com o erro (dc:255, 466) | |
| Tela pública | erros do envio | "Nenhuma assinatura digital encontrada neste arquivo."; "Uma assinatura deste arquivo não confere com o conteúdo."; "Este arquivo não preserva as assinaturas anteriores. Assine o PDF baixado nesta tela, sem imprimir ou salvar como novo."; "Não há assinatura nova neste arquivo."; "Não foi possível ler este arquivo." (dc:385, 466) | |
| Tela pública | baixar | `pres`: última versão, nome "{nome} - para assinar.pdf" (dc:466 + 383); sem `pres`: `pdf(dA,'assinar')`, nome "para_assinar_{nome normalizado}.pdf" (dc:425) | |
| Assinar | validação | nome com 2 palavras, CPF válido, CPF igual ao cadastrado, "Envie o PDF assinado.", aceite (dc:386-388) | não confere CPF do certificado |
| Assinar | método e trilha | `metodo = "Conta gov.br" ou "Certificado ICP-Brasil" + " · arquivo {nome} · {n} assinatura(s) digital(is) no arquivo"`; evento "Assinado por {nome} · {metodo}"; `x.pres.atual` passa a ser o arquivo devolvido (dc:387, 390) | sem rubrica: lugar de assinatura sem imagem, com "Assinado em" (dc:416) |
| Manifesto | tipo | "Tipo: {lei} · {metodo}" (dc:418) | |
| Carimbo | geometria | anotação `/Stamp` `/Name/BlueJus` na **última página**, canto inferior esquerdo (x0 = 20, y0 = 10 a partir do `/MediaBox`), largura `min(380, largura-40)`, altura `12 + 10 * linhas`; fundo `0.95 0.98 1`, borda `0.04 0.44 0.63` 0.7, Helvetica 7.6 `0.05 0.2 0.31`; acréscimo incremental com xref clássica ou fluxo `/XRef` (pdf.js:23) | só sem DocMDP 1-2, sem cifra, sem alterada |
| Carimbo | linhas | até 6 assinaturas BlueJus com rubrica: "Assinatura eletrônica BlueJus: {nome}, CPF ***.{456}.***-**, em {data}" + "Código {cod}. Confira em bluejus.com.br." (dc:384) | sem nenhuma: "Ainda não há assinatura eletrônica feita pelo BlueJus para carimbar." |
| Carimbo | erros | "Arquivo protegido por senha.", "O primeiro signatário bloqueou alterações neste PDF.", "Estrutura do PDF não reconhecida.", "Página não localizada no PDF.", "Anotações não localizadas.", "Verificação final não confirmou a preservação." + " Use o original."; "Não foi possível carimbar. Use o original." (pdf.js:23, dc:384) | conferência final: prefixo, mesmo número de assinaturas, íntegras continuam íntegras |
| Arquivos | nome | " - com carimbo.pdf", " - original.pdf", " - para assinar.pdf", "para_assinar_" (dc:383-384, 425, 466) | |

O desenho **não mostra**: lista de versões devolvidas (só a última, por "Baixar original"), resultado de cadeia,
revogação ou carimbo do tempo, CPF do certificado, validade do certificado. "Versões devolvidas" existem só como
`pres.atual` sobrescrito.

## b) O que já existe no código

| Peça | Onde | Situação |
|---|---|---|
| Motor | `src/AssinaturaEletronica/Service/ConferenciaDePdf/ConferenciaDePdf.php:54` `conferir(pdf, versaoAnterior, cpfEsperado)` | pronto (M1 a M27), sem estado, autowiring |
| Resultado | `ResultadoDaConferencia.php:13-27` (situacao, motivo, tamanho, assinaturas, docMdp, alteradoDepoisDaCertificacao, cifrado, permiteCarimbo, prefixo, assinaturasNovas, cpf), `:29 temAssinatura()`, `:34 ultimaAssinatura()`; `AssinaturaConferida` (faixa, veredito, titular/cpf/cnpj **declarados**, vinculo, acrescimo) | pronto; falta serializar para JSON (M4) |
| Leitura para o carimbo | `LeitorDeObjetosDoPdf.php:21 objeto()`, `InfladorLimitado.php:21 inflar()`; internos `IndiceDoPdf`, `LeitorDeSintaxeDoPdf` | prontos; falta achar a última página (árvore `/Pages`) |
| Fábrica de PDF de teste | `tests/AssinaturaEletronica/Unit/ConferenciaDePdf/OficinaDePdfAssinado.php` | reaproveitar nos testes da 5 |
| Níveis | `Enum/NivelDeAssinatura.php:12-13` (`govbr`, `icp`); tela `public/js/assinatura.js:75`; rótulos em `translations/assinatura_eletronica_tela/...:53-54` | já escolhíveis no passo 3 |
| Trava do envio | `Service/ConferenciaDoEnvio.php:48-50` recusa gov.br/ICP ("ainda não está disponível", `translations/assinatura_eletronica/...:66`); `:54` exige e-mail de todos | abrir na etapa 5 |
| Assinar | `UseCase/AssinarDocumentoUseCase.php:81 executar`, `:123 conferirFormulario` (código obrigatório `:132`), `:222 registrar`, método gravado como código (`'simples'`/`'reforcada'`) | só Simples e Reforçada |
| Entidade | `Entity/DocumentoParaAssinar.php:269 enviar(hash)`, `:375 assinar(...)` com `rubricaChave` string; `Entity/Signatario.php:359 registrarAssinatura` (rubrica obrigatória no método, coluna `rubrica_chave` já nula `:89`) | falta variante sem rubrica |
| Rotas públicas | `Controller/AssinaturaPublicaController.php:65` página, `:86` código, `:99` assinar (POST), `:108` recusa, `:117` arquivo, `:123` logo, `:129` rubrica, `:136` pega-tudo (prioridade -10) | novas rotas precisam de prioridade acima do pega-tudo |
| Rotas internas | `Controller/ArquivosDaAssinaturaController.php:30` arquivo, `:36` logo, `:42` rubrica; `DocumentosDaAssinaturaController.php:52-145` | |
| Tela pública | `Service/PaginaDoSignatario.php:52 publico()` (sem flag de certificado; `:69` só `reforcada`), `templates/assinatura_eletronica/publica.html.twig`, `public/js/assinatura-publica.js` (450 linhas) | |
| Upload do PDF | `Service/ArquivosDoRascunho.php:38 gravarPdf` grava os bytes como vieram (`FonteDeUploadHttp`); `ConferenciaDeUpload.php:16` teto 10 MB; o arquivo fica no navegador até salvar (`assinatura.js:660`) | o quadro do passo 3 precisa de conferência no servidor antes de salvar |
| Hash do envio | `Service/ConteudoCanonicoDoDocumento.php:34` (JSON canônico com o sha256 do PDF) | o PDF para assinar não é o hash impresso |
| Eventos | `Enum/TipoDeEvento.php:14-32` (sem eventos de certificado) | acrescentar |
| Arquivos | `Armazenamento/ChavesDeAssinaturaEletronica.php:28-85` (categoria `ASSINATURA_DOCUMENTO`, isolada por escritório); espelho R2 `Shared/EspelhoRemoto/Reconciliacao/FontesDeReferencia.php:61-67`; `Repository/DocumentoParaAssinarRepository.php:110 arquivosEmUso` | versões entram nos três |
| Purga | `Tenant/UseCase/PurgarEscritorioUseCase.php:317-320` | versão cai pela CASCADE do documento; arquivos saem com o diretório |
| Limitadores | `config/packages/assinatura_eletronica.yaml:6-22` | um novo para upload público |
| Dependências | `symfony/process` (`composer.json:49`) e `symfony/http-client` (`:44`, uso em `Djen/Service/DjenClient.php`) já em `require` | prontos para 3, 6 e 8 |

## c) Etapa 6: cadeia no servidor (pyHanko, D2)

**O que a spec pede** (seção 3.3, etapa 6, D2): contêiner interno com pyHanko chamado pelo PHP; gravar situação, cadeia,
CPF ou CNPJ, data e carimbo do tempo; cadeias ICP-Brasil e gov.br atualizadas por tarefa agendada; resolver o que o motor
deixou: cadeia até a raiz, revogação (LCR/OCSP), carimbo do tempo, LTV/DSS depois de P=1 (M12), PSS com parâmetros
diferentes (M6), `/Perms` e DSS indiretos (pendências da seção 8 do motor), sombra e aparência (M27). A âncora gov.br
("ICP-GOV") **não é** ICP-Brasil.

**Encaixe no compose** (sem alterar nada agora):
- Precedente de imagem própria: `blog_builder` (`build: ./builder-blog`, versão da base fixada pela tag, sem porta,
  `cpus` e `mem_limit`). Precedente de serviço desligado até o Samuel ligar: `voto` (`profiles: ["voto"]`, `environment`
  explícito em vez de `env_file`, fora do rollback do `scripts/vps/deploy-automatico.sh:255`).
- Proposta: pasta `validador-pdf/` na raiz (Dockerfile `python:3.12.x-slim` fixado, `requirements.txt` com versão exata e
  `--require-hashes`, usuário sem root, `read_only: true`, `tmpfs /tmp`), serviço `validador_pdf` com
  `profiles: ["assinatura"]` em produção, `mem_limit: 512m`, `cpus: 1`, healthcheck em `GET /saude`, volume
  `validador_ancoras` para as cadeias baixadas.
- **Redes**: `assinatura_net` (`internal: true`) só com `php`, `worker` e `validador_pdf`; e uma rede de saída só do
  validador (`validador_saida`) para baixar LCR, OCSP e as cadeias do ITI. O validador **não** entra na `saas_net`
  (blog_builder e voto ficam de fora). `deploy-prod-tls.sh:117` faz `up -d --remove-orphans`: com profile, o deploy não o
  sobe sem `COMPOSE_PROFILES=assinatura` no `.env.prod`.
- Dev: o mesmo serviço em `docker-compose.yml` com profile `assinatura` (opt-in como o `worker`); a suíte PHP não depende
  dele.

**Como o PHP fala com ele**: HTTP interno (o contêiner do PHP não tem Python e não pode dar `docker exec`).
`POST http://validador_pdf:8080/v1/conferir` (multipart: `pdf`, `anterior` opcional, `ancora` = `icp`|`govbr`|`qualquer`),
cabeçalho `Authorization: Bearer {ASSINATURA_VALIDADOR_TOKEN}`, teto 20 MB, tempo máximo 30 s; resposta JSON
`{"v":1, "assinaturas":[{"faixa":[a,l,c,m], "intacta", "valida", "confiavel", "ancora", "cadeia":[CN...],
"revogacao":"boa|revogada|desconhecida", "carimbo":{"presente","valido","hora"}, "cobertura", "nivel_de_modificacao",
"docmdp_ok", "motivo"}]}`. Casamento com o motor pela faixa de bytes. No PHP: `HttpClient` com cliente nomeado
(`framework.http_client.scoped_clients` em `config/packages/assinatura_eletronica.yaml`), `ASSINATURA_VALIDADOR_URL`
vazia = não configurado.

**O que validar no Python** (API a conferir na documentação da versão fixada): `PdfFileReader(..., strict=False)`,
`reader.embedded_signatures`, `validate_pdf_signature` / `validate_pdf_ltv_signature` com `ValidationContext`
(`trust_roots` = só as raízes; intermediárias do pacote do ITI em `other_certs`; `revocation_mode` "hard-fail" para o
signatário), diff com `DEFAULT_DIFF_POLICY` (campos `intact`, `valid`, `trusted`, `coverage`, `modification_level`,
`docmdp_ok`, `timestamp_validity`). Isso cobre cadeia, revogação, carimbo do tempo (assinatura e documento), DocMDP com
DSS, PSS com parâmetros declarados e a análise de revisões (sombra: objeto preparado numa revisão e ativado noutra).
Aparência `/AP` do campo que cobre a página: o diff do pyHanko trata mudança de aparência como modificação; conferir no
teste com um caso de sombra montado.

**Regra de combinação (proposta)**: motor `ilegivel`, `sem_assinatura`, alguma `alterada`, prefixo `NaoConfere` ou
`assinaturasNovas = 0`: recusa sem chamar o validador. Motor `nao_conferido` só por acréscimo (M27) ou bytes depois:
o pyHanko decide (nova assinatura com cobertura do arquivo inteiro e `modification_level` até preenchimento de
formulário/LTA e `docmdp_ok`: aceita; senão recusa com o motivo). Nova assinatura: `confiavel` com a âncora do nível
(ICP aceita também para quem é gov.br) e revogação boa; aí o CPF do motor (`Confere`) passa a valer de fato.
Validador fora do ar ou sem configurar: recusa com "tente de novo" (nada gravado como avançada ou qualificada).

**Raízes**: ITI, repositório oficial (`https://www.gov.br/iti/pt-br/assuntos/repositorio`); pacote único
`ACcompactado.zip` com o `hashsha512.txt` ao lado (scripts da comunidade apontam para
`acraiz.icpbrasil.gov.br/credenciadas/CertificadosAC-ICP-Brasil/`; confirmar a URL e conferir o SHA-512 antes de
extrair). Raízes vigentes "Autoridade Certificadora Raiz Brasileira" (a lista exata sai do pacote, só as autoassinadas
viram âncora). gov.br: cadeia própria (ICP-GOV); a fonte oficial não foi achada na busca: confirmar no ITI e **fixar a
impressão digital SHA-256 da raiz no código**, nunca confiar no que vem dentro de um PDF. Atualização: o próprio contêiner
baixa ao subir e a cada 24 h (laço interno), guarda no volume e mantém a última boa se o download falhar.

**Testes sem internet**: (1) Python: PKI de teste gerada no teste com **certomancer** (do mesmo autor do pyHanko:
raiz, intermediária, folha com CPF no otherName 2.16.76.1.3.1, LCR e OCSP em arquivo) ou `openssl` com config
temporária, PDFs assinados pelo próprio pyHanko; casos: cadeia boa, raiz desconhecida, revogado, vencido, carimbo
válido e inválido, P=1 com DSS, acréscimo que muda página, sombra; `allow_fetching=False` com LCR/OCSP locais.
(2) PHP: `MockHttpClient` para o cliente e a regra de combinação. (3) Teste de arranjo do compose como
`tests/Blog/Unit/ComposeDoBlogBuilderTest.php` e `tests/Arquitetura/VotoInfraArquiteturaTest.php` (rede, profile,
sem porta, sem `env_file`). (4) Amostras reais (gov.br e ICP) só no teste manual: têm CPF, não vão para o repositório.

## d) Etapa 8: selo PAdES com e-CNPJ (D3)

**O que a spec pede** (3.5, etapa 8, D3): assinar o PDF final com e-CNPJ A1 do BlueJus e carimbo do tempo de ACT
ICP-Brasil; opcional, depois do piloto; sem migration; só o segredo do certificado.

**Como fazer**: o mesmo contêiner da etapa 6 ganha `POST /v1/selar` (pyHanko: `SimpleSigner.load_pkcs12`, `PdfSigner`
com `PdfSignatureMetadata(field_name="SeloBlueJus", subfilter PAdES, embed_validation_info=True, use_pades_lta=True)` e
`HTTPTimeStamper`; campo invisível; acréscimo incremental sobre o PDF em imagem da etapa 3). No PHP, interface
`SeladorDePdf::selar(string $pdf): ?string` (nulo sem configuração) chamada pela implementação de `PdfFinalDoDocumento`
(bloco 1b da etapa 3) **antes** de `guardarArquivoFinal`: `hash_final` passa a ser o do PDF selado; o hash impresso
(`hash_enviado`, E3-7) não muda. Falha do selo: a mensagem assíncrona tenta de novo (retry do Messenger); esgotado,
guarda sem selo e registra `selo_nao_aplicado` na trilha. Só sela PDF com `/Producer (BlueJus)` e sem assinatura
(limita abuso do endpoint).

**Certificado de teste**: script em `validador-pdf/testes/pki/` (certomancer ou `openssl req/ca` com config temporária):
"AC Raiz Teste BlueJus" > "AC Teste e-CNPJ" > folha com CNPJ (otherName 2.16.76.1.3.3) e responsável (2.16.76.1.3.4),
exportada em PKCS#12 com senha de teste; TSA de teste (serviço do certomancer ou o carimbador fictício do pyHanko).
Teste de ida e volta: o motor `ConferenciaDePdf` confere o PDF selado (1 assinatura íntegra, CNPJ de teste) e o
`/v1/conferir` com a raiz de teste confirma a cadeia.

**Onde o certificado real entra**: arquivos fora do git em `/opt/jusprime/secrets/` (mesmo padrão da `sites_deploy_key`),
montados **só no validador** por `secrets:` de serviço do compose (`/run/secrets/selo_pkcs12` e
`/run/secrets/selo_pkcs12_senha`); o PHP nunca vê a chave. URL da ACT em `ASSINATURA_SELO_TSA_URL` (só no validador).
Runbook novo `docs/runbooks/assinatura-selo-ecnpj-em-producao.md`.

**Pendente do Samuel**: comprar o e-CNPJ A1 (D3, depois do piloto); contratar carimbo do tempo de ACT ICP-Brasil (é
pago; TSA grátis não é ICP-Brasil); gravar os arquivos na VPS; ligar o profile e publicar.

## e) Divisão proposta em blocos

Branch `assinatura-desenho`, PR único no fim. Cada bloco num implementador próprio (worktree se paralelo).

### Etapa 5

| Bloco | Escopo | Arquivos (dono exclusivo) | Depende de |
|---|---|---|---|
| 5.0 Contrato | **M4 `Version20261109300000`**: tabela `assinatura_eletronica_versao` (id, documento_id FK CASCADE, signatario_id NULL FK SET NULL, ordem smallint, origem varchar(20) `original`/`para_assinar`/`devolvida`, arquivo_chave, nome_original, sha256 char(64), tamanho int, analise JSON, criado_em; **colunas da 6 já aqui**: cadeia JSON NULL, cadeia_situacao varchar(20) NULL, cadeia_conferida_em timestamp NULL; único (documento_id, ordem)); entidade `VersaoDoPdf` + repositório (`ultima`, `original`, `paraAssinar`); enum `OrigemDaVersao`; `Signatario::registrarAssinaturaComCertificado` e `DocumentoParaAssinar::assinarComCertificado` (sem rubrica); `ChavesDeAssinaturaEletronica::versao/novaVersao`; R2 (`FontesDeReferencia`, `DatasDasReferencias` e testes); `arquivosEmUso` com versões; `TipoDeEvento` (`pdf_para_assinar_gerado`, `pdf_assinado_recusado`) com textos; `SerializacaoDaConferencia` (resultado do motor em JSON `v:1`, fora do motor); interface `ValidadorDeCadeia` + `ResultadoDaCadeia` + implementação "não configurado" (contrato da 6); `ConferenciaDoEnvio` libera gov.br/ICP só com `ValidadorDeCadeia::configurado()`; blocos de tradução vazios `ja_assinado:` (tela) e `certificado:` (pública); spec `docs/specs/assinatura-documentos-etapa-5.md` | migration, `Entity/`, `Repository/`, `Enum/`, `Armazenamento/`, R2, `ConferenciaDoEnvio`, `services.yaml` (alias) | nada |
| 5A Já assinado (interno) | `POST /assinatura/pdf/conferir` (sem gravar, devolve o quadro); salvar rascunho grava a versão `original` com análise e insere o parágrafo (dc:374); Duplicar copia só o original; `ApresentacaoDaConferencia` (quadro dc:381: título, cor, linhas, nota, aviso, pode carimbar); quadro no passo 3 e no detalhe; "Baixar original com as assinaturas" (última versão); rótulo "Baixar manifesto com selo"; link do ITI | `SalvarRascunhoUseCase`, `ArquivosDoRascunho`, `DuplicarDocumentoUseCase`, controller novo `ConferenciaDoPdfController`, ação "original" em `ArquivosDaAssinaturaController`, `ApresentacaoDoDocumento` (campo `jaAssinado`), `public/js/assinatura-ja-assinado.js` + ligação em `assinatura.js`, traduções `ja_assinado:` | 5.0 |
| 5B Carimbo | `Service/CarimboVisivel` (porta de `carimbar`, pdf.js:23) + `LocalizadorDaUltimaPagina` (árvore `/Pages` com `LeitorDeObjetosDoPdf`), conferência final pelo motor; rota `GET /assinatura/documentos/{id}/carimbo` em controller próprio; nunca grava | `Service/CarimboVisivel*`, `Controller/CarimboDaAssinaturaController.php`, testes com `OficinaDePdfAssinado` | 5.0 (só para a rota) |
| 5C Tela pública | `GET /assinar/{token}/para-assinar` (com `pres`: última versão; sem: PDF da etapa 3 em modo assinar, **congelado** como versão `para_assinar` na primeira vez, sob trava da linha); `POST /assinar/{token}/pdf-assinado` (confere sem gravar, devolve os textos de dc:466); `AssinarComCertificadoUseCase` (reconfere o arquivo reenviado, regra de combinação, grava `devolvida`, registra, trilha, conclusão como a Simples); POST de assinar despacha por nível; `PaginaDoSignatario` (flag `certificado`, nível, URLs); bloco `aCert` em `assinatura-publica.js`; limitador de upload; método e classe no detalhe e no manifesto | `AssinaturaPublicaController` (rotas novas), `UseCase/AssinarComCertificadoUseCase`, `UseCase/ObterPdfParaAssinarUseCase`, `PaginaDoSignatario`, `assinatura-publica.js`, `publica.html.twig`, `assinatura_eletronica.yaml` (limitador), traduções `certificado:` | 5.0; caminho sem `pres` espera etapa 3 (bloco 0 código BJA, 1a montador modo assinar, 1b manifesto) |

5A e 5B em paralelo logo depois do 5.0; 5C começa pelo caminho `pres` e pelo upload e fecha depois da etapa 3.
Revisão independente antes da vigia (spec, risco MÉDIO).

### Etapa 6

| Bloco | Escopo | Arquivos | Depende de |
|---|---|---|---|
| 6.0 Contrato | protocolo HTTP `v1` (seção c), nomes (`validador_pdf`, `assinatura_net`, `validador_saida`, `ASSINATURA_VALIDADOR_URL`, `ASSINATURA_VALIDADOR_TOKEN`), tabela de combinação, formato do JSON `cadeia` gravado | spec `assinatura-documentos-etapa-6.md` | 5.0 |
| 6A Contêiner | `validador-pdf/` (Dockerfile, requirements com hash, `servidor.py` com `ThreadingHTTPServer` da biblioteca padrão e semáforo, `ancoras.py`, testes Python com PKI de teste) | só `validador-pdf/**` | 6.0 |
| 6B Cliente PHP | `Service/Cadeia/HttpValidadorDeCadeia` (troca o alias), cliente nomeado, gravação nas colunas da M4, classe do manifesto só com cadeia confirmada; testes com `MockHttpClient` | `Service/Cadeia/**`, `assinatura_eletronica.yaml` (cliente), `services.yaml` (alias), `AssinarComCertificadoUseCase` (chamada) | 6.0 e 5C |
| 6C Infra | serviço nos dois compose, redes, volume, teste de arranjo do compose, job do CI que roda os testes Python dentro da imagem, `ConferenteDeSegredos` (token), `.env*` (nome vazio), runbook `assinatura-validador-em-producao.md` | `docker-compose.yml`, `docker-compose.prod.yml`, `.github/workflows/ci.yml`, `tests/Arquitetura/ValidadorPdfInfraArquiteturaTest.php` | 6.0 |

**Migration**: nenhuma nova se as colunas da cadeia entrarem na M4 (recomendado; a spec já diz "na M4").

### Etapa 8

| Bloco | Escopo | Arquivos | Depende de |
|---|---|---|---|
| 8.0 Contrato | interface `SeladorDePdf` + nula, `POST /v1/selar`, nomes dos `secrets`, eventos `pdf_selado`/`selo_nao_aplicado` | spec `assinatura-documentos-etapa-8.md`, `TipoDeEvento` | 6.0; etapa 3 bloco 1b |
| 8A Python | `/v1/selar`, PKI e TSA de teste, testes | `validador-pdf/**` (depois do 6A, mesmo dono) | 6A |
| 8B PHP | `HttpSeladorDePdf`, chamada em `PdfFinalDoDocumento`, retry e fallback, teste de ida e volta pelo motor | `Service/Selo/**`, implementação do PDF final da etapa 3 | 8.0, etapa 3 1b |
| 8C Infra | `secrets:` no compose de produção, runbook | `docker-compose.prod.yml` | 6C |

**Migration**: nenhuma (trilha guarda o selo; `hash_final` já existe na M2).

### Arquivos quentes (avisar o Coordenador antes)

`docker-compose.yml`, `docker-compose.prod.yml` (6C, 8C), `.github/workflows/ci.yml` (6C), `config/services.yaml`
(5.0, 6B), migrations (M4 só no 5.0), `Dockerfile` do PHP **não** muda (o Python fica no contêiner próprio). Dentro da
branch: `assinatura.js` só 5A, `assinatura-publica.js` só 5C, `AssinaturaPublicaController` só 5C, traduções cada bloco
no próprio sub-bloco.

## f) Lacunas e conflitos (recomendação pelas 3 regras)

| # | Lacuna | Recomendação |
|---|---|---|
| L1 | Prefixo do primeiro gov.br/ICP sem `pres` não conferido no desenho (dc:385) e PDF para assinar regerado a cada clique | congelar a versão `para_assinar` e conferir o prefixo (regra 2 da spec 3.2; a tela não muda, só o erro "Este arquivo não preserva..." passa a valer também aqui) |
| L2 | assinador.iti.br pode não preservar os bytes (spec 3.2) | teste manual do Samuel com conta gov.br antes de fechar a 5C; se não preservar: conferir pelo código BJA impresso e número de páginas, como a spec já prevê |
| L3 | CPF do certificado: o desenho não confere; e-CNPJ traz o CPF do responsável em 2.16.76.1.3.4, que o motor não lê (M10) | conferir com o CPF digitado (spec); `SemCpfNoCertificado` recusa com texto novo curto; ler 2.16.76.1.3.4 fica para decisão (mexe no motor pronto) |
| L4 | Resultado da cadeia: o desenho só mostra integridade e manda ao ITI | usar a cadeia para aceitar/recusar e para a classe do manifesto (necessário: ESPEC); acrescentar ao `det` de cada linha do quadro existente só "Cadeia ICP-Brasil confirmada"/"não confirmada" (regra 3: decisão do Coordenador; sem componente novo); a nota do desenho fica igual |
| L5 | Duplicar leva a versão assinada de outro documento (dc:368) | copiar só o original e a análise dele (regra 2 de coerência das evidências; a tela é a mesma) |
| L6 | Quadro do passo 3 aparece antes de salvar; o arquivo só sobe ao salvar (`assinatura.js:660`) | endpoint de conferência sem gravar (plumbing, sem mudança visível); alternativa de rodar `bj-assina-pdf.js` no navegador descartada: o motor do servidor é mais rígido (M4, M24, M27) e o quadro mostraria outra coisa |
| L7 | Documento com `pres` que já chega com assinatura `alterada`: o desenho deixa enviar, mas recusa todo PDF devolvido com qualquer `alterada` (dc:385) | seguir o desenho literal (regra 1): signatário gov.br/ICP desse documento não consegue devolver; o quadro vermelho já avisa |
| L8 | Teto: upload interno 10 MB (`ConferenciaDeUpload.php:16`), motor 20 MB (M2) | devolvido até 20 MB; original 10 MB |
| L9 | Validador fora do ar na hora do "Enviar PDF assinado" | recusa com "tente de novo em alguns minutos" (nunca grava como avançada/qualificada sem cadeia); alternativa assíncrona fica de fora (regra 3) |
| L10 | Carimbo do tempo de ACT ICP-Brasil é pago | sem ACT contratada, a 8 sela sem carimbo (PAdES-B-B) ou espera; decisão do Samuel junto com a compra (D3) |
| L11 | E-mail obrigatório para todos no envio (`ConferenciaDoEnvio.php:54`), embora gov.br/ICP não use código | manter (convenção da etapa 2: convite e avisos por e-mail; regra 2) |
| L12 | Etapa 3 inventário diz "presencial" | corrigir o texto no próximo commit da spec (achado 1) |
| L13 | LTV longo (DSS no arquivo guardado) | fora: guarda o relatório em `cadeia`; DSS muda os bytes (regra 3) |

## g) Pendências do Samuel (ação)

1. Assinar um PDF de teste no assinador.iti.br com conta gov.br e entregar o arquivo (L2), e um com certificado
   ICP-Brasil A1/A3 (aceite da 6).
2. Ligar o profile `assinatura` e o token do validador em produção depois do merge (runbook da 6C).
3. Etapa 8: comprar o e-CNPJ A1, contratar ACT, gravar os arquivos em `/opt/jusprime/secrets/` (D3).

Fontes da web consultadas: repositório do ITI (`gov.br/iti/pt-br/assuntos/repositorio`), página do pacote AUR
`ca-certificates-icp_br` (padrão `ACcompactado.zip` + `hashsha512.txt`), documentação do pyHanko em
`pyhanko.readthedocs.io` (validação e diff analysis), artigo SBSeg sobre a ICP-GOV (`sol.sbc.org.br`).
