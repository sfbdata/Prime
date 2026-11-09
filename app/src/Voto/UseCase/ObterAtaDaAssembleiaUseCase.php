<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AtaDaAssembleia;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * A ata imprimível (relatório consolidado do desenho, em HTML): a assembleia lida e, por pauta que saiu da espera, a
 * relação de votantes (quem e quando; nunca em quê) e a participação por bloco ou torre (unidades e peso).
 */
final class ObterAtaDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $emitidaPor, ?\DateTimeImmutable $agora = null): AtaDaAssembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->encerrarVencidas->executar($assembleia, $agora);
        [$votantes, $blocos] = $this->participacaoPorPauta($assembleia);

        return new AtaDaAssembleia($this->leitura->montar($assembleia), $agora ?? new \DateTimeImmutable(), $emitidaPor, $votantes, $blocos);
    }

    /**
     * @return array{0: array<int, list<array{bloco: string, identificacao: string, responsavel: ?string, em: \DateTimeImmutable}>>, 1: array<int, list<array{bloco: string, unidades: int, peso: string}>>}
     */
    private function participacaoPorPauta(Assembleia $assembleia): array
    {
        $votantes = [];
        $blocos = [];
        foreach ($assembleia->getPautas() as $pauta) {
            if (EstadoDaPauta::Aguardando === $pauta->getEstado()) {
                continue;
            }
            $lista = $this->participacoes->listarNominalDaPauta($pauta);
            $votantes[(int) $pauta->getId()] = array_map(self::votante(...), $lista);
            $blocos[(int) $pauta->getId()] = self::porBloco($lista);
        }

        return [$votantes, $blocos];
    }

    /** @return array{bloco: string, identificacao: string, responsavel: ?string, em: \DateTimeImmutable} */
    private static function votante(ParticipacaoNaPauta $participacao): array
    {
        $unidade = $participacao->getUnidade();

        return ['bloco' => $unidade->getBloco(), 'identificacao' => $unidade->getIdentificacao(), 'responsavel' => $unidade->getResponsavel(), 'em' => $participacao->getRegistradaEm()];
    }

    /**
     * @param list<ParticipacaoNaPauta> $lista
     *
     * @return list<array{bloco: string, unidades: int, peso: string}>
     */
    private static function porBloco(array $lista): array
    {
        $blocos = [];
        foreach ($lista as $participacao) {
            $unidade = $participacao->getUnidade();
            $bloco = $blocos[$unidade->getBloco()] ?? ['bloco' => $unidade->getBloco(), 'unidades' => 0, 'peso' => '0.000000'];
            $blocos[$unidade->getBloco()] = ['bloco' => $bloco['bloco'], 'unidades' => $bloco['unidades'] + 1, 'peso' => bcadd($bloco['peso'], $unidade->pesoDoVoto(), 6)];
        }
        ksort($blocos);

        return array_values($blocos);
    }
}
