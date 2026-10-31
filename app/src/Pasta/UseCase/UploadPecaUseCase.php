<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Armazenamento\GeradorDeCodigoDeDocumento;
use App\Pasta\Entity\ArquivoArmazenado;
use App\Pasta\Service\AvisoDePastaAcompanhada;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Pasta\Service\DetectorDeAssinaturaDigital;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Sha256DeArquivo;
use App\Shared\Http\FonteDeUploadHttp;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\ResultadoCompressao;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Upload de um documento na pasta (aba Documentos e tela de peticionar).
 *
 * ## O hash é do arquivo que FICOU no storage
 *
 * `sha256` é calculado em streaming sobre o upload ANTES de ele ir para o armazenamento (o
 * caminho temporário do PHP, que a ponte HTTP ainda não moveu). Sem compressão, esses são os
 * bytes que o storage guarda — a gravação é byte a byte (INV-7) — e o hash vale. Com
 * `reduzir_tamanho`, a compressão regrava a MESMA chave com outro binário; aí o hash do upload
 * não descreve mais o que está lá e é recalculado pela chave (`Sha256DeArquivo::deChave`), uma
 * leitura a mais só quando o conteúdo mudou. Se essa leitura falhar, o documento nasce com
 * `sha256 = null` (o `app:documentos:calcular-hash` preenche depois) em vez de derrubar o upload. É `comprimido` quem decide, e ele já cobre o caso
 * "publicou a versão comprimida mas falhou ao medir" (`CompressaoDeArquivoArmazenado`).
 *
 * ## Páginas e autor (D1)
 *
 * `paginas` é contado no MESMO ponto do hash do upload — no caminho temporário, antes de mover —
 * e só para PDF; a compressão não muda a contagem (a validação dela exige as mesmas páginas).
 * Falha na contagem → NULL, o upload segue (`app:documentos:calcular-hash --paginas` preenche).
 * `enviadoPor` é quem está logado; NULL nos caminhos sem usuário (importação, Drive).
 *
 * ## Selo "Assinado" (assinatura digital)
 *
 * `.p7s`/`.p7m` ou PDF com dicionário de assinatura (`DetectorDeAssinaturaDigital`) nascem com o selo,
 * origem `digital`. A leitura é no caminho temporário, antes de mover, como o hash e as páginas. Se a
 * compressão regravou o PDF, a assinatura não sobreviveu (o Ghostscript reescreve o arquivo): o
 * documento nasce SEM o selo, porque o que ficou no storage não está mais assinado.
 *
 * ## Arquivo novo dentro de transação maior
 *
 * `aoGravar` (opcional) recebe a chave assim que o arquivo é gravado, antes de qualquer outro passo que possa
 * falhar. É assim que o despacho da meta entrega a chave à `TransacaoComArquivoNovo`, que só apaga o arquivo quando
 * o COMMIT comprovadamente não aconteceu.
 *
 * ## Acompanhar alterações
 *
 * Com `avisarQuemAcompanha` (só o upload da pasta, `pasta_peticionar_upload`), quem acompanha a pasta recebe "Novo
 * documento na pasta N" depois do flush ({@see AvisoDePastaAcompanhada::aoIncluirDocumento()}, que nunca lança).
 * Sem ele, nada: o anexo do despacho roda dentro da transação da meta, que já tem aviso próprio.
 *
 * ## Tipo pelo conteúdo (DOC-115)
 *
 * O tipo é o que o finfo lê no conteúdo (`getMimeType()`), nunca a extensão do nome: executável ou
 * HTML renomeado para `.odt` chega como `application/x-dosexec`/`text/html` e é recusado. A única
 * vez em que a extensão entra na conta é para RESTRINGIR: `application/zip` com extensão de pacote
 * de escritório (ver `PACOTES_POR_EXTENSAO`) só passa se o pacote declarar o tipo por dentro.
 */
