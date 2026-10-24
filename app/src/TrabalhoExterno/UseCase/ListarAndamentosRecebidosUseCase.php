<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Repository\AvisosDeAndamentoRepository;
use App\TrabalhoExterno\Service\Contexto;

/**
 * O que a tela aberta precisa para tocar e mostrar "Andamento · {ID}" (desenho 06 de 24/10, D10;
 * `componentDidMount`, l.1423): os avisos de andamento da demanda que chegaram para quem está olhando.
 *
 * História: sem cursor, só devolve o cursor (o que já estava lá não toca); com cursor, os não lidos
 * depois dele, no máximo {@see LIMITE}, e o cursor novo. A pré-visualização do Master não recebe nada:
 * os avisos são do próprio Master, que não é parte da demanda.
 */
final class ListarAndamentosRecebidosUseCase
{
    public const LIMITE = 5;

    public function __construct(
        private readonly AvisosDeAndamentoRepository $avisos,
    ) {
    }

    /** @return array{cursor: int, andamentos: list<array{codigo: string, titulo: string, texto: string, hora: string}>} */
    public function executar(Contexto $ctx, ?int $desde): array
    {
        if ($ctx->previa) {
            return ['cursor' => 0, 'andamentos' => []];
        }
        if ($desde === null || $desde < 0) {
            return ['cursor' => $this->avisos->ultimoId($ctx->usuario, $ctx->tenant), 'andamentos' => []];
        }

        $cursor = $desde;
        $andamentos = [];
        foreach ($this->avisos->depoisDe($ctx->usuario, $ctx->tenant, $desde, self::LIMITE) as $aviso) {
            $cursor = max($cursor, $aviso['id']);
            if (preg_match('/[?&]te=(TE-\d{1,9})\b/', (string) $aviso['url'], $m) !== 1) {
                continue;
            }
            $andamentos[] = [
                'codigo' => $m[1],
                'titulo' => $aviso['titulo'],
                'texto' => (string) $aviso['mensagem'],
                'hora' => (new \DateTimeImmutable($aviso['criada_em']))->format('H:i'),
            ];
        }

        return ['cursor' => $cursor, 'andamentos' => $andamentos];
    }
}
