<?php

namespace App\Profile\UseCase;

use App\Entity\Auth\UserProfile;
use App\Shared\Service\ArquivoStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AtualizarFotoPerfilUseCase
{
    private const MIME_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];
    private const TAMANHO_MAX = 3 * 1024 * 1024; // 3 MB

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageService $storage,
        private readonly string $fotosPerfilDir,
    ) {}

    public function executar(UserProfile $perfil, UploadedFile $arquivo): void
    {
        if (!in_array($arquivo->getMimeType(), self::MIME_PERMITIDOS, true)) {
            throw new \InvalidArgumentException('Formato de imagem não permitido. Use JPG, PNG ou WebP.');
        }

        if ($arquivo->getSize() > self::TAMANHO_MAX) {
            throw new \InvalidArgumentException('A imagem deve ter no máximo 3 MB.');
        }

        // Remove foto anterior se existir
        if ($perfil->getFotoUrl() !== null) {
            $caminhoAntigo = $this->storage->caminho($this->fotosPerfilDir, $perfil->getFotoUrl());
            $this->storage->excluir($caminhoAntigo);
        }

        $nomeArquivo = $this->storage->salvar($arquivo, $this->fotosPerfilDir);
        $perfil->setFotoUrl($nomeArquivo);
        $this->em->flush();
    }
}
