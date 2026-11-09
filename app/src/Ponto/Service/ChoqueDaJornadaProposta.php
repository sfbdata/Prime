<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Repository\UserTenantRepository;

/**
 * O choque da jornada pessoal proposta com outro escritório onde a pessoa registra ponto (spec seguranca-b-jornada
 * §3.3). Só conta quando a chave do ponto dela está ligada neste escritório: desligada, a jornada daqui não marca
 * horário. Serve à ficha do colaborador (`JornadaColaboradorController`) e à troca de escala da aba Equipe
 * (`TrocarEscalaNaEquipeUseCase`, spec ponto-desenho-0811 E5): a mesma conferência nos dois caminhos.
 */
final class ChoqueDaJornadaProposta
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly ChoqueDeJornadaEntreEscritorios $choque,
        private readonly JornadaTenantRepository $padroes,
    ) {
    }

    /**
     * A recusa, ou null quando não há choque.
     *
     * @param list<BlocoJornadaColaborador> $blocos os blocos propostos; vazio é "sem jornada pessoal em blocos"
     */
    public function mensagem(User $user, Tenant $tenant, ?JornadaColaborador $atual, array $blocos): ?string
    {
        if ($this->vinculos->findAtivoPorUserETenant($user, $tenant)?->isRegistraPonto() !== true) {
            return null;
        }
        $proposta = ($atual ?? new JornadaColaborador())->propostaComBlocos($blocos);

        return $this->choque->mensagem($user, $tenant, $this->intervalos->daJornada($proposta, $this->padroes->doEscritorio($tenant)));
    }
}
