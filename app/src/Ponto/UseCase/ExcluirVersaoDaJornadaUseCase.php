<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Exception\VersaoDaJornadaNaoEncontradaException;
use App\Ponto\Exception\VersaoDaJornadaRecusadaException;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * Exclui uma versão do histórico da jornada (spec `docs/specs/ponto-jornada-vigencia.md` §10.2, regra 4). Os dias dela
 * passam para a versão anterior (ou, se era a mais antiga, para a seguinte). A única versão não sai por aqui: tirar a
 * jornada personalizada é o "Limpar escala".
 *
 * Se a excluída cobria os dias anteriores à primeira versão, a mais antiga que sobra herda a marca: aqueles dias
 * continuam com jornada.
 */
final class ExcluirVersaoDaJornadaUseCase
{
    public function __construct(
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly TrilhaDoPonto $trilha,
    ) {
    }

    /**
     * @throws VersaoDaJornadaNaoEncontradaException a versão não é desta pessoa neste escritório
     * @throws VersaoDaJornadaRecusadaException      é a única versão
     */
    public function executar(User $pessoa, Tenant $escritorio, int $versaoId): void
    {
        $versoes = $this->jornadas->historicoDe($pessoa, $escritorio)?->versoes() ?? [];

        $alvo = null;
        $restantes = [];
        foreach ($versoes as $versao) {
            if ($versao->getId() === $versaoId) {
                $alvo = $versao;
                continue;
            }
            $restantes[] = $versao;
        }
        if ($alvo === null) {
            throw new VersaoDaJornadaNaoEncontradaException();
        }
        if ($restantes === []) {
            throw new VersaoDaJornadaRecusadaException('A jornada tem uma versão só. Para tirar a jornada personalizada, use "Limpar escala".');
        }

        if ($alvo->cobreDiasAnteriores()) {
            $restantes[0]->setCobreDiasAnteriores(true);
        }
        $this->jornadas->remover($alvo);
        // Trilha do Ponto (E9): a troca de escala comita junto com o evento.
        $this->trilha->emTransacao(function () use ($pessoa, $escritorio, $alvo): void {
            $this->jornadas->gravar();
            $this->trilha->registrar($escritorio, $this->trilha->autorDaSessao(), AcaoDaTrilhaDoPonto::EscalaAlterada->value, DetalheDaTrilhaDoPonto::daEscala($pessoa, 'versao_excluida', $alvo->getValeAPartirDe()));
        });
    }
}
