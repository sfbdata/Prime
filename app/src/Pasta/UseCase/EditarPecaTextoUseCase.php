<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Pasta\PastaDocumento;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final class EditarPecaTextoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
    ) {}

    public function executar(PastaDocumento $doc, string $conteudoHtml, ?string $titulo = null): void
    {
        $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());
        file_put_contents($caminho, $conteudoHtml);
        $doc->setTamanhoBytes(strlen($conteudoHtml));

        if ($titulo !== null && trim($titulo) !== '') {
            $doc->setTitulo(trim($titulo));
            $doc->setNomeOriginal(trim($titulo) . '.html');
        }

        $this->em->flush();
    }
}
