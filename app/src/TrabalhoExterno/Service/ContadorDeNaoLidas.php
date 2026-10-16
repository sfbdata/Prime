<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Repository\LeituraDemandaRepository;
use App\TrabalhoExterno\Repository\MensagemDemandaRepository;

/**
 * Não lidas por demanda, no recorte de quem lê. Central e Solicitante contam numa consulta por
 * "lado" (nota interna só conta para a Central). O Convidado conta pela MESMA regra do chat
 * ({@see PoliticaDaDemanda::mensagemVisivel}): corte do aceite, nota interna e registro de arquivo
 * sem "Ver instruções" ficam de fora. Contar por SQL ali seria repetir a regra em outro lugar, e
 * a diferença entre as duas cópias é justamente o vazamento.
 */
final class ContadorDeNaoLidas
{
    public function __construct(
        private readonly MensagemDemandaRepository $mensagens,
        private readonly LeituraDemandaRepository $leituras,
        private readonly PoliticaDaDemanda $politica,
    ) {
    }

    /**
     * @param list<array{0: DemandaExterna, 1: Contexto}> $pares
     *
     * @return array<int, int> id da demanda → não lidas (ausente = 0)
     */
    public function porDemanda(array $pares): array
    {
        $porLado = [true => [], false => []];
        $usuario = null;
        $mapa = [];
        foreach ($pares as [$d, $ctx]) {
            $usuario = $ctx->usuario;
            // Pré-visualização do Master: a leitura é de OUTRA pessoa (o convidado, o escritório),
            // dado que o Master não vê na tela real. Na prévia não há contagem.
            if ($ctx->previa) {
                $mapa[(int) $d->getId()] = 0;
                continue;
            }
            if ($ctx->perfil === Perfil::Convidado) {
                $mapa[(int) $d->getId()] = $this->doConvidado($ctx, $d);
                continue;
            }
            $porLado[$ctx->ehInterno()][] = $d;
        }
        if ($usuario === null) {
            return [];
        }

        return $mapa
            + $this->mensagens->naoLidasPorDemanda($porLado[true], $usuario, true)
            + $this->mensagens->naoLidasPorDemanda($porLado[false], $usuario, false);
    }

    private function doConvidado(Contexto $ctx, DemandaExterna $d): int
    {
        $ultimaLida = $this->leituras->daDemanda($d, $ctx->usuario)?->getUltimaMensagemLida() ?? 0;
        $total = 0;
        foreach ($d->getMensagens() as $m) {
            if ((int) $m->getId() > $ultimaLida
                && $m->getAutor()?->getId() !== $ctx->usuario->getId()
                && $this->politica->mensagemVisivel($ctx, $d, $m)) {
                ++$total;
            }
        }

        return $total;
    }
}
