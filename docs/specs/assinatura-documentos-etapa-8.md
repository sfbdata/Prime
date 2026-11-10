# Assinatura de documentos, etapa 8: selo PAdES do BlueJus no PDF final

Spec geral: `docs/specs/assinatura-documentos.md` (3.5, etapa 8, D3). Contêiner e protocolo: `assinatura-documentos-etapa-6.md`.
PDF final: `assinatura-documentos-etapa-3.md` (E3-1, E3-4). Sem migration.

## 1. Decisões

| # | Assunto | Decisão |
|---|---|---|
| E8-1 | Onde sela | No mesmo contêiner `validador_pdf`, endpoint `POST /v1/selar`, com pyHanko (campo invisível `SeloBlueJus`, PAdES, informação de validação embutida, acréscimo incremental sobre o PDF em imagem da etapa 3). |
| E8-2 | A chave | O PKCS#12 e a senha ficam fora do git em `/opt/jusprime/secrets/`, montados só no validador por `secrets:` do compose (`/run/secrets/selo_pkcs12`, `/run/secrets/selo_pkcs12_senha`). O PHP nunca vê a chave. |
| E8-3 | Carimbo do tempo | `ASSINATURA_SELO_TSA_URL` (só no validador). Sem ACT configurada, sela sem carimbo (PAdES B-B); com ACT, PAdES com carimbo. A ACT ICP-Brasil é paga: decisão do Samuel junto com a compra do e-CNPJ (D3). |
| E8-4 | Quando sela | A implementação de `PdfFinalDoDocumento` (etapa 3, bloco 1b) chama `SeladorDePdf::selar()` antes de `guardarArquivoFinal`; `hash_final` passa a ser o do PDF selado; o hash impresso (`hash_enviado`) não muda. Sem configuração: guarda sem selo. Falha: a mensagem assíncrona tenta de novo; esgotadas as tentativas, guarda sem selo e registra `selo_nao_aplicado` na trilha. |
| E8-5 | Abuso do endpoint | Só sela PDF com `/Producer (BlueJus)` e sem nenhuma assinatura; senão `422`. |
| E8-6 | Teste | Certificado de TESTE gerado no próprio teste ("AC Raiz Teste BlueJus" > "AC Teste e-CNPJ" > folha com CNPJ em 2.16.76.1.3.3 e responsável em 2.16.76.1.3.4) e TSA de teste. Ida e volta: o motor `ConferenciaDePdf` confere o PDF selado e o `/v1/conferir` com a raiz de teste confirma a cadeia. O certificado real é pendência do Samuel. |

## 2. Protocolo

- `POST /v1/selar`, mesmo token da etapa 6, corpo = bytes do PDF (`application/pdf`), teto 20 MB.
- `200` com `Content-Type: application/pdf` (o PDF selado); `422 {"v":1,"erro":"nao_selavel"}` (E8-5);
  `503 {"v":1,"erro":"selo_nao_configurado"}` sem os `secrets`; `502 {"v":1,"erro":"carimbo_indisponivel"}` quando a
  ACT configurada não responde.
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
