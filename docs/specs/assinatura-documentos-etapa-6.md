# Assinatura de documentos, etapa 6: cadeia gov.br e ICP-Brasil no servidor (pyHanko)

Spec geral: `docs/specs/assinatura-documentos.md` (3.2, 3.3, etapa 6, D2). Motor de integridade já pronto:
`docs/specs/assinatura-documentos-etapa-5-motor.md` (M1 a M27). Etapa 5 (gov.br, ICP e documento já assinado):
`docs/specs/assinatura-documentos-etapa-5.md`. Branch `assinatura-desenho`, PR único. Sem migration nova: as colunas da
cadeia entram na M4 (`Version20261109300000`) da etapa 5.

## 1. Decisões

| # | Assunto | Decisão |
|---|---|---|
| E6-1 | Onde roda | Contêiner próprio `validador_pdf` (pasta `validador-pdf/` na raiz), Python 3.12 com pyHanko, imagem com versão fixada e dependências com hash (`--require-hashes`), usuário sem root, sistema de arquivos só de leitura e `/tmp` em tmpfs. O PHP não ganha Python. |
| E6-2 | Redes | `assinatura_net` (`internal: true`) só com `php`, `worker` e `validador_pdf`; `validador_saida` só para o validador baixar LCR, OCSP e as cadeias do ITI. O validador nunca entra na `saas_net` (lá roda o `blog_builder` com npm de terceiros). Sem porta publicada. |
| E6-3 | Ligar em produção | `profiles: ["assinatura"]`, como o `voto`: o deploy só sobe com `COMPOSE_PROFILES=assinatura` (passo do Samuel, runbook). Dev: mesmo serviço com o mesmo profile; a suíte PHP não depende dele. |
| E6-4 | gov.br e ICP só com validador | Sem `ASSINATURA_VALIDADOR_URL` configurada, o envio recusa os níveis gov.br e ICP (sem a cadeia, um certificado autoassinado com qualquer CPF passaria). Validador fora do ar na hora de enviar o PDF assinado: recusa com "tente de novo em alguns minutos"; nada é gravado como avançada ou qualificada sem cadeia confirmada. |
| E6-5 | Âncoras | **A confiança vem da impressão digital fixada no código, não do download.** ICP-Brasil: o pacote oficial do ITI (`ACcompactado.zip`) e o `hashsha512.txt` publicado ao lado são baixados por HTTP (o TLS do acraiz vem sem a intermediária e o repo.iti.br responde com certificado falso); o SHA-512 vem pela mesma conexão, então só pega arquivo corrompido, nunca troca no caminho. Só vira raiz o certificado autoassinado (assinatura própria conferida) cuja impressão digital SHA-256 está fixada no código (`ICP_RAIZES_SHA256`: v4, v5, v6, v7, v12 e v13); autoassinado fora da lista é ignorado com aviso no log. Os demais certificados do pacote são intermediárias e só valem se fecharem cadeia até uma raiz fixada. Ficam de fora as vencidas (a primeira, v1 e v2), as revogadas (v3, v8 e v9) e, por confiança mínima, a v10 (raiz de certificados SSL de servidor) e a v11 (raiz de assinatura de código): estão vigentes, mas não servem para assinar documento, e aceitá-las deixaria qualquer certificado delas com uso de chave de assinatura sair confiável num PDF. A v10 e a v11 ficam na lista de recusadas do código (`RAIZES_RECUSADAS_SHA256`), que vale para as duas famílias. gov.br: raiz própria (não é ICP-Brasil), da cadeia `Cadeia_GovBr-der.p7b` do ITI, também por HTTP; só vira raiz a de impressão digital fixada (`GOVBR_RAIZES_SHA256`). As envs `ASSINATURA_VALIDADOR_ICP_SHA256` e `ASSINATURA_VALIDADOR_GOVBR_SHA256` só acrescentam impressões digitais (raiz nova do ITI antes do PR) e nunca recolocam uma recusada (ignorada, com aviso no log). Nunca confiar em certificado que vem dentro do PDF. Atualização ao subir e a cada 24 h (1 h depois de uma falha); download que falha ou não confere mantém a última boa, guardada no volume `validador_ancoras` e conferida de novo ao subir. Família pedida sem nenhuma raiz carregada (primeira subida com o volume vazio, volume perdido, ITI fora do ar): `503 ancoras_indisponiveis`, nunca uma resposta `200` com `cadeia_nao_confiavel` (seção 2). |
| E6-6 | Regra de combinação (no PHP) | Motor `ilegivel`, `sem_assinatura`, alguma `alterada`, prefixo que não confere ou nenhuma assinatura nova: recusa sem chamar o validador. Senão o validador decide cada assinatura nova: aceita só com `intacta`, `valida`, `confiavel` com a âncora do nível (ICP também vale para quem é gov.br), revogação `boa`, `cobertura` do arquivo inteiro, `nivel_de_modificacao` até `formulario` e `docmdp_ok` não falso. Só então o CPF que o motor leu passa a valer. Revogação: o PHP aceita só com `revogacao=boa`. Signatário que não declara LCR nem OCSP sai `confiavel=true`, `revogacao=desconhecida` e `motivo=revogacao_nao_declarada`: recusa. **`motivo` nulo não quer dizer aceito**: o `motivo` é só o primeiro problema encontrado (na ordem: integridade, assinatura, confiança e revogação, modificação depois, cobertura, DocMDP, carimbo), para log e mensagem; quem decide são os campos acima. |
| E6-7 | O que fica gravado | O objeto de cada assinatura devolvido pelo validador vai para a coluna `cadeia` (JSON) da versão, com `cadeia_situacao` (`confirmada`, `recusada`, `indisponivel`) e `cadeia_conferida_em`. LTV dentro do arquivo (DSS) fica de fora: muda os bytes guardados. |

