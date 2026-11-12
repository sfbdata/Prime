<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\ComprovanteConferido;
use App\Voto\Entity\Assembleia;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Service\ComprovanteDoVoto;

/**
 * Conferência pública de um comprovante ("Confira seu comprovante", desenho 18 L180). Quem: qualquer pessoa com o
 * código, pelo portal da assembleia. Devolve só a pauta e o momento em que a unidade votou; nunca a unidade nem a
 * opção. Recalcula o código das participações da assembleia (o código é um HMAC, não há o que consultar por ele).
 */
final class ConferirComprovanteUseCase
{
    public function __construct(
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly ComprovanteDoVoto $comprovante,
    ) {
    }

    /** @return ComprovanteConferido|null null quando o texto não é um código ou nenhuma participação o gerou */
    public function executar(Assembleia $assembleia, string $texto): ?ComprovanteConferido
    {
        $codigo = ComprovanteDoVoto::normalizar($texto);
        if ('' === $codigo) {
            return null;
        }
        foreach ($this->participacoes->paraConferencia($assembleia) as $p) {
            if (hash_equals($codigo, $this->comprovante->codigo($p['unidadeId'], $p['pautaId'], $p['registradaEm']))) {
                return new ComprovanteConferido($p['pauta'], $p['registradaEm']);
            }
        }

        return null;
    }
}
