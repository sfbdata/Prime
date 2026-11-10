# Assinatura de documentos, etapa 8: selo PAdES do BlueJus no PDF final

Spec geral: `docs/specs/assinatura-documentos.md` (3.5, etapa 8, D3). Contêiner e protocolo: `assinatura-documentos-etapa-6.md`.
PDF final: `assinatura-documentos-etapa-3.md` (E3-1, E3-4). Sem migration.

## 1. Decisões

| # | Assunto | Decisão |
|---|---|---|
| E8-1 | Onde sela | No mesmo contêiner `validador_pdf`, endpoint `POST /v1/selar`, com pyHanko (campo invisível `SeloBlueJus`, PAdES, acréscimo incremental sobre o PDF em imagem da etapa 3). A informação de validação embutida (DSS, B-LT) só vai com ACT configurada: **sem ACT o selo sai sem DSS** (PAdES B-B, E8-3). O certificado do selo é conferido contra as âncoras ICP-Brasil antes de selar nos dois casos. |
| E8-2 | A chave | O PKCS#12 e a senha ficam fora do git em `/opt/jusprime/secrets/`, montados só no validador por `secrets:` do compose (`/run/secrets/selo_pkcs12`, `/run/secrets/selo_pkcs12_senha`). O PHP nunca vê a chave. |
| E8-3 | Carimbo do tempo | `ASSINATURA_SELO_TSA_URL` (só no validador). Sem ACT configurada, sela sem carimbo (PAdES B-B); com ACT, PAdES com carimbo. A ACT ICP-Brasil é paga: decisão do Samuel junto com a compra do e-CNPJ (D3). |
| E8-4 | Quando sela | A implementação de `PdfFinalDoDocumento` (`PdfFinalComSelo`, etapa 3, bloco 1b) sela entre gerar o PDF na área temporária e gravá-lo no armazenamento (`SeloDoPdfFinal::aplicar()`), antes de `guardarArquivoFinal`; `hash_final` passa a ser o do PDF selado; o hash impresso (`hash_enviado`) não muda. **Não configurado**: sem `ASSINATURA_VALIDADOR_URL`, ou resposta `503 selo_nao_configurado` (só ela; quer dizer "sem os `secrets`"): guarda sem selo e não grava nada na trilha. **Falha definitiva** (`400 pdf_ilegivel`, `413`, `422 nao_selavel` e o PDF acima de 19 MiB, `PdfDaAssinatura::TETO_PARA_O_MOTOR`, que nem sai do PHP: o selo acrescenta de 10 a 20 KB e o selado precisa passar no Validar, de 20 MiB, decisão de 10/11): cai na hora para "sem selo", grava `selo_nao_aplicado` na trilha e faz `logger->error`, sem tentar de novo (o mesmo PDF dá o mesmo erro, etapa 6, 2.1). **Falha que tenta de novo**: qualquer outra resposta que não seja `200` (inclusive `503 selo_certificado_invalido`, `ancoras_indisponiveis` e erro de transporte). No worker, a mensagem `GerarPdfFinalDoDocumento` leva a tentativa (começa na 1): antes da 4ª, o handler despacha a mesma mensagem com a tentativa seguinte e `DelayStamp` de 1, 5 e 15 min e termina sem erro (o retry do transporte, de 2, 4 e 8 s, é curto para o validador fora do ar); a 4ª é a última e guarda sem selo, com `selo_nao_aplicado` e `logger->error`. Erro de configuração (token, rota) faz `logger->error` a cada tentativa. Na web (o download do concluído sem PDF guardado, E3-4), uma tentativa só, nunca a última: a falha que tenta de novo não guarda nada, despacha `GerarPdfFinalDoDocumento` e responde o 429 que já existe ("O PDF deste documento está sendo gerado. Tente de novo em alguns minutos."); não configurado ou falha definitiva guardam sem selo, como no worker. Assim a web nunca deixa um documento sem selo por uma falha passageira. **Cadeia pendente** (10/11): a falha que tenta de novo marca o documento por 30 min (trava `assinatura_pdf_selo_pendente_{id}` na loja das vagas, comum à web e ao worker, que expira sozinha); com a marca, o download responde o mesmo 429 antes da trava do documento, da vaga e da cota, sem gerar nem pedir o selo, e a web só passa o pedido ao worker quando a marca é nova; se a fila falhar nesse pedido, a web solta a marca nova e a falha sobe (o clique seguinte tenta de novo). O vínculo à pasta, com a marca, não pede o PDF ao worker: o vínculo segue pendente e aplica no `PdfFinalGuardado`. **Trilha**: o pedido ao validador (até 45 s) roda fora da transação; `pdf_selado` ou `selo_nao_aplicado` (com o código em `dados.motivo`) entram na transação de quem guarda, com a linha do documento travada (`SeloDoPdfFinal::registrar()`); o pedido que encontra o PDF já guardado por outro não registra nada. O `logger->error` do "guardado sem o selo" só sai depois do COMMIT (`SeloDoPdfFinal::avisar()`). `selo_certificado_invalido` (senha errada, e-CNPJ vencido, revogado ou fora das âncoras) pede ação do Samuel; o log do validador separa os dois `503` do selo. Emendas de 10/11: falha definitiva sem tentativas, tentativas pela mensagem com 1, 5 e 15 min, web com uma tentativa e o 429, cadeia pendente por documento, trilha na transação de quem guarda. |
| E8-5 | Abuso do endpoint | Só sela PDF com `/Producer (BlueJus)` e sem nenhuma assinatura; senão `422`. |
| E8-6 | Teste | Certificado de TESTE gerado no próprio teste ("AC Raiz Teste BlueJus" > "AC Teste e-CNPJ" > folha com CNPJ em 2.16.76.1.3.3 e responsável em 2.16.76.1.3.4) e TSA de teste. Ida e volta: o motor `ConferenciaDePdf` confere o PDF selado e o `/v1/conferir` com a raiz de teste confirma a cadeia. O certificado real é pendência do Samuel. |