## 2. Protocolo HTTP `v1` (contrato entre PHP e contêiner)

- Base: `http://validador_pdf:8080`. Autenticação: cabeçalho `Authorization: Bearer {ASSINATURA_VALIDADOR_TOKEN}`,
  comparado em tempo constante; sem ele ou errado, `401`. Tokens e URLs: env `ASSINATURA_VALIDADOR_URL` e
  `ASSINATURA_VALIDADOR_TOKEN` (vazios = não configurado).
- `GET /saude` (sem token): `200 {"v":1,"ok":true,"ancoras":{"icp":N,"govbr":N,"atualizadas_em":"ISO-8601"|null}}`.
  Com alguma família sem raiz carregada: `503` com o mesmo corpo e `"ok":false` (a contagem diz qual família); o
  HEALTHCHECK do contêiner fica não saudável até a atualização trazer as âncoras.
- `POST /v1/conferir?ancora=icp|govbr|qualquer`, corpo = bytes do PDF (`Content-Type: application/pdf`), teto 20 MB
  (`413`), tempo máximo 30 s, até 2 conferências ao mesmo tempo (fila de 10 s, depois `503`). A família pedida tem
  de ter raiz carregada (`icp`, `govbr`, ou as duas para `qualquer`); senão `503 ancoras_indisponiveis` na hora,
  sem ocupar vaga.
- Resposta `200`:

```json
{"v":1,"assinaturas":[{
  "campo":"Signature1","faixa":[0,840,960,1200]|null,
  "intacta":true,"valida":true,"confiavel":true,"ancora":"icp",
  "titular":{"cn":"...","cpf":"12345678900"|null,"cnpj":null},
  "cadeia":[{"cn":"...","sha256":"..."}],
  "revogacao":"boa|revogada|desconhecida",
  "carimbo":{"presente":true,"valido":true,"hora":"ISO-8601"},
  "cobertura":"arquivo_inteiro|revisao_inteira|parcial",
  "nivel_de_modificacao":"nenhuma|ltv|formulario|anotacoes|outra",
  "docmdp_ok":true|null,"motivo":null}]}
```

  O PHP casa cada item com a assinatura do motor pela `faixa` (`/ByteRange`). A `faixa` é `null` quando o
  `/ByteRange` da assinatura não são 4 inteiros não negativos (null, texto, nomes, número real, mais ou menos
  números; nunca sai arredondada nem parcial): o item sai com `intacta=false`, `valida=false`, `confiavel=false`,
  `ancora=null` e `motivo=assinatura_ilegivel`, e o PHP o trata como não íntegro. Assinatura **sem a chave**
  `/ByteRange` não vira item: o pyHanko recusa o arquivo inteiro e a resposta é `400 pdf_ilegivel` (definitivo).
- Erros: corpo `{"v":1,"erro":"<código>"}`, conforme a tabela abaixo. Nunca devolve o PDF nem registra conteúdo em
  log (só rota, situação, tamanho, tempo e resultado).

### 2.1 Respostas do protocolo `v1` (as duas rotas)

Classes, para o PHP (6B e 8B):
- **Passageiro**: "tente de novo em alguns minutos"; nada é gravado como recusa.
- **Cliente ou configuração**: erro do nosso lado (PHP ou env). O PHP faz `logger->error`, mostra "tente de novo"
  e não grava nada.
