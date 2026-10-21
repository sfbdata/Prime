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
 * História: com o botão direito numa data do sistema, quem cuida das configurações do escritório
 * muda o nome dela na Agenda ("Editar nome"). Data acrescentada pelo escritório troca o próprio
 * nome; data do sistema ganha um renome, que "Restaurar datas do sistema" desfaz. Só a Agenda muda.
 */
final class RenomearDataUseCase
{
    public function __construct(
        private readonly AjusteDeDataRepository $ajustes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu, string $idDaAgenda, string $titulo): void
    {
        $titulo = trim($titulo);
        if ('' === $titulo || mb_strlen($titulo) > 255) {
            throw new \DomainException('Informe o nome (até 255 caracteres).');
        }
        $acrescentada = IdDeDataDaAgenda::acrescentada($idDaAgenda);
        if (null !== $acrescentada) {
            $ajuste = $this->ajustes->doEscritorioPorId($acrescentada, $tenant) ?? throw new \DomainException('Data não encontrada.');
            $ajuste->mudarTitulo($titulo);
            $this->ajustes->salvar($ajuste);

            return;
        }
        $dataId = IdDeDataDaAgenda::doSistema($idDaAgenda);
        $renome = $this->ajustes->daData($dataId, TipoDeAjuste::Renomear, $tenant);
        if (null === $renome) {
            $renome = AjusteDeData::renomear($tenant, $dataId, $titulo, $eu);
        }
        $renome->mudarTitulo($titulo);
        $this->ajustes->salvar($renome);
    }
}
