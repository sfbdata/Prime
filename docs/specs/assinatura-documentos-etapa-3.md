# Assinatura de documentos, etapa 3: PDF com selo, manifesto, Validar e vínculo à pasta

Spec geral: `docs/specs/assinatura-documentos.md` (1.6, 1.9, 3.5, 3.6, etapa 3, 5.2, 5.4). Desenho:
`docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (abreviado "dc:NNN"). Inventário completo da
etapa (regiões A1 a A9, porta do fundo, do selo e do QR, o que já existe no código): levantamento de 09/11, copiado em
`docs/specs/assinatura-documentos-etapa-3-inventario.md`. Branch de integração `assinatura-desenho`, PR único no fim.
Migration M2 = `Version20261109290000` (reservada pelo Coordenador).

## 1. Decisões (pelas três regras: fiel ao desenho; função do sistema não sai; extra só se necessário)

| # | Assunto | Decisão |
|---|---|---|
| E3-1 | Como montar o PDF final | A GD compõe a página inteira em 2480 x 3507 px (W=1000, H=1414, S=2.48), porta direta do canvas do `pdf()` (dc:392-427), medindo o texto com `imagettfbbox`. Cada página vira uma JPEG (qualidade 90) e um escritor próprio monta o PDF com uma imagem `DCTDecode` por página, A4 595.28 x 841.89 pt, `/Title (Documento BlueJus {cod})` e `/Producer (BlueJus)`, como o desenho faz (dc:421-425). Substitui "Dompdf + Ghostscript `pdfimage24`" da spec geral: o resultado é o mesmo (PDF em imagem), sem a paginação que o Dompdf não sabe fazer (fundo por página, fonte das duas colunas que diminui até caber). O Ghostscript (`png16m`) só rasteriza as páginas do PDF enviado. |
| E3-2 | Prévia HTML x `pdf()` | O PDF segue o `pdf()` ("Assinado em", cabeçalho em toda página). A prévia da tela não muda. |
| E3-3 | Folha branca | Vale o código do desenho (dc:408-411): só o fundo das páginas do conteúdo fica branco; cabeçalho e rodapé com QR seguem. O manifesto mantém fundo e rodapé. Sem manifesto quando folha branca e sem código (rascunho). |
| E3-4 | Quando o PDF é guardado | Só o PDF final do documento **concluído** é guardado (`arquivo_final_chave`, `hash_final`), uma vez. Gerado por mensagem assíncrona depois do COMMIT da conclusão e, se ainda faltar (dev sem worker, fila atrasada), no primeiro download. Rascunho, aguardando, recusado e cancelado: gerado na hora a cada download, sem guardar (rascunho com "RASCUNHO" e hash provisório, como dc:392). |
| E3-5 | Download do signatário | `GET /assinar/{token}/pdf`, só para o signatário que já assinou e enquanto o token valer. A tela "Assinatura já registrada" segue sem download (desenho). |
| E3-6 | Código BJA | `BJA-XXXX-XXXX-XXXX` gerado no envio por HMAC-SHA256 com segredo próprio `ASSINATURA_SELO_SEGREDO` (nunca o `APP_SECRET` nem o `ASSINATURA_SEGREDO`). Sem o segredo, o envio recusa com mensagem. Sem preenchimento retroativo: em produção nenhum documento foi enviado ainda. |
| E3-7 | Hash na validação | `hash_enviado` (o impresso no PDF). `hash_final` fica só como prova do arquivo guardado. |
| E3-8 | Situação na validação | O rótulo de situação que o sistema já usa, não o valor cru do desenho. Documento na Lixeira também valida (a prova continua). A resposta pública nunca leva CPF nem e-mail (spec 3.6). |
| E3-9 | Conteúdo que não cabe | Duas colunas que não cabem a 9.5: continuam numa segunda página com a mesma diagramação (regra 3: senão o texto assinado se perde). Manifesto com muitos signatários ou eventos: continua em páginas extras com a mesma moldura, com a trilha inteira. |
| E3-10 | Vínculo à pasta | Sempre por id da pasta (o NUP não é único). Vincular exige `canAccessResource('pasta', id, 'edit')`, pasta fora da lápide e documento aguardando ou concluído; fora da Lixeira, nas duas entradas (L11). O vazio da busca é só "Nenhuma pasta encontrada."; o texto "A lista carrega após abrir a aba Expediente..." e o botão manual "Vincular à pasta {q}" saem: a busca do servidor já procura pelo NUP, então o botão manual nunca acharia pasta nenhuma. Sem "desvincular" (o desenho não tem). |
| E3-11 | Vínculo pendente | Ao definir o vínculo de documento aguardando, grava `pasta_id`, `vinculado_por_id`, `vinculado_em` e `vinculo_pendente = true`. Quando o PDF final é guardado, aplica com o usuário `vinculado_por`, reconferindo permissão, cadeado e lápide. Se falhar (perdeu acesso, pasta na lápide, usuário removido), não grava, segue pendente e registra o evento "vínculo não aplicado". |
| E3-12 | Arquivo na pasta | `PastaDocumento` com código DOC da Central, nome `assinado_{nome normalizado até 60}.pdf`, categoria pelo modelo (procurações e substabelecimento: PROCURACAO; contrato: CONTRATO; hipossuficiência: GRATUIDADE_JUSTICA; residência: COMPROVANTE_RESIDENCIA; os demais, upload e modelo do escritório: DEMAIS), marcado como assinado com origem nova `eletronica`; contrato chama `MarcarContratoAssinadoPeloArquivoUseCase` quando couber; aviso de pasta acompanhada e sincronização com o Drive como no upload comum. |
| E3-13 | Selfie no manifesto | A imagem não entra (o desenho não põe). As coordenadas entram no texto do método, como o desenho (anotado no pacote jurídico). |
| E3-14 | URL do QR | Montada pelo roteador com URL absoluta (`DEFAULT_URI`), para valer em dev e em produção. O texto impresso segue o desenho ("bluejus.com.br/assinar/validar"). |
| E3-15 | Fontes | Arial do desenho = NimbusSans (Regular e Bold); Courier = NimbusMonoPS, do pacote `fonts-urw-base35`, declarado no `Dockerfile` para não sumir num rebuild. |
| E3-16 | Travessão | O travessão de dado ausente do desenho vira "não informado". |

## 2. Contrato comum (bloco 0, integrado antes dos blocos 2 e 3)

1. **M2 `Version20261109290000`** em `assinatura_eletronica_documento` (skill `criar-migration`): `codigo VARCHAR(18)
   NULL` com índice único; `arquivo_final_chave VARCHAR(255) NULL`; `hash_final VARCHAR(64) NULL`; `pasta_id INT NULL`
   FK `pasta` ON DELETE SET NULL; `vinculo_pendente BOOLEAN NOT NULL DEFAULT false`; `vinculado_por_id INT NULL` FK do
   usuário ON DELETE SET NULL; `vinculado_em TIMESTAMP(0) NULL`; `pasta_documento_id INT NULL` FK `pasta_documento` ON
   DELETE SET NULL. Índices nas FKs.
2. **`DocumentoParaAssinar`**: getters dos campos acima e `definirCodigo(string)` (uma vez), `guardarArquivoFinal(string
   $nomeDaChave, string $sha256)` (só concluído, uma vez), `definirVinculo(Pasta, User, \DateTimeImmutable)` (recusa se
   já houver vínculo; liga `vinculo_pendente`), `marcarVinculoFeito(PastaDocumento)` (desliga `vinculo_pendente`).
3. **`Service/ChaveDoSelo`** nos moldes da `ChaveDaAssinatura`: parâmetro `assinatura.selo_segredo` (env
   `ASSINATURA_SELO_SEGREDO`), `VERSAO = 1`, mínimo 32; `configurada(): bool`; `codigo(int $documentoId, string
   $hashEnviado, int $tentativa = 0): string` = HMAC-SHA256 de `"v1|{id}|{hash}|{tentativa}"`, 12 primeiros bytes, cada
   um `% 32` no alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, em `BJA-XXXX-XXXX-XXXX`. Vazio no `.env`, valor fixo de
   teste no `.env.test` (`# segredo:permitido`), nome no `.env.prod.example`, conferido pelo `ConferenteDeSegredos`,
   runbook `docs/runbooks/assinatura-selo-segredo-em-producao.md`.
