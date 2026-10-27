<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\UseCase\CumprirPrazoDaPastaUseCase;

/**
 * "Prazo cumprido" do modal da publicação. Marca a publicação e, quando o teor gerou prazo na pasta e o
 * usuário edita a pasta, cumpre também o `pasta_prazo` (sai dos filtros do Expediente). Desmarcar só vale
 * enquanto nenhum prazo da pasta estiver cumprido: o da pasta não tem "descumprir".
 */
final class CumprirPrazoDaPublicacaoUseCase
{
    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaPrazoRepository $prazosDaPasta,
        private readonly CumprirPrazoDaPastaUseCase $cumprirNaPasta,
    ) {
    }

    /** @throws VinculoDaPublicacaoInvalidoException */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, bool $cumprido): void
    {
        $publicacao = $this->publicacoes->findOneByIdDoTenant($publicacaoId, $tenant)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        $naPasta = $this->prazosDaPasta->doPushDaPublicacao($publicacaoId, $tenant);

        if (!$cumprido) {
            foreach ($naPasta as $prazo) {
                if ($prazo->estaCumprido()) {
                    throw new VinculoDaPublicacaoInvalidoException('O prazo já foi cumprido na pasta e não pode ser desmarcado aqui.');
                }
            }
            $publicacao->marcarPrazoCumprido(null);
            $this->publicacoes->flush();

            return;
        }

        $publicacao->marcarPrazoCumprido(new \DateTimeImmutable());
        $this->publicacoes->flush();
        foreach ($naPasta as $prazo) {
            if (!$prazo->estaCumprido()) {
                try {
                    $this->cumprirNaPasta->executar($prazo->getPasta(), (int) $prazo->getId(), $usuario, $tenant);
                } catch (\DomainException) {
                    // Sem permissão para editar aquela pasta: a publicação fica cumprida, o prazo da pasta segue aberto.
                }
            }
        }
    }
}
