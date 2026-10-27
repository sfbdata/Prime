<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * Liga (ou troca, ou solta) a publicação do Diário da OAB a uma pasta do escritório. Só quem EDITA a pasta
 * liga; soltar não pede pasta. A publicação é do usuário: id alheio é "não encontrada".
 */
final class VincularPublicacaoDoDiarioAPastaUseCase
{
    public function __construct(
        private readonly PublicacaoDoDiarioDaOabRepository $diario,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acesso,
    ) {
    }

    /**
     * @param int|null $pastaId null solta a publicação da pasta
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, ?int $pastaId): void
    {
        $publicacao = $this->diario->acharDoUsuario($publicacaoId, $tenant, $usuario)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');

        $pasta = null;
        if ($pastaId !== null) {
            $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
            if (!$pasta instanceof Pasta || $pasta->getExcluidaEm() !== null) {
                throw new VinculoDaPublicacaoInvalidoException('Pasta não encontrada.');
            }
            if (!$this->acesso->podeEditar($usuario, $pasta)) {
                throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para vincular a esta pasta.');
            }
        }

        $publicacao->vincularPasta($pasta);
        $this->diario->sincronizar();
    }
}
