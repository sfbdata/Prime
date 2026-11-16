<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Repository\PlanoRepository;

/**
 * Quando as opções de compra de espaço extra aparecem para o escritório (desenho Contribua 14 e 15/11, decisão D3):
 * liberação do Master gravada (permanente) OU uso em 97% ou mais (fixo). A marca `auto` é gravada uma vez, mas só vale
 * enquanto o uso estiver em 97% ou mais.
 *
 * Também é a trava D2: o servidor recusa o pedido de plano e o checkout sem liberação, menos para o Master e para quem já
 * paga um plano (continua gerindo o próprio plano).
 */
final class LiberacaoDasOpcoesDeEspaco
{
    public const LIMIAR_EM_PERCENTUAL = 97;

    public const NAO_LIBERADAS = 'As opções de compra de espaço ainda não foram liberadas para o seu escritório. Use "Solicitar mais espaço".';

    public function __construct(
        private readonly SituacaoDoEspaco $situacao,
        private readonly PlanoRepository $planos,
    ) {
    }

    /** O uso chegou a 97% do limite? Pelos bytes exatos (sem o arredondamento do percentual). Sem limite, nunca. */
    public static function usoNoLimiar(int $bytesUsados, ?int $bytesLimite): bool
    {
        return $bytesLimite !== null && $bytesLimite > 0 && $bytesUsados * 100 >= self::LIMIAR_EM_PERCENTUAL * $bytesLimite;
    }

    /** Quem libera agora: o Master (gravado), o uso em 97% (`Auto`) ou ninguém. */
    public static function origem(?EspacoDoEscritorio $espaco, int $bytesUsados, ?int $bytesLimite): ?OrigemDaLiberacao
    {
        if ($espaco?->getOpcoesLiberadasPor() === OrigemDaLiberacao::Master) {
            return OrigemDaLiberacao::Master;
        }

        return self::usoNoLimiar($bytesUsados, $bytesLimite) ? OrigemDaLiberacao::Auto : null;
    }

    /** Trava D2 de "Quero +N GB" e do checkout. */
    public function exigirParaComprar(Solicitante $quem): void
    {
        if ($quem->ehMaster) {
            return;
        }
        $retrato = $this->situacao->doEscritorio($quem->tenantId());
        if ($retrato->opcoesLiberadas || $retrato->planoCodigo !== $this->planos->free()->getCodigo()) {
            return;
        }

        throw new \DomainException(self::NAO_LIBERADAS);
    }
}
