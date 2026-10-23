<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contrato da pasta pendente ou regular (Financeiro). Com quem mudou e o escritório, o andamento acompanha
 * (desenho 1.2.3, `contratoFase`, E12b): regular leva a pasta até a fase Contrato; pendente volta dela.
 *
 * Toda mudança pelo selo grava o cartão "Contrato: marcado como assinado." / "Contrato: reaberto como pendente."
 * (desenho 02, `toggleContrato`, dc L6998), antes do cartão "Andamento" que a fase eventualmente ganhar. Não há
 * duplicata: o cartão "marcado como assinado (fase Contrato concluída no andamento)." só sai quando é o ANDAMENTO que
 * move o contrato (`sincronizarContrato`), e o `aoMudarContrato` daqui marca a fase sem essa sincronização, como o
 * `_ctSync` do desenho.
 */
final class AlterarSituacaoContratoUseCase
{
    private const SITUACOES_VALIDAS = ['PENDENTE', 'REGULAR'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?AndamentoDaPasta $andamento = null,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
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
        if ($mudou && $this->registro !== null && $usuario !== null) {
            $this->registro->registrar($pasta, $usuario, 'contrato', $situacao === 'REGULAR' ? 'marcado como assinado.' : 'reaberto como pendente.');
        }
        if ($mudou && $this->andamento !== null && $usuario !== null && $tenant !== null) {
            $this->andamento->aoMudarContrato($pasta, $usuario, $tenant, $situacao);
        }
        $this->em->flush();
    }
}
