<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\AjusteDeData;
use App\Agenda\Enum\TipoDeAjuste;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Agenda\Service\IdDeDataDaAgenda;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * História: com o botão direito, quem cuida das configurações do escritório tira uma data da Agenda.
 * Data acrescentada pelo escritório é excluída ("Excluir"); data do sistema fica oculta ("Ocultar da
 * agenda") até "Restaurar datas do sistema". Ocultar um feriado aqui NÃO o tira do ponto: se ele está
 * em Feriados e Folgas, continua contando na jornada (spec §5 e §13).
 */
final class OcultarDataUseCase
{
    public function __construct(
        private readonly AjusteDeDataRepository $ajustes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu, string $idDaAgenda): void
    {
        $acrescentada = IdDeDataDaAgenda::acrescentada($idDaAgenda);
        if (null !== $acrescentada) {
            $ajuste = $this->ajustes->doEscritorioPorId($acrescentada, $tenant) ?? throw new \DomainException('Data não encontrada.');
            $this->ajustes->remover($ajuste);

            return;
        }
        $dataId = IdDeDataDaAgenda::doSistema($idDaAgenda);
        if (null === $this->ajustes->daData($dataId, TipoDeAjuste::Ocultar, $tenant)) {
            $this->ajustes->salvar(AjusteDeData::ocultar($tenant, $dataId, $eu));
        }
    }
}
