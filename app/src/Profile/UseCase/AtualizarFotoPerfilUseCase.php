<?php
declare(strict_types=1);
namespace App\Profile\UseCase;

use App\Profile\DTO\AtualizarFotoInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Shared\Service\ArquivoStorageInterface;

final class AtualizarFotoPerfilUseCase
{
    private const MIME_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];
    private const TAMANHO_MAX = 3 * 1024 * 1024;

    public function __construct(
        private readonly UserProfileRepository $repository,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $fotosPerfilDir,
    ) {
    }

    public function executar(UserProfile $perfil, AtualizarFotoInput $input): void
    {
        if (!in_array($input->arquivo->getMimeType(), self::MIME_PERMITIDOS, true)) {
            throw new \InvalidArgumentException('Formato de imagem não permitido. Use JPG, PNG ou WebP.');
        }

        if ($input->arquivo->getSize() > self::TAMANHO_MAX) {
            throw new \InvalidArgumentException('A imagem deve ter no máximo 3 MB.');
        }

        $novoNome = $this->storage->salvar($input->arquivo, $this->fotosPerfilDir);

        $fotoAnterior = $perfil->getFotoUrl();
        $perfil->setFotoUrl($novoNome);
        $this->repository->salvar($perfil, flush: true);

        if ($fotoAnterior !== null) {
            $this->storage->excluir($this->storage->caminho($this->fotosPerfilDir, $fotoAnterior));
        }
    }
}
