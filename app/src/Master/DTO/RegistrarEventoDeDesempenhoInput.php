<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Master\Enum\TipoDeEventoDeDesempenho;

/**
 * Um evento do monitor (POST /monitor/evento, JSON, ou o listener de login). Dado pessoal mínimo (spec
 * master-desempenho §2.1): só `usuario`. `doJson()` aceita só os tipos que o navegador pode reportar
 * (`login` é gravado pelo servidor), limita `ms`, e reduz a página ao caminho, sem query string nem fragmento.
 */
final readonly class RegistrarEventoDeDesempenhoInput
{
    public const MS_MAXIMO = 600_000;

    public function __construct(
        public TipoDeEventoDeDesempenho $tipo,
        public ?User $usuario = null,
        public ?bool $ok = null,
        public ?int $ms = null,
        public ?string $mensagem = null,
        public ?string $origem = null,
        public ?string $pagina = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(array $corpo, ?User $usuario): ?self
    {
        $tipo = TipoDeEventoDeDesempenho::tryFrom((string) ($corpo['tipo'] ?? ''));
        if ($tipo === null || $tipo === TipoDeEventoDeDesempenho::Login) {
            return null;
        }

        return new self(
            tipo: $tipo,
            usuario: $usuario,
            ok: is_bool($corpo['ok'] ?? null) ? $corpo['ok'] : null,
            ms: is_int($corpo['ms'] ?? null) ? max(0, min($corpo['ms'], self::MS_MAXIMO)) : null,
            mensagem: is_string($corpo['msg'] ?? null) ? $corpo['msg'] : null,
            origem: is_string($corpo['src'] ?? null) ? $corpo['src'] : null,
            pagina: self::soOCaminho($corpo['pag'] ?? null),
        );
    }

    private static function soOCaminho(mixed $pagina): ?string
    {
        if (!is_string($pagina)) {
            return null;
        }
        $caminho = preg_split('/[?#]/', $pagina, 2)[0];

        return $caminho === '' ? null : $caminho;
    }
}
