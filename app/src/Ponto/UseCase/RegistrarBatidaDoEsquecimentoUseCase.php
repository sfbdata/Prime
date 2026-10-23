<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Exception\BatidaEmHorarioFuturoException;
use App\Ponto\Service\HorarioFuturoDaBatida;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava a batida pedida por uma justificativa de esquecimento de registro que o admin abonou.
 *
 * Caminho único da aprovação individual e do "aprovar todos" (spec `fix-aprovar-todos-esquecimento.md`).
 * Só persiste: quem chama decide o flush. No "aprovar todos", a notificação de cada item faz flush dentro do
 * laço, então cada item é gravado coerente consigo mesmo (abono e batida juntos), mas o lote não é atômico
 * (já era assim antes desta correção).
 */
final class RegistrarBatidaDoEsquecimentoUseCase
{
    public const OBSERVACAO = 'Criado por aprovação de justificativa (Esquecimento de Registro)';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HorarioFuturoDaBatida $horarioFuturo,
    ) {
    }

    /**
     * Devolve a batida criada, ou null quando a justificativa não é de esquecimento ou falta tipo, hora ou data.
     *
     * @throws \DomainException                quando a justificativa é de outro escritório
     * @throws BatidaEmHorarioFuturoException quando a data e a hora pedidas ficam acima de agora
     */
    public function __invoke(JustificativaPonto $justificativa, Tenant $tenant): ?RegistroPonto
    {
        if (!self::pedeBatida($justificativa)) {
            return null;
        }

        if ($justificativa->getTenant()?->getId() !== $tenant->getId()) {
            throw new \DomainException('A justificativa não pertence a este escritório.');
        }

        if ($this->pedeHorarioFuturo($justificativa, $tenant)) {
            throw new BatidaEmHorarioFuturoException();
        }

        $dataHora = new \DateTime(
            $justificativa->getData()->format('Y-m-d') . ' '
            . $justificativa->getHoraRegistroEsquecido()->format('H:i:s')
        );

        $registro = new RegistroPonto();
        $registro->setUser($justificativa->getUser());
        $registro->setTenant($tenant);
        $registro->setDataHora($dataHora);
        $registro->setTipo($justificativa->getTipoRegistroEsquecido());
        $registro->setObservacao(self::OBSERVACAO);
        $this->em->persist($registro);

        return $registro;
    }

    /**
     * Recusa rápida para quem chama antes de abonar: o pedido de esquecimento cai acima de agora.
     */
    public function pedeHorarioFuturo(JustificativaPonto $justificativa, Tenant $tenant): bool
    {
        return self::pedeBatida($justificativa)
            && $this->horarioFuturo->estaNoFuturo($justificativa->getData(), $justificativa->getHoraRegistroEsquecido(), $tenant);
    }

    private static function pedeBatida(JustificativaPonto $justificativa): bool
    {
        return $justificativa->getTipo() === 'esquecimento_registro'
            && $justificativa->getTipoRegistroEsquecido() !== null
            && $justificativa->getHoraRegistroEsquecido() !== null
            && $justificativa->getData() !== null;
    }
}
