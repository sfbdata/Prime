<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\VersaoDaJornadaInput;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Exception\ChoqueDeJornadaException;
use App\Ponto\Exception\EscalaDesconhecidaException;
use App\Ponto\Exception\ForaDaEquipeException;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\CatalogoDeEscalas;
use App\Ponto\Service\ChoqueDaJornadaProposta;
use App\Ponto\Service\EscalaDoCatalogo;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * O gestor troca a escala de uma pessoa da equipe na aba "Equipe" da /ponto (spec `docs/specs/ponto-desenho-0811.md`,
 * E5, P56 a): a Ana passa a fazer 44h de segunda a sábado a partir de hoje, e os dias de antes não mudam.
 *
 * A escala do desenho é um modelo: monta os blocos dela e grava uma versão nova da jornada pessoal valendo a partir de
 * hoje, pela mesma regra da ficha do colaborador (`SalvarVersaoDaJornadaUseCase`: a versão que já começa hoje é
 * atualizada; a primeira nasce sem cobrir os dias anteriores). Antes, a mesma conferência de choque com outro
 * escritório da ficha. O "Habilitar alertas de ponto" fica como está na jornada pessoal; sem ela, ligado, como a ficha
 * abre a primeira.
 *
 * Quem chama já conferiu que quem pede gere a equipe do escritório (`GestaoDoPonto::veEquipe`).
 */
final class TrocarEscalaNaEquipeUseCase
{
    public function __construct(
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly ChoqueDaJornadaProposta $choque,
        private readonly SalvarVersaoDaJornadaUseCase $salvarVersao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws ForaDaEquipeException              a pessoa não existe ou não tem vínculo ativo no escritório
     * @throws EscalaDesconhecidaException        a escala não é do catálogo
     * @throws ChoqueDeJornadaException           a escala bate com o horário dela em outro escritório
     * @throws UniqueConstraintViolationException duas trocas ao mesmo tempo criando a versão de hoje
     */
    public function executar(int $pessoaId, Tenant $escritorio, string $escalaId): JornadaColaborador
    {
        $pessoa = $this->naEquipe($pessoaId, $escritorio);
        $escala = CatalogoDeEscalas::pelo($escalaId) ?? throw new EscalaDesconhecidaException();
        $hoje = $this->relogio->now()->setTime(0, 0);
        $atual = $this->jornadas->historicoDe($pessoa, $escritorio)?->vigenteEm($hoje);
        $blocos = $escala->blocos();
        $this->exigirSemChoque($pessoa, $escritorio, $atual, $blocos);

        return $this->salvarVersao->executar($pessoa, $escritorio, $this->versaoDeHoje($escala, $hoje, $atual, $blocos));
    }

    private function naEquipe(int $pessoaId, Tenant $escritorio): User
    {
        $pessoa = $this->usuarios->find($pessoaId);
        if ($pessoa === null || $this->vinculos->findAtivoPorUserETenant($pessoa, $escritorio) === null) {
            throw new ForaDaEquipeException();
        }

        return $pessoa;
    }

    /** @param list<BlocoJornadaColaborador> $blocos */
    private function exigirSemChoque(User $pessoa, Tenant $escritorio, ?JornadaColaborador $atual, array $blocos): void
    {
        $recusa = $this->choque->mensagem($pessoa, $escritorio, $atual, $blocos);
        if ($recusa !== null) {
            throw new ChoqueDeJornadaException($recusa);
        }
    }

    /** @param list<BlocoJornadaColaborador> $blocos */
    private function versaoDeHoje(EscalaDoCatalogo $escala, \DateTimeImmutable $hoje, ?JornadaColaborador $atual, array $blocos): VersaoDaJornadaInput
    {
        return new VersaoDaJornadaInput($hoje, $escala->cargaSemanal, $atual?->isAlertaHabilitado() ?? true, $blocos);
    }
}
