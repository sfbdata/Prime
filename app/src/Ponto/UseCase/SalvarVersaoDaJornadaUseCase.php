<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\VersaoDaJornadaInput;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Exception\VersaoDaJornadaNaoEncontradaException;
use App\Ponto\Exception\VersaoDaJornadaRecusadaException;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * Grava uma versão da jornada pessoal de uma pessoa num escritório (spec `docs/specs/ponto-jornada-vigencia.md`
 * §3.4 e §10.2).
 *
 * O gestor troca a jornada da Ana para 44h "a partir de 10/03": as versões anteriores ficam como estão e a folha de
 * fevereiro não muda. Se já existe uma versão começando em 10/03, é ela que muda. Se ele abre uma versão do histórico
 * ("Editar"), muda aquela, e a data pode mudar desde que não caia no dia de outra versão.
 *
 * Quem chama já validou os blocos e o choque entre escritórios. O "Habilitar alertas de ponto" não mede o passado:
 * vale para todas as versões da pessoa.
 */
final class SalvarVersaoDaJornadaUseCase
{
    public function __construct(
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly TrilhaDoPonto $trilha,
    ) {
    }

    /**
     * @throws VersaoDaJornadaNaoEncontradaException a versão pedida não é desta pessoa neste escritório
     * @throws VersaoDaJornadaRecusadaException      outra versão já começa na data
     */
    public function executar(User $pessoa, Tenant $escritorio, VersaoDaJornadaInput $input): JornadaColaborador
    {
        $historico = $this->jornadas->historicoDe($pessoa, $escritorio);
        $versoes = $historico?->versoes() ?? [];
        $naData = $this->versaoQueComecaEm($versoes, $input->valeAPartirDe);

        $alvo = $input->versaoId === null ? $naData : $this->versaoPeloId($versoes, $input->versaoId);
        if ($naData !== null && $naData !== $alvo) {
            throw new VersaoDaJornadaRecusadaException(sprintf(
                'Já existe uma versão da jornada que começa em %s.',
                $input->valeAPartirDe->format('d/m/Y'),
            ));
        }

        if ($alvo === null) {
            // A versão nova parte da que valia na data (campos legados e alerta); a primeira parte do zero.
            $base = $historico?->vigenteEm($input->valeAPartirDe) ?? $versoes[0] ?? null;
            $alvo = $base?->novaVersao($input->valeAPartirDe)
                ?? (new JornadaColaborador())->setUser($pessoa)->setTenant($escritorio);
            $this->jornadas->salvar($alvo);
            $versoes[] = $alvo;
        }

        $alvo->setValeAPartirDe($input->valeAPartirDe);
        $alvo->setCargaHorariaSemanal($input->cargaHorariaSemanal);
        foreach ($alvo->getBlocos()->toArray() as $blocoAntigo) {
            $alvo->removeBloco($blocoAntigo);
        }
        foreach ($input->blocos as $bloco) {
            $alvo->addBloco($bloco);
        }
        foreach ($versoes as $versao) {
            $versao->setAlertaHabilitado($input->alertaHabilitado);
        }

        $this->gravarComOEvento($pessoa, $escritorio, $input);

        return $alvo;
    }

    /** Trilha do Ponto (E9): a troca de escala comita junto com o evento "Escala alterada" (com o rótulo, se veio da Equipe). */
    private function gravarComOEvento(User $pessoa, Tenant $escritorio, VersaoDaJornadaInput $input): void
    {
        $this->trilha->emTransacao(function () use ($pessoa, $escritorio, $input): void {
            $this->jornadas->gravar();
            $this->trilha->registrar($escritorio, $this->trilha->autorDaSessao(), AcaoDaTrilhaDoPonto::EscalaAlterada->value, DetalheDaTrilhaDoPonto::daEscala($pessoa, 'versao_salva', $input->valeAPartirDe, $input->escala));
        });
    }

    /** @param list<JornadaColaborador> $versoes */
    private function versaoQueComecaEm(array $versoes, \DateTimeImmutable $dia): ?JornadaColaborador
    {
        foreach ($versoes as $versao) {
            if ($versao->getValeAPartirDe()->format('Y-m-d') === $dia->format('Y-m-d')) {
                return $versao;
            }
        }

        return null;
    }

    /** @param list<JornadaColaborador> $versoes */
    private function versaoPeloId(array $versoes, int $id): JornadaColaborador
    {
        foreach ($versoes as $versao) {
            if ($versao->getId() === $id) {
                return $versao;
            }
        }

        throw new VersaoDaJornadaNaoEncontradaException();
    }
}
