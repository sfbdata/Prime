<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\SolicitarCodigoDaAssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ChaveDoCodigoDaAssembleia;
use App\Voto\Service\CorreioDoCodigoDaAssembleia;
use App\Voto\Service\TransacaoDoVoto;
use Psr\Clock\ClockInterface;

/** Assembleia já resolvida pela porta pública. Resposta void não revela se o cadastro existe. */
final class SolicitarCodigoDaAssembleiaUseCase
{
    public function __construct(
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly TransacaoDoVoto $transacao,
        private readonly ChaveDoCodigoDaAssembleia $chave,
        private readonly CorreioDoCodigoDaAssembleia $correio,
        private readonly ClockInterface $relogio,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    public function executar(Assembleia $assembleia, SolicitarCodigoDaAssembleiaInput $input): void
    {
        $unidade = $this->unidades->localizarParaCodigo($assembleia, $input);
        if ($unidade === null) {
            return;
        }
        $codigo = $this->chave->novo();
        $emitido = $this->transacao->executar(function () use ($unidade, $assembleia, $input, $codigo): bool {
            $travada = $this->unidades->buscarDaAssembleiaTravada((int) $unidade->getId(), $assembleia);

            return $travada?->emitirCodigo($this->chave->hash($codigo, $input->desafio), hash('sha256', $input->desafio), $this->relogio->now()) ?? false;
        });
        if ($emitido) {
            try {
                $this->correio->enviar($unidade, $codigo);
            } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
                $this->logger->warning('Falha ao entregar código de acesso do Voto.');
            }
        }
    }
}