- **Definitivo para aquele PDF**: mandar o mesmo arquivo de novo dá o mesmo resultado.

| Situação | `erro` | Quando acontece | Rota | Classe |
|---|---|---|---|---|
| `200` | (sem erro) | Conferência feita (JSON acima) ou PDF selado (`application/pdf`) | as duas | resultado |
| `400` | `pdf_ilegivel` | Corpo vazio, corpo menor que o `Content-Length`, PDF que o pyHanko não abre, ou memória esgotada no processo filho (teto de 1,5 GB) | as duas | definitivo |
| `400` | `parametro_invalido` | `ancora` ausente ou fora de `icp`, `govbr`, `qualquer` (minúsculas) | conferir | cliente |
| `401` | `nao_autorizado` | Sem token, token errado ou token vazio no validador (`WWW-Authenticate: Bearer`) | as duas | configuração |
| `404` | `nao_encontrado` | Rota desconhecida (`GET` fora de `/saude`, `POST` fora das duas rotas) | qualquer | cliente |
| `405` | `metodo_nao_permitido` | Qualquer método fora de `GET` e `POST` (`HEAD`, `OPTIONS`, `PUT`, `DELETE`, `PATCH` e outros), com `Allow: GET, POST`; resposta a `HEAD` sem corpo | qualquer | cliente |
| `411` | `tamanho_ausente` | `POST` com `Transfer-Encoding`, sem `Content-Length`, ou com `Content-Length` não numérico ou negativo | as duas | cliente |
| `413` | `pdf_grande_demais` | `Content-Length` acima de 20 MB (o corpo nem é lido) | as duas | definitivo |
| `422` | `nao_selavel` | PDF sem `/Producer (BlueJus)` exato, criptografado, com `/Perms` no catálogo, com qualquer assinatura ou com campo de assinatura vazio (E8-5) | selar | definitivo (selo: E8-4) |
| `500` | `erro_interno` | O processo filho caiu ou levantou um erro inesperado (o log registra o tipo do erro, ou `sem_resposta` com a saída ou o sinal), ou erro inesperado no processo principal ao atender o `POST` (log só com o tipo; se a resposta já tinha começado, a conexão só fecha) | as duas | passageiro |
| `502` | `carimbo_indisponivel` | A ACT configurada não respondeu (etapa 8, E8-3) | selar | passageiro |
| `503` | `ocupado` | As 2 vagas ficaram ocupadas por mais de 10 s (`Retry-After: 10`) | as duas | passageiro |
| `503` | `tempo_esgotado` | A operação passou de 30 s; o filho é morto | as duas | passageiro |
| `503` | `ancoras_indisponiveis` | Família pedida sem raiz carregada (`Retry-After: 60`); no selo, a ICP-Brasil | as duas | passageiro |
| `503` | `selo_nao_configurado` | Sem os `secrets` do selo: arquivo que não existe (PKCS#12 ou senha) ou PKCS#12 vazio | selar | selo: E8-4 |
| `503` | `selo_certificado_invalido` | `secrets` presentes mas inutilizáveis: sem permissão de leitura (por exemplo root 0600), diretório no lugar do arquivo ou outro erro de leitura, grande demais, senha errada, PKCS#12 ilegível ou sem a chave ou sem o certificado, e-CNPJ vencido, revogado, sem o uso de chave ou fora das âncoras | selar | selo: E8-4 |

Além da tabela:
- **Precedência**: vale o primeiro erro, nesta ordem. Os dois `POST`: rota (`404`), token (`401`), tamanho
  declarado (`411`, `413`), corpo menor que o declarado (`400 pdf_ilegivel`). Conferir: `parametro_invalido`, corpo
  vazio (`pdf_ilegivel`), `ancoras_indisponiveis`, fila (`ocupado`), e no filho `tempo_esgotado`, `erro_interno` ou
  `pdf_ilegivel`. Selar: os `secrets` (`selo_nao_configurado`, `selo_certificado_invalido`) antes do corpo vazio
  (`pdf_ilegivel`), depois `ancoras_indisponiveis`, fila, e no filho `pdf_ilegivel`, `nao_selavel`, o PKCS#12
  (`selo_certificado_invalido`) e por fim a cadeia do e-CNPJ (`selo_certificado_invalido`) ou a ACT
  (`carimbo_indisponivel`).
- **Pedido HTTP malformado** (linha do pedido acima de 64 KB, versão inválida, mais de 100 cabeçalhos, cabeçalho
  longo demais, HTTP/2): a biblioteca padrão responde antes do validador, em HTML e sem `{"v":1}`: `400`, `414`,
  `431` ou `505`. O PHP trata como erro de cliente (classe "cliente": `logger->error`, "tente de novo", nada
  gravado).
- **Conexão fechada sem resposta**: o validador aceita até 8 conexões abertas (a nona é fechada na hora) e dá 15 s
  para o pedido inteiro chegar (cabeçalhos e corpo; o tempo de 30 s do socket vale para cada leitura, então sem esse
  prazo um byte a cada 29 s prenderia o fio). Para o PHP é erro de transporte: passageiro, como validador fora do ar.
- **Timeout do cliente PHP: 45 s** (10 s de fila, 30 s no filho, mais o envio e a leitura). Estourou: passageiro.
- **Limitador na 6B**: `tempo_esgotado` é passageiro e cada tentativa ocupa 1 das 2 vagas por até 30 s, então a 6B
  põe um limitador (Symfony RateLimiter) por signatário ou, sem signatário identificado, por IP, na chamada de
  conferência. Sem ele, um PDF feito para demorar ocupa as duas vagas de todos os escritórios.

## 3. Blocos

| Bloco | Escopo | Arquivos | Depende de |
|---|---|---|---|
| 6A Contêiner | `validador-pdf/`: Dockerfile em dois alvos (`producao` e `teste`), `requirements.txt` e `requirements-teste.txt` com hash, servidor HTTP da biblioteca padrão, âncoras, testes Python com PKI de teste gerada no teste (sem internet: `allow_fetching=False`, LCR e OCSP locais). Casos: cadeia boa, raiz desconhecida, revogado, vencido, carimbo válido e inválido, acréscimo que muda página, sombra. | só `validador-pdf/**` | este protocolo |
| 6B Cliente PHP | `Service/Cadeia/HttpValidadorDeCadeia` no lugar da implementação "não configurado" da 5.0, cliente HTTP nomeado, regra E6-6, gravação E6-7, classe do manifesto só com cadeia confirmada; testes com `MockHttpClient` | `Service/Cadeia/**`, `assinatura_eletronica.yaml`, alias em `services.yaml`, chamada no `AssinarComCertificadoUseCase` | 5.0 e 5C |
| 6C Infra | serviço nos dois compose, redes, volume, `profiles`, teste de arranjo do compose, job do CI que roda os testes Python na imagem `teste`, `ConferenteDeSegredos`, `.env*` (nomes vazios), runbook `docs/runbooks/assinatura-validador-em-producao.md` | `docker-compose.yml`, `docker-compose.prod.yml`, `.github/workflows/ci.yml`, teste de arquitetura | 6A (avisar o Coordenador antes: arquivos quentes) |

## 4. Pendências do Samuel

- Assinar um PDF de teste no assinador.iti.br (conta gov.br) e outro com certificado ICP-Brasil A1/A3, para o aceite.
- Esses PDFs respondem também: **o certificado do gov.br declara LCR ou OCSP?** Se não declarar, a E6-6 recusa toda
  assinatura gov.br (`revogacao_nao_declarada`) e a decisão volta ao orquestrador.
- Depois do merge: criar `ASSINATURA_VALIDADOR_TOKEN`, ligar o profile `assinatura` (runbook da 6C).

## 5. Pendências do validador (sem código ainda)

- Conferir com o `ACcompactado.zip` real se existe certificado cruzado da v10 ou da v11 emitido pela v5, v6 ou v12.
  Se existir, uma cadeia SSL ou de código fecharia numa raiz fixada passando pelo cruzado, e ele também tem de ficar
  de fora.
- Restringir o signatário por OID de política (A1, A3 de pessoa física e jurídica) fica para depois dos PDFs reais.

## 6. Notas para a 6C

- Os hashes do `requirements.txt` e do `requirements-teste.txt` são só das wheels **linux x86_64**: a imagem só se
  constrói em amd64 (VPS e CI). Numa máquina ARM o `pip install --require-hashes` falha. O runbook avisa, e o
  compose pode fixar `platform: linux/amd64` no serviço; outra arquitetura exige refazer os hashes com as wheels dela.
- O `/saude` responde `503` enquanto uma família está sem raiz (primeira subida sem rede, volume perdido): o `php` e o
  `worker` não usam `depends_on` com `condition: service_healthy` do `validador_pdf`, senão não sobem com o ITI fora
  do ar. O PHP trata a falta de âncoras como passageira (seção 2.1).
