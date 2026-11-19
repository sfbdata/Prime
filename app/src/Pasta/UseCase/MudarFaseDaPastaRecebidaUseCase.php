<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Exception\EscritaNaPastaRecebidaNegadaException;
use App\Pasta\Exception\EscritaNaPastaRecebidaRecusadaException;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\EscritaNaPastaRecebida;
use App\Pasta\Service\FasesDaPastaRecebida;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quem recebeu a pasta de outra conta troca a fase do andamento (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9b;
 * rota `expediente_recebida_fase`). Pede a caixa "Movimentar"; a conta da pasta suspensa recusa.
 *
 * Fino sobre {@see AndamentoDaPasta::marcar()}, sem o `MudarFaseDaPastaUseCase` (que exige vínculo na conta da pasta) e
 * sem sincronizar o contrato (mexeria no Financeiro, fora para quem recebeu). Só as fases de
 * {@see FasesDaPastaRecebida::aoAlcance()}: cruzar o Contrato (inclusive saindo dele), Finalizar e Reativar são
 * recusados. Avançar deixa as fases do meio "puladas", como o clique na trilha.
 *
 * O estado, a marcação E o flush rodam com o filtro do escritório desligado: com o filtro da sessão (B) ligado, o
 * andamento gravado de A não seria achado e nasceria outro, duplicado.
 */
final class MudarFaseDaPastaRecebidaUseCase
{
    use SemFiltroDeTenant;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AndamentoDaPasta $andamento,
    ) {
    }

    /**
     * @return string|null o texto registrado no Registro da pasta
     *
     * @throws EscritaNaPastaRecebidaNegadaException
     * @throws EscritaNaPastaRecebidaRecusadaException
     */
    public function executar(PastaRecebida $recebida, User $autor, int $fase): ?string
    {
        EscritaNaPastaRecebida::exigirMovimentar($recebida);

        return $this->semFiltroDeTenant(function () use ($recebida, $autor, $fase): ?string {
            $estado = $this->andamento->estado($recebida->pasta, $recebida->tenant);
            if (!\in_array($fase, FasesDaPastaRecebida::aoAlcance($estado), true)) {
                throw new EscritaNaPastaRecebidaRecusadaException('Esta fase não está disponível para quem colabora de outra conta: ela não cruza o Contrato nem finaliza ou reativa a pasta.');
            }
            $texto = $this->andamento->marcar($recebida->pasta, $autor, $recebida->tenant, $fase, sincronizarContrato: false);
            $this->em->flush();

            return $texto;
        });
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