final class UploadPecaUseCase
{
    private const MIME_LIMITS = [
        'image/png'                                                                          => 3 * 1024 * 1024,
        'image/jpeg'                                                                         => 3 * 1024 * 1024,
        'application/pdf'                                                                    => 10 * 1024 * 1024,
        'application/vnd.google-earth.kml+xml'                                               => 5 * 1024 * 1024,
        'application/xml'                                                                    => 5 * 1024 * 1024,
        'text/xml'                                                                           => 5 * 1024 * 1024,
        'application/vnd.ms-excel'                                                           => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'                  => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'            => 10 * 1024 * 1024,
        'application/msword'                                                                 => 10 * 1024 * 1024,
        'audio/mpeg'                                                                         => 10 * 1024 * 1024,
        'audio/ogg'                                                                          => 10 * 1024 * 1024,
        'audio/mp4'                                                                          => 10 * 1024 * 1024,
        'video/mp4'                                                                          => 50 * 1024 * 1024,
        'video/quicktime'                                                                    => 50 * 1024 * 1024,
        'text/plain'                                                                         => 5 * 1024 * 1024,
        'application/zip'                                                                    => 50 * 1024 * 1024,
        'application/pkcs7-signature'                                                        => 5 * 1024 * 1024,
        'audio/opus'                                                                         => 50 * 1024 * 1024,
        // DOC-115: os formatos que o desenho trata e o visor abre. Teto pelo padrão do tipo:
        // imagem 3 MB, documento/planilha/apresentação 10 MB, texto 5 MB, e-mail 10 MB (o teto do visor).
        'image/gif'                                                                          => 3 * 1024 * 1024,
        'image/webp'                                                                         => 3 * 1024 * 1024,
        'image/tiff'                                                                         => 3 * 1024 * 1024,
        'image/heic'                                                                         => 3 * 1024 * 1024,
        'image/heif'                                                                         => 3 * 1024 * 1024,
        'application/vnd.oasis.opendocument.text'                                            => 10 * 1024 * 1024,
        'application/vnd.oasis.opendocument.spreadsheet'                                     => 10 * 1024 * 1024,
        'application/vnd.oasis.opendocument.presentation'                                    => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'          => 10 * 1024 * 1024,
        'text/rtf'                                                                           => 10 * 1024 * 1024,
        'application/rtf'                                                                    => 10 * 1024 * 1024,
        'text/csv'                                                                           => 5 * 1024 * 1024,
        'message/rfc822'                                                                     => 10 * 1024 * 1024,
    ];

    /**
     * DOC-115: pacotes ZIP que o finfo às vezes devolve como `application/zip` genérico (ordem das
     * entradas, gerador do arquivo). Extensão => o MIME que o pacote tem de declarar POR DENTRO.
     *
     * `application/zip` NÃO vale como ODT/ODS/ODP/PPTX por si só: com uma destas extensões o
     * pacote é aberto (ZipArchive) e só passa se declarar o tipo coerente — no ODF, a entrada
     * `mimetype`; no OOXML, a parte principal em `[Content_Types].xml`. Então o documento é gravado
     * com o MIME do pacote. Zip qualquer renomeado para `.odt` é recusado, em vez de entrar como zip
     * com cara de documento. Um `.zip` de verdade segue aceito como zip.
     */
    private const PACOTES_POR_EXTENSAO = [
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** A parte principal que o `[Content_Types].xml` de um OOXML declara, por MIME do pacote. */
    private const PARTE_PRINCIPAL_OOXML = [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
    ];

    /**
     * Teto da leitura das entradas de controle (`mimetype`, `[Content_Types].xml`): nada de inflar
     * zip bomb. O `[Content_Types].xml` cresce ~150 bytes por slide; 1 MB sobra.
     */
    private const LIMITE_ENTRADA_DE_CONTROLE = 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly CompressaoDeArquivoArmazenado $compressao,
        private readonly LoggerInterface $logger,
        private readonly ContadorDePaginasDePdf $contadorDePaginas,
        private readonly DetectorDeAssinaturaDigital $detectorDeAssinatura,
        private readonly ?AvisoDePastaAcompanhada $avisoDeAcompanhamento = null,
        private readonly ?GeradorDeCodigoDeDocumento $codigos = null,
    ) {}

