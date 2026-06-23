# Spec — Compressão opcional de arquivos no upload (imagens + PDF)

## Motivo
PDFs digitalizados e imagens são os maiores consumidores de espaço no acervo. A
feature dá ao usuário, no momento do upload, a escolha entre **manter a qualidade**
(padrão) ou **reduzir o tamanho** economizando espaço. Toca dois domínios (Pasta +
Cliente), executa binário externo (Ghostscript) e mexe em fluxo legado
(`ClienteDocumento` sem tenant) → risco MÉDIO.

## Comportamento
- **Opt-in:** padrão é manter qualidade. Compressão só ocorre se o usuário marcar
  o toggle "Reduzir tamanho" no formulário de upload.
- **Na ingestão:** o arquivo é comprimido logo após `storage->salvar()` (já em
  disco), **in-place**. Só substitui o original **se o resultado for menor**.
  O original do upload (temp) é descartado naturalmente.
- **Best-effort:** se a compressão falhar (gs ausente, erro de GD, processo != 0)
  ou não reduzir, mantém o arquivo original e segue. **Nunca** derruba o upload.
- **Sem migration:** o tamanho final vai no `tamanhoBytes` já existente; a economia
  ("de X para Y") aparece só no feedback imediato (JSON no Pasta, flash no Cliente).

## Tipos suportados
| MIME | Como | Observação |
|---|---|---|
| `image/jpeg` | GD `imagejpeg(..., 75)` | lossy, maior ganho |
| `image/png` | GD `imagepng(..., 9)` | lossless, ganho modesto |
| `application/pdf` | Ghostscript `-dPDFSETTINGS=/ebook` (~150 dpi) | legível p/ juízo |
| demais (docx, vídeo, zip, …) | no-op | toggle sem efeito, seguro |

## PDF assinado
Recomprimir invalida a assinatura. Decisão: **aviso fixo na UI + honra a escolha**.
- A UI mostra alerta fixo ao lado do toggle.
- O servidor detecta a assinatura (`pdfEstaAssinado`: `/ByteRange` + `/Sig` /
  `/Type/Sig` / `/Adobe.PPKLite`) apenas para **informar** no feedback; não bloqueia.

## Componentes (em `src/Shared/Service/`)
- `CompressorArquivoInterface` — `comprimir(string $caminho, string $mimeType): ResultadoCompressao`
  e `pdfEstaAssinado(string $caminho): bool`.
- `CompressorArquivo` — implementação (GD + Ghostscript via `symfony/process`).
- `ResultadoCompressao` — VO readonly: `tamanhoOriginal`, `tamanhoFinal`,
  `comprimido`, `eraAssinado`.

## Pontos de integração
- **Pasta:** `UploadPecaUseCase::executar()` ganha `bool $reduzirTamanho = false`;
  injeta `CompressorArquivoInterface`. `PeticionarController::upload` lê
  `reduzir_tamanho` e devolve a economia no JSON. Template `pasta/peticionar.html.twig`
  ganha toggle + aviso; JS anexa o campo ao `FormData` e mostra toast.
- **Cliente:** `ClienteController::uploadDocumento` lê `reduzir_tamanho`, comprime
  cada arquivo do lote, grava `tamanhoBytes` final e dá flash de resumo. Template
  `cliente/show.html.twig` ganha o mesmo toggle + aviso. (Sem extrair UseCase agora.)

## Infra
- `Dockerfile`: adicionar `ghostscript` ao `apt-get install` (rebuild da imagem dev).
- `services.yaml`: parâmetro `ghostscript_bin` com override por env
  (`%env(default:default_ghostscript_bin:GHOSTSCRIPT_BIN)%`, padrão `/usr/bin/gs`)
  + bind `string $ghostscriptBin`.

## Testes
- `tests/Shared/Unit/CompressorArquivoTest.php` (novo): JPEG real reduz; PDF (skip se
  sem `gs`); `pdfEstaAssinado` true/false; mimeType não suportado = no-op.
- `tests/Pasta/Unit/UploadPecaUseCaseTest.php` (ajustar): mock de
  `CompressorArquivoInterface`; `reduzir=true` comprime e grava tamanho final;
  `reduzir=false` não chama o compressor.

## Não-objetivos
- Demais pontos de upload (ServiceDesk, Kanban, Ponto, Tarefa, Perfil).
- Compressão assíncrona (Messenger).
- Coluna persistida de tamanho original / flag de comprimido.
- Correção do isolamento de tenant em `ClienteDocumento` (legado) — apenas não regredir.
