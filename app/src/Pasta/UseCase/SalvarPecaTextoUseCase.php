<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaDocumento;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final class SalvarPecaTextoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
    ) {}

    public function executar(
        Pasta $pasta,
        string $conteudoHtml,
        string $titulo,
        string $categoria,
    ): PastaDocumento {
        if (trim($titulo) === '') {
            throw new \InvalidArgumentException('O título da peça não pode ser vazio.');
        }

        $nomeArquivo = $this->storage->salvarConteudo($conteudoHtml, $this->uploadsDir, 'html');

        $tamanhoBytes = strlen($conteudoHtml);

        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTitulo($titulo);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo($nomeArquivo);
        $doc->setNomeOriginal($titulo . '.html');
        $doc->setMimeType('text/html');
        $doc->setTamanhoBytes($tamanhoBytes);

        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }
}
