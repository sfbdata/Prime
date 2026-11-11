<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\SolicitarCodigoDaAssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ChaveDoCodigoDaAssembleia;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\Service\TransacaoDoVoto;
use Psr\Clock\ClockInterface;

/** Erro retorna null dentro da transação para persistir a tentativa. Acerto consome o código antes do redirecionamento. */
final class ConfirmarCodigoDaAssembleiaUseCase
{
    public function __construct(
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly TransacaoDoVoto $transacao,
        private readonly ChaveDoCodigoDaAssembleia $chave,
        private readonly TokenDaUnidade $tokens,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Assembleia $assembleia, SolicitarCodigoDaAssembleiaInput $input, string $codigo): ?string
    {
        $unidade = $this->unidades->localizarParaCodigo($assembleia, $input);
        if ($unidade === null) {
            return null;
        }

        return $this->transacao->executar(function () use ($unidade, $assembleia, $input, $codigo): ?string {
            $travada = $this->unidades->buscarDaAssembleiaTravada((int) $unidade->getId(), $assembleia);
            if ($travada === null || !$travada->confirmarCodigo($this->chave->hash($codigo, $input->desafio), hash('sha256', $input->desafio), $this->relogio->now())) {
                return null;
            }

            return $this->tokens->token($travada->getSemente());
        });
    }
}
