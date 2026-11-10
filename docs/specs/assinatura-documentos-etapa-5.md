# Assinatura de documentos, etapa 5: gov.br, ICP-Brasil e documento já assinado

Spec geral: `docs/specs/assinatura-documentos.md` (1.3, 1.4, 1.7, 3.1 a 3.4, etapa 5, 5.2). Motor de integridade:
`assinatura-documentos-etapa-5-motor.md`. Cadeia no servidor: `assinatura-documentos-etapa-6.md` (entra junto: sem
ela gov.br e ICP seriam falsificáveis). Inventário do desenho e do código, com dc:linha: levantamento de 09/11 copiado em
`assinatura-documentos-etapa-5-inventario.md` (seções a, b, e, f). Migration M4 = `Version20261109300000`. Branch
`assinatura-desenho`, PR único.

Nome: `pres` no desenho é o **PDF que já chega com assinaturas digitais** ("documento já assinado"); não existe nível
presencial.

## 1. Decisões (pelas três regras)

| # | Assunto | Decisão |
|---|---|---|
| E5-1 | PDF para assinar | Congelado como versão `para_assinar` na primeira geração (sob trava da linha) e o prefixo do PDF devolvido é conferido contra ele, também no primeiro signatário gov.br/ICP de documento sem assinatura prévia (o desenho só confere quando há `pres`, dc:385, e regera a cada clique). A tela não muda; o erro "Este arquivo não preserva..." passa a valer também aqui. |
| E5-2 | gov.br e ICP no envio | Só liberados com o validador da etapa 6 configurado (E6-4). |
| E5-3 | CPF do certificado | Confere com o CPF do signatário; certificado sem CPF recusa com texto curto novo. O CPF do responsável do e-CNPJ (2.16.76.1.3.4) não é lido nesta etapa (mexeria no motor pronto; regra 3). |
| E5-4 | Resultado da cadeia | Decide aceitar ou recusar e a classe da lei no manifesto. Nenhum texto novo no quadro do desenho (regra 3). |
| E5-5 | Duplicar | Copia só o original e a análise dele, nunca a versão já assinada por outros (dc:368 levaria `pres.atual`). |
| E5-6 | Quadro antes de salvar | Endpoint que confere sem gravar (o arquivo só sobe ao salvar o rascunho); o quadro mostra o resultado do motor do servidor. |
| E5-7 | Documento que já chega com assinatura alterada | Segue o desenho: deixa enviar, o quadro vermelho avisa, e o PDF devolvido com qualquer `alterada` é recusado (dc:385). |
| E5-8 | Tetos | PDF devolvido até 20 MB (teto do motor); original enviado no rascunho até 10 MB (como hoje). Memória medida no 5C (10/11/2026): teste temporário com `memory_reset_peak_usage()` antes e `memory_get_peak_usage()` depois, base e devolvido de 19 a 20 MB, validador por dublê; acréscimo sobre o processo de +19 MiB só no motor, +39 MiB na pré-conferência (com a leitura da base) e +60 MiB na assinatura final inteira (`AssinarComCertificadoUseCase`), abaixo dos 256M de `EntregaDoPdfDaAssinatura::limitar()`. Não medido: a cópia que o cliente HTTP do validador real possa fazer do corpo. |
| E5-9 | E-mail | Continua obrigatório para todos no envio (convite e avisos por e-mail, convenção da etapa 2). |
| E5-10 | Conferência fora do ar no passo 1 | O desenho analisa o PDF no navegador e nunca falha (dc:373); aqui quem confere é o servidor (E5-6). Com a conferência fora do ar (429 do limitador, 5xx ou rede), o PDF não avança ao passo 3 e o passo 1 mostra "Não foi possível conferir o arquivo agora. Tente de novo em instantes." (`ja_assinado.conferir_indisponivel`; `assinatura-ja-assinado.js`). Desvio aceito na conferência de 10/11/2026: seguir sem o quadro esconderia uma assinatura que o servidor acharia ao salvar. |
| E5-11 | Original não encontrado | "Original não encontrado neste navegador." (dc:383) vira "Original não encontrado.": o original mora no servidor, não no navegador. Vale para "Baixar original com as assinaturas" e para a cópia com carimbo (o carimbo olha a assinatura antes de ler o arquivo). Adaptação aceita na conferência de 10/11/2026. |
| E5-12 | Original sem página do PDF no conteúdo | Como o `pres` do `subir` do desenho (dc:373-374), que não depende dos blocos: o PDF que chega assinado vira o original e ganha o parágrafo mesmo quando o conteúdo não tem página dele (a tela manda o PDF enquanto houver `w.pres`; o PDF em si não fica no documento). Sem PDF novo, o rascunho que fica sem página perde o original, como o `retomar` do desenho, que não leva o `pres`. Decisão do Coordenador na conferência de 10/11/2026. |

