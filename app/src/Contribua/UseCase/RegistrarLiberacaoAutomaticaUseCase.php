<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Event\OpcoesDeEspacoForamLiberadas;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\LiberacaoDasOpcoesDeEspaco;
use App\Contribua\Service\SituacaoDoEspaco;
use Psr\Clock\ClockInterface;

/**
 * Grava a marca `auto` quando o uso do escritório chega a 97% do limite (desenho Contribua 14 e 15/11, N8, decisão D3).
 *
 * Idempotente: grava uma vez. Não mexe quando o escritório já tem a marca (Master ou automática anterior) e só despacha
 * `OpcoesDeEspacoForamLiberadas(Auto)` ao gravar. Quem a lê sabe que ela só vale enquanto o uso estiver em 97% ou mais
 * (`LiberacaoDasOpcoesDeEspaco`). O evento sai depois do trabalho dar certo (`EventosDoEspacoAposConfirmar`).
 */
final class RegistrarLiberacaoAutomaticaUseCase
{
    public function __construct(
        private readonly SituacaoDoEspaco $situacao,
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
        private readonly EventosDoEspacoAposConfirmar $eventos,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return bool true se gravou a marca agora */
    public function executar(int $tenantId): bool
    {
        $retrato = $this->situacao->doEscritorio($tenantId);
        if (!LiberacaoDasOpcoesDeEspaco::usoNoLimiar($retrato->bytesUsados, $retrato->bytesLimite)) {
            return false;
        }

        $espaco = $this->espacos->doTenantOuNovo($tenantId, $this->planos->free());
        if ($espaco->getOpcoesLiberadasPor() !== null) {
            return false;
        }
        $espaco->liberarOpcoes(OrigemDaLiberacao::Auto, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $this->espacos->salvar($espaco, flush: true);

        $this->eventos->adiar(new OpcoesDeEspacoForamLiberadas($tenantId, OrigemDaLiberacao::Auto, null));

        return true;
    }
}
