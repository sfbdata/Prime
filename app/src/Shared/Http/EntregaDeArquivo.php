<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoIndisponivelNoMomento;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Entrega um arquivo persistido como resposta HTTP, endereçado por CHAVE (E2.3, D11).
 *
 * ```
 * Controller → ChaveDeArquivo → EntregaDeArquivo → MaterializadorDeArquivo → backend
 * ```
 *
 * O controller decide **quem pode** baixar, **qual** arquivo (a chave, montada pela fábrica do
 * domínio), **com que nome** e **se abre ou baixa**. Não sabe onde o arquivo mora: nem raiz
 * física, nem `public/uploads`, nem resolvedor. Isso é daqui para baixo.
 *
 * Esta classe mora na borda HTTP, fora de `App\Shared\Armazenamento`, porque depende de
 * HttpFoundation — e o núcleo não pode (INV-8).
 *
 * ## O que ela preserva do `servir()` antigo, bit a bit
 *
 * A mesma `BinaryFileResponse` sobre o mesmo caminho, com a mesma chamada de
 * `setContentDisposition()`. Daí vêm de graça, e idênticos: `Content-Type` (adivinhado do
 * arquivo), `Accept-Ranges`, resposta 206 a `Range`, `Last-Modified`, o fallback ASCII do nome e
 * o envio em blocos, sem carregar o arquivo em memória (INV-4). `EntregaDeArquivoTest` compara
 * os cabeçalhos das duas implementações lado a lado.
 *
 * ## INV-9 — o arquivo é emprestado, e a resposta não pode apagá-lo
 *
 * O materializador devolve um {@see ArquivoEmprestado}: o caminho do arquivo de produção. A
 * `BinaryFileResponse` só abre esse arquivo em `sendContent()`, **depois** de o controller
 * retornar, e apagaria se `deleteFileAfterSend` estivesse ligado. Por isso esta classe **nunca**
 * chama `deleteFileAfterSend()` — e o tipo recebido não oferece nada que apague.
 *
 * ## D10 — erro na entrega
 *
 *  - arquivo ausente → **404**, e só isso vira 404 aqui;
 *  - {@see ArquivoIndisponivelNoMomento} (a cópia local foi liberada e o R2 não respondeu ou não
 *    conferiu) → **503**: o arquivo existe, então nunca 404. Sem nova tentativa em linha;
 *  - {@see FalhaDeArmazenamento} (permissão, I/O, backend fora) **passa direto**. Não há catch
 *    amplo: "não consegui ler o storage" não é "o arquivo não existe";
 *  - chave inválida vinda da URL é da rota — é ela que monta a chave e responde 404 à recusa.
 *
 * ## SEC-003 — o que abre inline não executa script na origem do BlueJus
 *
 * Inline é mesma origem: um XML com elementos XHTML, um SVG ou um HTML enviado por alguém do
 * escritório executaria `<script>` com a sessão de quem o abrisse direto (link "abrir em nova
 * aba", Ctrl+clique). Por isso toda entrega INLINE cujo tipo não seja de {@see self::TIPOS_SEGUROS_INLINE}
 * (PDF, imagem raster) nem áudio/vídeo sai com `Content-Security-Policy: sandbox; default-src 'none'`
 * — o navegador trata o documento como de origem opaca, sem script, e não carrega nada de fora.
 *
 * Por que CSP e não forçar `text/plain` ou attachment: o visualizador da tela
 * (`visualizador-documento.js`) lê esses tipos por `fetch()` — a CSP da resposta não se aplica a
 * quem só lê os bytes, e o `Content-Type` continua o mesmo que ele sempre recebeu. PDF fica de
 * fora porque o leitor de PDF do Chrome não abre sob `sandbox` (o visor carrega o PDF num iframe
 * pela própria URL); imagem, áudio e vídeo são carregados por `<img>`/`<audio>`/`<video>`, onde
 * a CSP da resposta nem é consultada — ficam de fora só para não mudar nada do que já funciona.
 *
 * `X-Content-Type-Options: nosniff` vai em toda entrega (inline e download): sem ele o navegador
 * pode "adivinhar" HTML num arquivo servido como outra coisa. O nginx de prod já manda o mesmo
 * cabeçalho; mandá-lo daqui garante o mesmo comportamento atrás de qualquer proxy.
 *
 * O tipo decidido é o MESMO que a `BinaryFileResponse` vai declarar no `prepare()` (o
 * `getMimeType()` do arquivo, adivinhado pelo conteúdo) — não o `mime_type` gravado no banco,
 * que veio do upload e pode mentir.
 *
 * ## O que ainda não está aqui
 *
 * Redirect, URL assinada e streaming de backend remoto são decisão da E4. O materializador entrega
 * sempre um arquivo local emprestado: o do disco ou, na leitura de recuperação, o do cache de leitura
 * hidratado do R2 e conferido por SHA. Range, CSP e `Content-Disposition` são os mesmos nos dois.
 */
final class EntregaDeArquivo
{
    /** A política das entregas inline de tipo ativo (XML, SVG, HTML, texto…): origem opaca, nada carrega. */
    public const CSP_SANDBOX = "sandbox; default-src 'none'";

    /**
     * Tipos que abrem inline SEM a CSP de sandbox: o PDF (o leitor do navegador não abre sob
     * sandbox) e as imagens raster. Áudio e vídeo entram pelo prefixo, em {@see self::exigeSandbox()}.
     * SVG NÃO está aqui: é documento ativo, aceita `<script>`.
     */
    public const TIPOS_SEGUROS_INLINE = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    public function __construct(
        private readonly MaterializadorDeArquivo $materializador,
    ) {
    }

    /**
     * @throws NotFoundHttpException           quando não há arquivo na chave
     * @throws ServiceUnavailableHttpException quando o arquivo existe mas não pôde ser obtido agora
     * @throws FalhaDeArmazenamento            quando o storage não consegue responder — nunca vira 404
     */
    public function resposta(ChaveDeArquivo $chave, string $nomeParaDownload, bool $inline): Response
    {
        try {
            $arquivo = $this->materializador->paraLeitura($chave);
        } catch (ArquivoNaoEncontrado $e) {
            throw new NotFoundHttpException('Arquivo não encontrado.', $e);
        } catch (ArquivoIndisponivelNoMomento $e) {
            throw new ServiceUnavailableHttpException(null, 'Arquivo temporariamente indisponível. Tente de novo em alguns minutos.', $e);
        }

        return $this->deEmprestado($arquivo, $nomeParaDownload, $inline);
    }

    private function deEmprestado(ArquivoEmprestado $arquivo, string $nomeParaDownload, bool $inline): BinaryFileResponse
    {
        $resposta = new BinaryFileResponse($arquivo->caminho());
        $resposta->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            // Nome antigo gravado com "/" ou "\" quebrava o cabeçalho (500): sai saneado (NomeSeguroDeArquivo).
            NomeSeguroDeArquivo::paraDownload($nomeParaDownload),
        );
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');

        // O mesmo palpite que o prepare() vai usar para o Content-Type (vazio → octet-stream).
        if ($inline && self::exigeSandbox($resposta->getFile()->getMimeType() ?: 'application/octet-stream')) {
            $resposta->headers->set('Content-Security-Policy', self::CSP_SANDBOX);
        }

        return $resposta;
    }

    /** Verdadeiro para todo tipo que, aberto inline, poderia executar script na origem (SEC-003). */
    public static function exigeSandbox(string $mimeType): bool
    {
        $tipo = strtolower(trim(explode(';', $mimeType, 2)[0]));

        if (\in_array($tipo, self::TIPOS_SEGUROS_INLINE, true)) {
            return false;
        }

        return !str_starts_with($tipo, 'audio/') && !str_starts_with($tipo, 'video/');
    }
}
