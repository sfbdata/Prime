<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
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
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly CompressaoDeArquivoArmazenado $compressao,
        private readonly LoggerInterface $logger,
    ) {}

    public function executar(
        Pasta $pasta,
        ?PastaSecao $secao,
        UploadedFile $file,
        string $categoria,
        ?string $descricao,
        ?string $numero,
        Tenant $tenant,
        bool $reduzirTamanho = false,
    ): ResultadoUploadPeca {
        if ($secao !== null && $secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        if ($secao !== null && $secao->getPasta() !== $pasta) {
            throw new \InvalidArgumentException('Seção não pertence à pasta do documento.');
        }

        $mimeType = $file->getMimeType() ?? '';

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
        $doc = new PastaDocumento();
        $doc->setTenant($tenant);

        $upload = FonteDeUploadHttp::de($file);

        // Antes de mover: depois do `gravarEm()` o caminho do upload não existe mais.
        $sha256 = Sha256DeArquivo::deArquivoLocal($file->getPathname());

        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDePasta::novoDocumento($doc, $upload->extensao));
        $nomeUnico  = $armazenado->chave->nome;

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
        $doc->setSecao($secao);

        $this->em->persist($doc);
        $this->em->flush();

        return new ResultadoUploadPeca($doc, $compressao);
    }
}
