<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Djen\DTO\PrazoDaPublicacao;
use App\Pasta\Service\CalculadoraDoPrazoDoPush;
use App\Pasta\Service\DiasUteisDoEscritorio;

/**
 * Prazo mostrado na publicação do Push (spec push-prazo-na-publicacao), nesta ordem: os dias que o
 * usuário escolheu (úteis, calendário da UF do escritório) e, na falta deles, a data que o teor gerou
 * na pasta. Sem nenhum dos dois, não há prazo: nada é presumido.
 */
final class PrazoDaPublicacaoCalculador
{
    public function __construct(
        private readonly CalculadoraDoPrazoDoPush $calculadora,
        private readonly DiasUteisDoEscritorio $diasUteis,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $prazoDoTeor data do `pasta_prazo` do Push, se houver
     */
    public function calcular(
        ?\DateTimeImmutable $disponibilizacao,
        ?int $diasEscolhidos,
        ?\DateTimeImmutable $prazoDoTeor,
        bool $cumprido,
        ?string $uf,
        \DateTimeImmutable $hoje,
    ): ?PrazoDaPublicacao {
        $calendario = $this->diasUteis->daUf($uf);
        $hoje = $hoje->setTime(0, 0);

        if ($diasEscolhidos !== null && $disponibilizacao !== null) {
            $vencimento = $this->calculadora->vencimento($disponibilizacao, $diasEscolhidos, false, $calendario);
            $publicacao = $calendario->proximoUtil($disponibilizacao->setTime(0, 0)->modify('+1 day'));
            $inicio = $calendario->proximoUtil($publicacao->modify('+1 day'));

            return new PrazoDaPublicacao($vencimento, $inicio, $diasEscolhidos, $this->restante($vencimento, $hoje, $uf), $cumprido, PrazoDaPublicacao::ORIGEM_ESCOLHIDO);
        }
        if ($prazoDoTeor !== null) {
            $vencimento = $prazoDoTeor->setTime(0, 0);

            return new PrazoDaPublicacao($vencimento, null, null, $this->restante($vencimento, $hoje, $uf), $cumprido, PrazoDaPublicacao::ORIGEM_TEOR);
        }

        return null;
    }

    /** Dias úteis de amanhã até o vencimento (hoje não conta); -1 se já passou. */
    private function restante(\DateTimeImmutable $vencimento, \DateTimeImmutable $hoje, ?string $uf): int
    {
        if ($vencimento < $hoje) {
            return -1;
        }
        if ($vencimento->getTimestamp() === $hoje->getTimestamp()) {
            return 0;
        }

        $restante = 0;
        for ($dia = $hoje->modify('+1 day'); $dia <= $vencimento; $dia = $dia->modify('+1 day')) {
            if ($this->diasUteis->ehUtil($dia, $uf)) {
                ++$restante;
            }
        }

        // Vencimento em dia não útil (no futuro) ainda não é "vence hoje".
        return max(1, $restante);
    }
}
