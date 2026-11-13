<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\ComprovanteDeParticipacao;
use App\Voto\DTO\EnviarComprovantePorEmailInput;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\ComprovanteDoVoto;
use App\Voto\Service\CorreioDoComprovante;
use App\Voto\Service\LimiteDosEnviosDoComprovante;
use App\Voto\Service\UnidadeDoLink;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Eleitor pede o próprio comprovante. Valida participação e destinatário antes de consumir a tentativa. */
final class EnviarComprovantePorEmailUseCase
{
    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly ComprovanteDoVoto $codigos,
        private readonly CorreioDoComprovante $correio,
        private readonly LimiteDosEnviosDoComprovante $limite,
        private readonly ValidatorInterface $validador,
    ) {
    }

    public function executar(EnviarComprovantePorEmailInput $input): void
    {
        $unidade = $this->link->localizar($input->token) ?? throw new CedulaNaoEncontradaException();
        $comprovante = $this->comprovanteDaParticipacao($unidade, $input->pauta);
        if (\count($this->validador->validate($input)) > 0) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe um e-mail válido.');
        }
        $this->limite->consumir((int) $unidade->getId());
        $this->correio->enviar($input->email, $comprovante);
    }

    private function comprovanteDaParticipacao(UnidadeDaAssembleia $unidade, int $pautaId): ComprovanteDeParticipacao
    {
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $unidade->getAssembleia()) ?? throw new CedulaNaoEncontradaException();
        $hora = $this->participacoes->horariosDaUnidade($unidade)[$pautaId] ?? null;
        if (!$unidade->isApta() || $hora === null) {
            throw new OperacaoInvalidaNaAssembleiaException('Comprovante indisponível para este voto.');
        }

        return new ComprovanteDeParticipacao(
            $unidade->getAssembleia()->getTitulo(),
            $pauta->getTitulo(),
            trim($unidade->getBloco() . ' ' . $unidade->getIdentificacao()),
            $hora,
            $this->codigos->codigo((int) $unidade->getId(), $pautaId, $hora),
        );
    }
}