## 2. Protocolo

- `POST /v1/selar`, mesmo token da etapa 6, corpo = bytes do PDF (`application/pdf`), teto 20 MB.
- `200` com `Content-Type: application/pdf` (o PDF selado); `422 {"v":1,"erro":"nao_selavel"}` (E8-5);
  `503 {"v":1,"erro":"selo_nao_configurado"}` só sem os `secrets` (arquivo ausente ou PKCS#12 vazio);
  `503 {"v":1,"erro":"selo_certificado_invalido"}` com os `secrets` presentes mas inutilizáveis (senha errada, PKCS#12
  ilegível ou grande demais, e-CNPJ vencido, revogado, sem o uso de chave ou fora das âncoras ICP-Brasil);
  `503 {"v":1,"erro":"ancoras_indisponiveis"}` com as âncoras ICP-Brasil ainda sem raiz (verificado depois dos
  `secrets`); `502 {"v":1,"erro":"carimbo_indisponivel"}` quando a ACT configurada não responde. Os demais códigos
  (`400`, `401`, `411`, `413`, `500`, `503 ocupado`, `503 tempo_esgotado`) e a classe de cada um: tabela 2.1 da spec
  da etapa 6.
- PHP: interface `Service/Selo/SeladorDePdf::selar(string $pdf): ?string` (nulo = sem configuração), implementação
  nula por padrão e `HttpSeladorDePdf` quando `ASSINATURA_VALIDADOR_URL` existe.

## 3. Blocos

| Bloco | Escopo | Arquivos | Depende de |
|---|---|---|---|
| 8A Python | `/v1/selar`, PKI e TSA de teste, testes | `validador-pdf/**` (mesmo dono da 6A) | 6A |
| 8B PHP | `SeladorDePdf`, nula e HTTP; chamada no PDF final; tentativa e queda para "sem selo"; eventos `pdf_selado` e `selo_nao_aplicado`; teste de ida e volta pelo motor | `Service/Selo/**`, implementação do PDF final | etapa 3 bloco 1b |
| 8C Infra | `secrets:` no compose de produção, runbook `docs/runbooks/assinatura-selo-ecnpj-em-producao.md` | `docker-compose.prod.yml` | 6C |

## 4. Pendências do Samuel

Comprar o e-CNPJ A1 (D3, depois do piloto); contratar o carimbo do tempo de uma ACT ICP-Brasil; gravar os arquivos em
`/opt/jusprime/secrets/`; ligar o profile e publicar.
