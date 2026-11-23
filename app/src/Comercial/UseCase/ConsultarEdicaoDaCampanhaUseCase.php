<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\EdicaoDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use Psr\Clock\ClockInterface;

/**
 * Abre uma campanha em modo edição (pedido do Farlei M5.3, 23/11/2026): devolve a cópia congelada no formato do editor, a
 * data do próximo envio e a frequência. Só enquanto a campanha não começou a sair
 * ({@see CampanhaDeEmailRepository::condicaoEditavel()}); depois disso, a recusa de {@see EditarCampanhaUseCase::JA_COMECOU}.
 * Null se a campanha não existe. Só lê.
 */
final class ConsultarEdicaoDaCampanhaUseCase
{
    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $id): ?EdicaoDaCampanha
    {
        $c = $this->campanhas->porId($id);
        if ($c === null) {
            return null;
        }
        if (!$this->campanhas->editavel($id)) {
            throw new CampanhaRecusada(EditarCampanhaUseCase::JA_COMECOU);
        }
        $proxima = $c['proxima_execucao'] === null ? null : new \DateTimeImmutable($c['proxima_execucao']);
        $futura = $proxima !== null && $proxima > $this->relogio->now();

        return new EdicaoDaCampanha(
            $this->composicao->descongelar($c)->paraArray(),
            $futura ? $proxima->format('Y-m-d\TH:i') : '',
            $c['frequencia'],
            $c['estado'],
            $c['nome'],
        );
    }
}