4. **Envio**: `EnviarDocumentoUseCase` gera o código depois do hash; colisão tenta de novo com `tentativa + 1` (até 5),
   o índice único garante. Sem segredo: recusa com mensagem traduzida, sem enviar.
5. **Repositório**: `DocumentoParaAssinarRepository::porCodigo(string): ?DocumentoParaAssinar` (sem filtro de escritório,
   só para a validação pública) e `codigoEmUso(string): bool`; `arquivosEmUso` inclui o PDF final.
6. **Arquivos**: `ChavesDeAssinaturaEletronica::pdfFinal(DocumentoParaAssinar)` e `novoPdfFinal(DocumentoParaAssinar)`;
   `arquivo_final_chave` no espelho do R2 (`FontesDeReferencia`, `DatasDasReferencias` e seus testes).
7. **Interface `Service/PdfFinalDoDocumento`**: `garantir(DocumentoParaAssinar $doc): ChaveDeArquivo` (devolve a chave
   do PDF final guardado, gerando e guardando se faltar; lança se o documento não está concluído). O bloco 0 registra
   uma implementação provisória que lança `\LogicException`, com alias em `config/services.yaml`; o bloco 1 troca o
   alias pela implementação real e apaga a provisória.
8. **Evento `Event/PdfFinalGuardado`** (readonly, `documentoId`), despachado pelo bloco 1 depois do COMMIT que guarda o
   PDF final; o bloco 3 escuta.
