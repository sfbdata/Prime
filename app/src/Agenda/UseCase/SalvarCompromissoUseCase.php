<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\CompromissoInput;
use App\Agenda\DTO\CompromissoSalvoOutput;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\AvisoDoCompromisso;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;

/**
 * História: no modal da Agenda, a pessoa cria ou edita um compromisso: título, tipo (Compromisso,
 * Audiência, Reunião, Prazo), dia e horário (ou dia inteiro), "Equipe do escritório" ou "Só eu",
 * pasta opcional pelo número, lembrete, observações com @colegas a avisar, e (da agenda antiga,
 * mantidos pela convenção D1) repetição e participantes.
 *
 * Regras: editar exige ver o compromisso (senão 404) e ser o criador ou administrar usuários do
 * escritório (senão 403), como na agenda antiga. Fim depois do início, inclusive em compromisso de
 * vários dias. Pasta e colegas só do próprio escritório (colegas ativos). Cada colega avisado recebe o
 * aviso uma vez só; participantes novos também. "Só eu" não avisa ninguém: ninguém mais conseguiria
 * abrir o compromisso. Evento da agenda antiga editado aqui guarda a cor (legenda), convenção D1.
 */
final class SalvarCompromissoUseCase
{
    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly PastaRepository $pastas,
        private readonly UserRepository $usuarios,
        private readonly PermissionChecker $permissoes,
        private readonly AvisoDoCompromisso $aviso,
    ) {
    }

    public function executar(CompromissoInput $in, ?int $id, User $eu, Tenant $tenant, bool $verPastas = true): CompromissoSalvoOutput
    {
        $evento = null === $id ? $this->novo($eu, $tenant) : $this->editavel($id, $eu, $tenant);
        [$inicio, $fim] = $this->horario($in);
        $pasta = $verPastas ? $this->pastaDoEscritorio($in->pasta, $tenant) : $evento->getPasta();
        $this->aplicar($evento, $in, $inicio, $fim, $pasta);
        $this->aplicarRepeticao($evento, $in, $inicio);
        $participantesNovos = $this->aplicarParticipantes($evento, $in->participantes, $tenant);
        $privado = Evento::VISIBILIDADE_SOMENTE_EU === $evento->getVisibilidade();
        $avisadosNovos = $privado ? [] : $this->colegas($evento->avisar($this->idsDoEscritorio($in->avisados, $tenant)), $tenant);
        $this->eventos->salvar($evento);
        $avisados = $privado ? 0 : $this->aviso->avisar($evento, $this->semRepetir($avisadosNovos, $participantesNovos), $eu);

        return new CompromissoSalvoOutput((int) $evento->getId(), $inicio->format('Y-m-d'), $avisados);
    }

    private function novo(User $eu, Tenant $tenant): Evento
    {
        return (new Evento())->setCriador($eu)->setTenant($tenant);
    }

    private function editavel(int $id, User $eu, Tenant $tenant): Evento
    {
        $evento = $this->eventos->visivelPara($id, $tenant, $eu) ?? throw new CompromissoNaoEncontradoException();
        $ehCriador = $evento->getCriador()?->getId() === $eu->getId();
        if (!$ehCriador && !$this->permissoes->canAdminister($eu, $tenant, 'admin.users.manage')) {
            throw new SemPermissaoNoCompromissoException();
        }

        return $evento;
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function horario(CompromissoInput $in): array
    {
        $diaDoFim = (new \DateTimeImmutable($in->data))->modify('+' . $in->dias . ' days')->format('Y-m-d');
        if ($in->diaInteiro) {
            return [new \DateTimeImmutable($in->data . ' 00:00'), new \DateTimeImmutable($diaDoFim . ' 23:59:59')];
        }
        $inicio = new \DateTimeImmutable($in->data . ' ' . $in->inicio);
        $fim = '' === $in->fim ? $inicio->modify('+1 hour') : new \DateTimeImmutable($diaDoFim . ' ' . $in->fim);
        if ($fim <= $inicio) {
            throw new \DomainException('O fim precisa ser depois do início.');
        }

        return [$inicio, $fim];
    }

    private function pastaDoEscritorio(string $nup, Tenant $tenant): ?Pasta
    {
        if ('' === $nup) {
            return null;
        }

        return $this->pastas->findDoEscritorioPorNup($nup, $tenant)
            ?? throw new \DomainException('Pasta ' . $nup . ' não encontrada neste escritório.');
    }

    private function aplicar(Evento $evento, CompromissoInput $in, \DateTimeImmutable $inicio, \DateTimeImmutable $fim, ?Pasta $pasta): void
    {
        $corAtual = $evento->getCor();
        $evento->setTitulo($in->titulo);
        $evento->setTipo(TipoDeCompromisso::from($in->tipo));
        if (null !== $evento->getId() && $this->ehLegendaDaAgendaAntiga($corAtual)) {
            $evento->setCor($corAtual);
        }
        $evento->setDataInicio($inicio);
        $evento->setDataFim($fim);
        $evento->setDiaInteiro($in->diaInteiro);
        $evento->setVisibilidade('privado' === $in->visibilidade ? Evento::VISIBILIDADE_SOMENTE_EU : Evento::VISIBILIDADE_TODOS);
        $evento->setPasta($pasta);
        $evento->setLembreteMinutos($in->lembreteMinutos);
        $evento->setDescricao('' === $in->observacao ? null : $in->observacao);
        $evento->setLocal(null);
    }

    /** Cor que não é de nenhum tipo veio de uma legenda da agenda antiga: fica (convenção D1). */
    private function ehLegendaDaAgendaAntiga(string $cor): bool
    {
        // Só os quatro tipos do formulário antigo: as cores dos três da tela 1A coincidem com legendas antigas.
        $doTipo = array_map(static fn (TipoDeCompromisso $t): string => $t->cor(), array_filter(TipoDeCompromisso::cases(), static fn (TipoDeCompromisso $t): bool => $t->ehDoFormularioAntigo()));

        return !in_array(strtolower($cor), $doTipo, true);
    }

    private function aplicarRepeticao(Evento $evento, CompromissoInput $in, \DateTimeImmutable $inicio): void
    {
        $repete = '' !== $in->repetir;
        if ($repete && null !== $in->repetirAte && $in->repetirAte < $inicio->format('Y-m-d')) {
            throw new \DomainException('A repetição precisa terminar depois do início.');
        }
        $evento->setRecorrente($repete);
        $evento->setTipoRecorrencia($repete ? $in->repetir : null);
        $evento->setFimRecorrencia($repete && null !== $in->repetirAte ? new \DateTimeImmutable($in->repetirAte . ' 23:59:59') : null);
    }

    /**
     * Troca os participantes pelos pedidos (só colegas ativos do escritório) e devolve os que entraram
     * agora. Quem já participava e foi desligado continua: o modal não o mostra, e tirá-lo em silêncio
     * poderia abrir o compromisso para o escritório todo (sem participantes, "Equipe" vale para todos).
     *
     * @param list<int> $ids
     *
     * @return list<User>
     */
    private function aplicarParticipantes(Evento $evento, array $ids, Tenant $tenant): array
    {
        $antes = array_map(static fn (User $u): ?int => $u->getId(), $evento->getParticipantes()->toArray());
        $pedidos = $this->colegas($ids, $tenant);
        $ativos = array_map(static fn (User $u): ?int => $u->getId(), $this->usuarios->findColaboradoresAtivosPorTenant($tenant));
        foreach ($evento->getParticipantes()->toArray() as $atual) {
            if (in_array($atual->getId(), $ativos, true)) {
                $evento->removeParticipante($atual);
            }
        }
        foreach ($pedidos as $u) {
            $evento->addParticipante($u);
        }

        return array_values(array_filter($pedidos, static fn (User $u): bool => !in_array($u->getId(), $antes, true)));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<User>
     */
    private function colegas(array $ids, Tenant $tenant): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values(array_filter(
            $this->usuarios->findColaboradoresAtivosPorTenant($tenant),
            static fn (User $u): bool => in_array($u->getId(), $ids, true),
        ));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function idsDoEscritorio(array $ids, Tenant $tenant): array
    {
        return array_map(static fn (User $u): int => (int) $u->getId(), $this->colegas($ids, $tenant));
    }

    /**
     * @param list<User> $a
     * @param list<User> $b
     *
     * @return list<User>
     */
    private function semRepetir(array $a, array $b): array
    {
        $porId = [];
        foreach ([...$a, ...$b] as $u) {
            $porId[(int) $u->getId()] = $u;
        }

        return array_values($porId);
    }
}
