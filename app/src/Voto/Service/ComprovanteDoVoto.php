<?php

declare(strict_types=1);

namespace App\Voto\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Código de comprovante de que a unidade votou numa pauta (spec docs/specs/voto-cabine.md, seção "Comprovante").
 *
 * É um HMAC da unidade, da pauta e da hora da participação (em segundos, como o banco guarda): prova que a unidade
 * votou e quando, e NUNCA em quê (a participação não guarda a opção). Só o servidor, com o segredo da aplicação,
 * consegue refazer o código; por isso a conferência recalcula o código das participações da assembleia e compara.
 * Formato `BJV-XXXX-XXXX-XXXX` (12 hexadecimais), o do desenho (L180: "BJV-...").
 */
final class ComprovanteDoVoto
{
    private const PREFIXO = 'BJV';

    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $segredo)
    {
    }

    public function codigo(int $unidadeId, int $pautaId, \DateTimeImmutable $registradaEm): string
    {
        $hash = hash_hmac('sha256', 'voto-comprovante:' . $unidadeId . ':' . $pautaId . ':' . $registradaEm->format('Y-m-d H:i:s'), $this->segredo);

        return self::PREFIXO . '-' . implode('-', str_split(strtoupper(substr($hash, 0, 12)), 4));
    }

    /** Aceita minúsculas, espaços e o prefixo sem os traços; devolve o formato do código ou '' se não parecer um. */
    public static function normalizar(string $texto): string
    {
        $limpo = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $texto));
        if (!preg_match('/^BJV([0-9A-F]{12})$/', $limpo, $achou)) {
            return '';
        }

        return self::PREFIXO . '-' . implode('-', str_split($achou[1], 4));
    }
}
