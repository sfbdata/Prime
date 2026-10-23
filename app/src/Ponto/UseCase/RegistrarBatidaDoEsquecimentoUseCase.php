<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava a batida pedida por uma justificativa de esquecimento de registro que o admin abonou.
 *
 * Caminho único da aprovação individual e do "aprovar todos" (spec `fix-aprovar-todos-esquecimento.md`).
 * Só persiste: quem chama decide o flush (o lote grava tudo de uma vez).
 */
final class RegistrarBatidaDoEsquecimentoUseCase
{
    public const OBSERVACAO = 'Criado por aprovação de justificativa (Esquecimento de Registro)';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Devolve a batida criada, ou null quando a justificativa não é de esquecimento ou falta tipo, hora ou data.
     *
     * @throws \DomainException quando a justificativa é de outro escritório
     */
    public function __invoke(JustificativaPonto $justificativa, Tenant $tenant): ?RegistroPonto
    {
        if ($justificativa->getTipo() !== 'esquecimento_registro'
            || $justificativa->getTipoRegistroEsquecido() === null
            || $justificativa->getHoraRegistroEsquecido() === null
            || $justificativa->getData() === null
        ) {
            return null;
        }

        if ($justificativa->getTenant()?->getId() !== $tenant->getId()) {
            throw new \DomainException('A justificativa não pertence a este escritório.');
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
}
