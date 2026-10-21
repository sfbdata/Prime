<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\AjusteDeData;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * História: com o botão direito num dia, quem cuida das configurações do escritório acrescenta um
 * feriado ou data que o sistema não conhece (feriado municipal, suspensão de expediente no tribunal),
 * escolhendo o calendário (Nacional, Estadual, Tribunal, Comemorativa) e, se quiser, a fonte. A data
 * aparece na Agenda de todo o escritório. Não vale para o ponto: para contar como feriado na jornada,
 * cadastra-se em Feriados e Folgas (spec §5 e §13).
 */
final class AcrescentarDataUseCase
{
    public const CALENDARIOS = ['fer', 'est', 'trib', 'com'];

    public function __construct(
        private readonly AjusteDeDataRepository $ajustes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu, string $data, string $titulo, string $calendario, string $fonte): int
    {
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (false === $dia || $dia->format('Y-m-d') !== $data) {
            throw new \DomainException('Data inválida.');
        }
        $titulo = trim($titulo);
        if ('' === $titulo || mb_strlen($titulo) > 255) {
            throw new \DomainException('Informe o nome do feriado ou da data (até 255 caracteres).');
        }
        if (!in_array($calendario, self::CALENDARIOS, true)) {
            throw new \DomainException('Escolha o tipo: Nacional, Estadual, Tribunal ou Comemorativa.');
        }
        $fonte = mb_substr(trim($fonte), 0, 255);
        $ajuste = AjusteDeData::acrescentar($tenant, $dia, $titulo, $calendario, '' === $fonte ? null : $fonte, $eu);
        $this->ajustes->salvar($ajuste);

        return (int) $ajuste->getId();
    }
}
