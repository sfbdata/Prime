<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\MembroDaEquipeOutput;
use App\Ponto\DTO\PainelDaEquipeOutput;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\EscalaDaPessoaNaEquipe;
use App\Ponto\Service\HojeDaPessoaNaEquipe;
use App\Ponto\Service\JustificativasAAnalisarDaEquipe;
use App\Repository\UserTenantRepository;
use Psr\Clock\ClockInterface;

/**
 * A aba "Equipe" da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5, P54, P55, P56 a e P57; desenho 16 L210 a
 * L219): o gestor abre a aba e vê, de cada pessoa que registra ponto no escritório da sessão, a situação de hoje, a
 * entrada, o último registro, o banco de horas, as pendências e a escala; e as justificativas a analisar.
 *
 * Só leitura, só o escritório da sessão (as consultas levam o escritório escrito). Quem chama já conferiu que quem
 * pede gere a equipe (`GestaoDoPonto::veEquipe`). A hora é a do servidor.
 *
 * - Equipe: vínculos ativos com a chave do ponto ligada, por nome; quem vê vem primeiro, com o subtítulo "Você";
 *   os outros, com o cargo (sem cargo, sem subtítulo).
 * - Hoje: as batidas do dia de todos numa consulta só, escolhidas como na folha (`HojeDaPessoaNaEquipe`).
 * - Banco: o mesmo número do topo da /ponto da pessoa (`BancoDeHorasPorLotes`, E17: o banco desde o início da
 *   contagem, sem zerar em janeiro). É a parte cara (2 consultas por mês desde o início da contagem, com teto fixado
 *   em EquipeDoPontoConsultasTest): por isso o painel é buscado só quando a aba abre.
 * - Pendências: as de registro da pessoa neste escritório (`ListarPendenciasDeRegistroUseCase::soNoEscritorio`).
 */
final class ConsultarEquipeDoPontoUseCase
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly RegistroPontoRepository $registros,
        private readonly CalculadoraJornada $calculadora,
        private readonly BancoDeHorasPorLotes $banco,
        private readonly ListarPendenciasDeRegistroUseCase $pendencias,
        private readonly EscalaDaPessoaNaEquipe $escalas,
        private readonly JustificativasAAnalisarDaEquipe $aAnalisar,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, Tenant $escritorio): PainelDaEquipeOutput
    {
        $agora = $this->relogio->now();
        $batidasDeHoje = $this->batidasDeHojePorPessoa($escritorio, $agora);

        $membros = [];
        foreach ($this->equipeComQuemVePrimeiro($eu, $escritorio) as $vinculo) {
            $membros[] = $this->membro($vinculo, $eu, $escritorio, $batidasDeHoje[(int) $vinculo->getUser()->getId()] ?? [], $agora);
        }

        return new PainelDaEquipeOutput($membros, $this->aAnalisar->listar($eu, $escritorio));
    }

    /** @return list<UserTenant> */
    private function equipeComQuemVePrimeiro(User $eu, Tenant $escritorio): array
    {
        $eu_ = [];
        $outros = [];
        foreach ($this->vinculos->findAtivosQueRegistramPontoComCargo($escritorio) as $vinculo) {
            if ($vinculo->getUser()->getId() === $eu->getId()) {
                $eu_[] = $vinculo;
            } else {
                $outros[] = $vinculo;
            }
        }

        return [...$eu_, ...$outros];
    }

    /** @return array<int, list<MarcacaoDoPonto>> as batidas efetivas de hoje, pelo id da pessoa (a incluída pelo gestor também) */
    private function batidasDeHojePorPessoa(Tenant $escritorio, \DateTimeImmutable $agora): array
    {
        $porPessoa = [];
        foreach ($this->registros->batidasDoDiaDaEquipe($escritorio, $agora) as $batida) {
            $porPessoa[$batida->pessoaId()][] = $batida;
        }

        return $porPessoa;
    }

    /** @param list<MarcacaoDoPonto> $batidasDeHoje */
    private function membro(UserTenant $vinculo, User $eu, Tenant $escritorio, array $batidasDeHoje, \DateTimeImmutable $agora): MembroDaEquipeOutput
    {
        $pessoa = $vinculo->getUser();
        $hoje = HojeDaPessoaNaEquipe::daEscolha($this->calculadora->escolherBatidas($batidasDeHoje, $agora));
        $souEu = $pessoa->getId() === $eu->getId();

        return new MembroDaEquipeOutput(
            (int) $pessoa->getId(),
            (string) ($pessoa->getFullName() ?? $pessoa->getEmail()),
            $souEu ? 'Você' : $vinculo->getCargo()?->getNome(),
            $hoje->situacao,
            $hoje->entrada,
            $hoje->ultimoRegistro,
            $this->banco->calcular($pessoa, $escritorio, $agora)->saldoAtualMinutos,
            count($this->pendencias->soNoEscritorio($pessoa, $escritorio)),
            $this->escalas->opcoes($pessoa, $escritorio),
        );
    }
}