    /** @param (\Closure(\App\Shared\Armazenamento\ChaveDeArquivo): void)|null $aoGravar recebe a chave logo depois da gravação */
    public function executar(
        Pasta $pasta,
        ?PastaSecao $secao,
        UploadedFile $file,
        string $categoria,
        ?string $descricao,
        ?string $numero,
        Tenant $tenant,
        bool $reduzirTamanho = false,
        ?User $enviadoPor = null,
        ?\Closure $aoGravar = null,
        bool $avisarQuemAcompanha = false,
    ): ResultadoUploadPeca {
        if ($secao !== null && $secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        if ($secao !== null && $secao->getPasta() !== $pasta) {
            throw new \InvalidArgumentException('Seção não pertence à pasta do documento.');
        }

        $mimeType = $file->getMimeType() ?? '';

        $extensao = strtolower($file->getClientOriginalExtension());
        if ($mimeType === 'application/zip' && isset(self::PACOTES_POR_EXTENSAO[$extensao])) {
            $mimeType = self::mimeDoPacote($file->getPathname(), self::PACOTES_POR_EXTENSAO[$extensao])
                ?? throw new \InvalidArgumentException(sprintf(
                    '"%s": o conteúdo não é um arquivo .%s válido.',
                    $file->getClientOriginalName(),
                    $extensao,
                ));
        }

        if (!array_key_exists($mimeType, self::MIME_LIMITS)) {
            throw new \InvalidArgumentException(sprintf('Tipo de arquivo não permitido: %s.', $mimeType));
        }

        $tamanho = $file->getSize();
        $limite  = self::MIME_LIMITS[$mimeType];

        if ($tamanho > $limite) {
            throw new \InvalidArgumentException(sprintf(
                '"%s": excede o limite de %d MB.',
                $file->getClientOriginalName(),
                intdiv($limite, 1024 * 1024),
            ));
        }

        // O escopo sai do próprio documento (R1): o mesmo escritório que a leitura vai usar. O
        // documento só é persistido depois da gravação.
        $doc = (new PastaDocumento())->setTenant($tenant);
        $codigo = $this->codigos?->gerar((int) $tenant->getId()); // antes de gravar: se falhar, não sobra arquivo órfão

        $upload = FonteDeUploadHttp::de($file);

        // Antes de mover: depois do `gravarEm()` o caminho do upload não existe mais.
        $sha256  = Sha256DeArquivo::deArquivoLocal($file->getPathname());
        $paginas = $mimeType === 'application/pdf' ? $this->contadorDePaginas->contar($file->getPathname()) : null;
        $assinado = $this->detectorDeAssinatura->arquivoAssinado($file->getPathname(), $file->getClientOriginalName(), $mimeType);

        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDePasta::novoDocumento($doc, $upload->extensao));
        $nomeUnico  = $armazenado->chave->nome;
        // A chave vai já agora para quem roda o upload numa transação maior: se algo falhar daqui em diante, ele decide.
        $aoGravar?->__invoke($armazenado->chave);

        // D30: sem compressão, o tamanho gravado é o que o storage mediu ao gravar.
        $compressao = ResultadoCompressao::naoComprimido($armazenado->tamanhoBytes);
        if ($reduzirTamanho) {
            $compressao = $this->compressao->comprimir($armazenado->chave, $mimeType);
        }

        if ($compressao->comprimido) {
            // A chave foi regravada com outro binário: o hash é do que está lá, não do upload. Se
            // essa leitura falhar, o hash fica null (opcional) e o upload segue.
            $sha256 = Sha256DeArquivo::deChaveOuNulo($this->armazenamento, $armazenado->chave, $this->logger);
        }

        $doc->setPasta($pasta);
        $doc->setTitulo($file->getClientOriginalName());
        $doc->setCategoria($categoria);
        $doc->setDescricao(($descricao !== null && $descricao !== '') ? $descricao : null);
        $doc->setNumero(($numero !== null && $numero !== '') ? $numero : null);
        $doc->setCaminhoArquivo($nomeUnico);
        $doc->setNomeOriginal($file->getClientOriginalName());
        $doc->setMimeType($mimeType);
        $doc->setTamanhoBytes($compressao->tamanhoFinal);
        $doc->setSha256($sha256);
        $doc->setSecao($secao)->setPaginas($paginas);
        $doc->setEnviadoPor($enviadoPor);
        $doc->marcarAssinaturaDoUpload($assinado, $compressao->comprimido, $enviadoPor);

        $this->vincularAoArquivo($doc, $tenant, $codigo, $nomeUnico, $compressao->tamanhoFinal, $sha256);
        $this->gravarEAvisar($avisarQuemAcompanha, $doc, $pasta, $tenant, $enviadoPor);

        return new ResultadoUploadPeca($doc, $compressao);
    }

    /** Cada upload ganha o seu `arquivo_armazenado` (1:1 na C1); o código DOC é o nome que o escritório vê. */
    private function vincularAoArquivo(PastaDocumento $doc, Tenant $tenant, ?string $codigo, string $chave, int $tamanho, ?string $sha256): void
    {
        if ($codigo === null) {
            return;
        }
        $arquivo = new ArquivoArmazenado($tenant, $codigo, $chave, $tamanho, $sha256);
        $this->em->persist($arquivo);
        $doc->setArquivo($arquivo);
    }

    /**
     * Grava o documento e, depois do flush, avisa quem acompanha. Só o upload da pasta avisa (ver "Acompanhar
     * alterações" no PHPDoc da classe); o aviso nunca lança.
     */
    private function gravarEAvisar(bool $avisar, PastaDocumento $doc, Pasta $pasta, Tenant $tenant, ?User $autor): void
    {
        $this->em->persist($doc);
        $this->em->flush();
        if ($avisar) {
            $this->avisoDeAcompanhamento?->aoIncluirDocumento($doc, $pasta, $tenant, $autor);
        }
    }

    /**
     * O MIME do pacote quando o ZIP declara, por dentro, exatamente `$esperado`; null se não
     * declara (zip genérico, outro tipo de pacote, arquivo ilegível).
     */
    private static function mimeDoPacote(string $caminho, string $esperado): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($caminho, \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $parte = self::PARTE_PRINCIPAL_OOXML[$esperado] ?? null;

            if ($parte === null) {
                // ODF: a entrada `mimetype` traz o tipo do documento, sem mais nada.
                $declarado = $zip->getFromName('mimetype', self::LIMITE_ENTRADA_DE_CONTROLE);

                return \is_string($declarado) && trim($declarado) === $esperado ? $esperado : null;
            }

            // OOXML: a parte principal tem de estar declarada com o tipo do pacote.
            $tipos = $zip->getFromName('[Content_Types].xml', self::LIMITE_ENTRADA_DE_CONTROLE);
            if (!\is_string($tipos)) {
                return null;
            }

            return preg_match('/ContentType\s*=\s*["\']' . preg_quote($parte, '/') . '["\']/i', $tipos) === 1 ? $esperado : null;
        } finally {
            $zip->close();
        }
    }
}
