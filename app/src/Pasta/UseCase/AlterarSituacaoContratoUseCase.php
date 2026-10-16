<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AndamentoDaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contrato da pasta pendente ou regular (Financeiro). Com quem mudou e o escritório, o andamento acompanha
 * (desenho 1.2.3, `contratoFase`, E12b): regular leva a pasta até a fase Contrato; pendente volta dela.
 */
final class AlterarSituacaoContratoUseCase
{
    private const SITUACOES_VALIDAS = ['PENDENTE', 'REGULAR'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?AndamentoDaPasta $andamento = null,
    ) {}

    public function executar(Pasta $pasta, string $situacao, ?User $usuario = null, ?Tenant $tenant = null): void
    {
        if (!in_array($situacao, self::SITUACOES_VALIDAS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Situação inválida. Valores aceitos: %s.', implode(', ', self::SITUACOES_VALIDAS))
            );
        }

        $mudou = $pasta->getSituacaoContrato() !== $situacao;
        $pasta->setSituacaoContrato($situacao);
        if ($mudou && $this->andamento !== null && $usuario !== null && $tenant !== null) {
            $this->andamento->aoMudarContrato($pasta, $usuario, $tenant, $situacao);
        }
        $this->em->flush();
    }
}