9. **`TipoDeEvento`**: `vinculo_definido` ("Definido o vínculo à pasta {nup} ao concluir"), `vinculo_feito`
   ("Documento assinado vinculado à pasta {nup}"), `vinculo_nao_aplicado` ("Vínculo à pasta {nup} não aplicado:
   {motivo}"), com os textos no domínio de tradução dos eventos.
10. **Traduções da tela**: blocos novos `pdf:`, `manifesto:`, `validar:` e `vinculo:` com todos os textos do inventário
    (A1 a A9). Cada bloco só acrescenta chaves dentro do seu.
11. **`ApresentacaoDoDocumento`**: `codigo` em `resumo` e `detalhe` (nulo no rascunho).
12. **`Dockerfile`**: pacote `fonts-urw-base35` (E3-15).

## 3. Blocos e donos dos arquivos

| Bloco | Escopo | Arquivos (só o dono mexe) |
|---|---|---|
| 0. Contrato | seção 2 | migration M2, `DocumentoParaAssinar`, `ChaveDoSelo`, `.env*`, `ConferenteDeSegredos`, runbook do selo, `EnviarDocumentoUseCase`, `DocumentoParaAssinarRepository`, `ChavesDeAssinaturaEletronica`, R2, `PdfFinalDoDocumento` + provisória, `PdfFinalGuardado`, `TipoDeEvento`, traduções (criação dos blocos), `ApresentacaoDoDocumento` (código), `Dockerfile` |
| 1a. Núcleo do PDF | GD: semente, fundo (b1, b2), selo e anéis (b3), QR (b4), cabeçalho, rodapé, diagramação de página inteira e duas colunas, página de PDF enviado, folha branca, escritor do PDF; entrada por objeto de valor próprio (`FolhaParaMontar`), sem banco | `src/AssinaturaEletronica/Pdf/**`, `tests/AssinaturaEletronica/Unit/Pdf/**` |
| 1b. PDF no sistema e manifesto | manifesto (A2); implementação de `PdfFinalDoDocumento`; mensagem assíncrona e handler; despacho depois do COMMIT da conclusão no `AssinarDocumentoUseCase`; rotas `GET /assinatura/documentos/{id}/pdf` (`assinatura_eletronica_pdf`) e `GET /assinar/{token}/pdf` (`assinatura_eletronica_publica_pdf`); botões de baixar na lista, no menu e no detalhe (A5); tela verde com o código e "Baixar PDF com selo" | `Pdf/**`, `Message/`, `MessageHandler/`, ação de PDF nos controllers, alias em `services.yaml`, `AssinarDocumentoUseCase`, `assinatura-publica.js` (tela verde), traduções `pdf:` e `manifesto:` |
| 2. Validar | rotas públicas `GET /assinar/validar` e `GET /assinar/validar/{codigo}` (nomes `assinatura_eletronica_publica_validar*`, prioridade acima do pega-tudo, limitador por IP) e a consulta em JSON; aba Validar (A7); câmera, foto, `BarcodeDetector` e jsQR 1.4.0 servido do sistema; cartão do código no detalhe (A6, só o valor) | `Controller/ValidacaoPublicaController.php`, serviço de projeção pública, `templates/assinatura_eletronica/validar*.html.twig`, `public/js/assinatura-validar.js`, `public/js/vendor/jsqr/`, limitador em `assinatura_eletronica.yaml`, traduções `validar:` |
| 3. Vínculo | modal e busca (A9); `VincularAPastaUseCase`; aplicação do pendente ao ouvir `PdfFinalGuardado`; `PastaDocumento` com código DOC; origem `eletronica`; textos "Na pasta"/"Irá para a pasta" só para quem vê a pasta; campo `vinculo` na apresentação | `UseCase/VincularAPastaUseCase.php`, serviço do vínculo, listener, rotas do vínculo e da busca, `PastaRepository` (método novo), `OrigemDaAssinatura`, `ApresentacaoDoDocumento` (vínculo), `public/js/assinatura-vinculo.js`, traduções `vinculo:` |

`public/js/assinatura.js`, `templates/assinatura_eletronica/index.html.twig` e `assinatura.css` são tocados pelos blocos
1b, 2 e 3: cada um põe a lógica no próprio JS e no `assinatura.js` só a ligação (conflito textual resolvido na
integração). `messenger.yaml` não muda (`#[AsMessage('async')]`).

## 4. Pendências fora do código

- Samuel: criar `ASSINATURA_SELO_SEGREDO` em produção (runbook); trocar `camera=()` por `camera=(self)` no nginx.
- Antes de ligar a chave `assinatura` do Master: ela é global (liga para todo escritório cujo perfil recebeu o módulo).
- Pacote jurídico: coordenadas do signatário no manifesto entregue a todas as partes (E3-13).
