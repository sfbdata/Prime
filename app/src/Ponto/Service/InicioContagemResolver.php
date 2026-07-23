<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * Resolve a partir de quando a folha de ponto conta para um colaborador num escritório.
 *
 * A contagem abre no registro de ponto MAIS ANTIGO — seja uma batida real, seja uma justificativa
 * já abonada (um abono deferido também é registro de ponto). Antes disso não há controle de ponto
 * para a pessoa, então não há meta a cobrar.
 *
 * Data de admissão e data de cadastro NÃO entram nesta conta: a primeira diz desde quando a pessoa
 * é funcionária, a segunda desde quando o registro existe — nenhuma das duas responde "desde quando
 * há controle de ponto para ela". Usá-las já causou incidente em produção (colaboradores admitidos
 * antes de o sistema existir acumulando centenas de horas negativas).
 */
final class InicioContagemResolver
{
    /**
     * Um abono só puxa o início da contagem para trás se cair DENTRO desta janela antes da primeira
     * batida. Sem o limite, um abono retroativo antigo (o sistema aceita qualquer data passada)
     * abriria dezenas de dias úteis sem batida como débito — o mesmo débito fantasma que esta regra
     * existe para matar. A janela cobre o caso real: esqueceu de bater os primeiros dias e o admin
     * deferiu abono depois.
     */
    private const JANELA_ABONO_DIAS = 30;

    public function __construct(
        private readonly RegistroPontoRepository $registroPontoRepository,
        private readonly JustificativaPontoRepository $justificativaPontoRepository,
    ) {}

    /** null = colaborador sem nenhum registro de ponto: não há o que contar. */
    public function resolver(User $user, Tenant $tenant): ?\DateTimeImmutable
    {
        $primeiraBatida = $this->registroPontoRepository->findDataPrimeiraBatida($user, $tenant);

        // Sem nenhuma batida, o abono é o único registro de ponto que existe — não há âncora
        // para janela nenhuma.
        if ($primeiraBatida === null) {
            return $this->justificativaPontoRepository->findDataPrimeiraAbonada($user, $tenant);
        }

        $limiteJanela = $primeiraBatida->modify(sprintf('-%d days', self::JANELA_ABONO_DIAS));

        // Busca o abono mais antigo DENTRO da janela: um abono anterior a ela não adianta a contagem.
        $abonoNaJanela = $this->justificativaPontoRepository
            ->findDataPrimeiraAbonada($user, $tenant, $limiteJanela);

        if ($abonoNaJanela === null || $abonoNaJanela >= $primeiraBatida) {
            return $primeiraBatida;
        }

        return $abonoNaJanela;
    }
}