## 2. Contrato comum (bloco 5.0, depois do bloco 0 da etapa 3 integrado)

1. **M4 `Version20261109300000`**: tabela `assinatura_eletronica_versao` (id, `documento_id` FK CASCADE,
   `signatario_id` NULL FK SET NULL, `ordem` smallint, `origem` varchar(20) `original`|`para_assinar`|`devolvida`,
   `arquivo_chave`, `nome_original`, `sha256` char(64), `tamanho` int, `analise` JSON, `criado_em`; colunas da etapa 6:
   `cadeia` JSON NULL, `cadeia_situacao` varchar(20) NULL, `cadeia_conferida_em` timestamp NULL; único
   (`documento_id`, `ordem`)).
2. Entidade `VersaoDoPdf` + repositório (`ultima`, `original`; o `paraAssinar` saiu no 5C, porque a releitura sob a trava
   é pela `ultima`), enum `OrigemDaVersao`.
3. `Signatario::registrarAssinaturaComCertificado(...)` e `DocumentoParaAssinar::assinarComCertificado(...)` (sem
   rubrica; conclusão como na Simples).
4. `ChavesDeAssinaturaEletronica::versao` e `novaVersao`; `arquivosEmUso` com as versões; R2 (`FontesDeReferencia`,
   `DatasDasReferencias` e testes).
5. `TipoDeEvento`: `pdf_para_assinar_gerado`, `pdf_assinado_recusado`, com textos.
6. `SerializacaoDaConferencia`: o `ResultadoDaConferencia` do motor em JSON `{"v":1,...}`, fora do motor.
7. Interface `Service/Cadeia/ValidadorDeCadeia` (`configurado(): bool`, `conferir(string $pdf, string $ancora):
   ResultadoDaCadeia`) + `ResultadoDaCadeia` (objeto do protocolo da etapa 6) + implementação "não configurado", com
   alias em `services.yaml`.
8. `ConferenciaDoEnvio` recusa gov.br e ICP sem `ValidadorDeCadeia::configurado()` (E5-2).
9. Blocos de tradução `ja_assinado:` (tela) e `certificado:` (pública), com os textos do inventário (seção a).

## 3. Blocos

| Bloco | Escopo | Arquivos (dono exclusivo) | Depende de |
|---|---|---|---|
| 5A Já assinado (interno) | `POST /assinatura/pdf/conferir` (E5-6); salvar rascunho grava a versão `original` com a análise e insere o parágrafo (dc:374); Duplicar (E5-5); quadro do passo 3 e do detalhe (dc:381); "Baixar original com as assinaturas"; rótulo "Baixar manifesto com selo"; link do ITI | `SalvarRascunhoUseCase`, `ArquivosDoRascunho`, `DuplicarDocumentoUseCase`, `ConferenciaDoPdfController`, ação "original" em `ArquivosDaAssinaturaController`, `ApresentacaoDoDocumento` (`jaAssinado`), `public/js/assinatura-ja-assinado.js` + ligação em `assinatura.js`, traduções `ja_assinado:` | 5.0 |
| 5B Carimbo | `CarimboVisivel` (porta de `carimbar`, bj-assina-pdf.js:23) + `LocalizadorDaUltimaPagina`; conferência final pelo motor; `GET /assinatura/documentos/{id}/carimbo`; nunca grava | `Service/CarimboVisivel*`, `Controller/CarimboDaAssinaturaController.php` | 5.0 (só a rota) |
| 5C Tela pública | `GET /assinar/{token}/para-assinar` (E5-1); `POST /assinar/{token}/pdf-assinado` (confere sem gravar, textos de dc:466); `AssinarComCertificadoUseCase` (reconfere, regra E6-6, grava `devolvida`, trilha, conclusão); despacho por nível no POST de assinar; `PaginaDoSignatario`; bloco `aCert` em `assinatura-publica.js`; limitador de upload; método e classe no detalhe e no manifesto | `AssinaturaPublicaController` (rotas novas), `AssinarComCertificadoUseCase`, `ObterPdfParaAssinarUseCase`, `PaginaDoSignatario`, `assinatura-publica.js`, `publica.html.twig`, `assinatura_eletronica.yaml` (limitador), traduções `certificado:` | 5.0 e etapa 3 (blocos 0, 1a, 1b) |

## 4. Pendências do Samuel

Assinar um PDF de teste no assinador.iti.br com conta gov.br e outro com certificado ICP-Brasil, para o aceite (o
assinador do ITI pode não preservar os bytes; se não preservar, a conferência passa a ser pelo código BJA e pelo número
de páginas, como a spec geral prevê em 3.2).
