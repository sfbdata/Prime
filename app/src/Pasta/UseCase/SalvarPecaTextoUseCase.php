<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SalvarPecaTextoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
    ) {}

    public function executar(
        Pasta $pasta,
        ?PastaSecao $secao,
        string $conteudoHtml,
        string $titulo,
        string $categoria,
        Tenant $tenant,
    ): PastaDocumento {
        if ($secao !== null && $secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        if ($secao !== null && $secao->getPasta() !== $pasta) {
            throw new \InvalidArgumentException('Seção não pertence à pasta do documento.');
        }

        if (trim($titulo) === '') {
            throw new \InvalidArgumentException('O título da peça não pode ser vazio.');
        }

        $nomeArquivo = $this->storage->salvarConteudo($conteudoHtml, $this->uploadsDir, 'html');

        $tamanhoBytes = strlen($conteudoHtml);

        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo($titulo);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo($nomeArquivo);
        $doc->setNomeOriginal($titulo . '.html');
        $doc->setMimeType('text/html');
        $doc->setTamanhoBytes($tamanhoBytes);
        $doc->setSecao($secao);

        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }
}
