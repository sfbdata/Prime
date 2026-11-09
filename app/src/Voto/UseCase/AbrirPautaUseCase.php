<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * A mesa abre a pauta (spec voto-assembleia-condominio, seção 1.7): "1ª convocação" ou "2ª convocação" (quórum 50% ou
 * 0%), "Abrir por minutos" (prazo = agora + minutos) ou "Abrir sem prazo" (minutos null). A primeira abertura trava
 * a lista de unidades. A pauta é relida TRAVADA para a abertura não cruzar com outra ação da mesa.
 */
final class AbrirPautaUseCase
{
    public const MINUTOS_MAXIMOS = 1440;

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, int $convocacao, ?int $minutos, string $nome, ?\DateTimeImmutable $agora = null): void
    {
        $agora ??= new \DateTimeImmutable();
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $prazo = self::prazo($minutos, $agora);
        $this->transacao->executar(function () use ($assembleia, $pautaId, $convocacao, $prazo, $nome, $agora): void {
            $pauta = $this->pautas->buscarTravadaDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            $pauta->abrir($nome, $convocacao, $prazo, $agora);
        });
    }

    /** Minutos de 1 a 1440 viram o prazo; null é "sem prazo". Também usado por "Definir prazo". */
    public static function prazo(?int $minutos, \DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        if (null === $minutos) {
            return null;
        }
        if ($minutos < 1 || $minutos > self::MINUTOS_MAXIMOS) {
            throw new OperacaoInvalidaNaAssembleiaException(sprintf('Informe os minutos, de 1 a %d.', self::MINUTOS_MAXIMOS));
        }

        return $agora->modify(sprintf('+%d minutes', $minutos));
    }
}
