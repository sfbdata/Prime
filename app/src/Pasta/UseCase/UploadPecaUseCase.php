<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\ResultadoCompressao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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

        $upload     = FonteDeUploadHttp::de($file);
        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDePasta::novoDocumento($doc, $upload->extensao));
        $nomeUnico  = $armazenado->chave->nome;

        // D30: sem compressão, o tamanho gravado é o que o storage mediu ao gravar.
        $compressao = ResultadoCompressao::naoComprimido($armazenado->tamanhoBytes);
        if ($reduzirTamanho) {
            $compressao = $this->compressao->comprimir($armazenado->chave, $mimeType);
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
        $doc->setSecao($secao);

        $this->em->persist($doc);
        $this->em->flush();

        return new ResultadoUploadPeca($doc, $compressao);
    }
}
