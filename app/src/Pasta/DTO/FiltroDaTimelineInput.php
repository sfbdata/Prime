<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Service\RegrasDaTimelineInteligente;
use Symfony\Component\HttpFoundation\Request;

/**
 * Os filtros da Timeline inteligente (desenho: chips por tipo, período, pessoa e a busca).
 *
 * Valor desconhecido vira o neutro ("tudo"/todo o período) — é parâmetro de URL, não decisão do
 * usuário: um chip que não existe não pode esvaziar a tela. Já um filtro VÁLIDO que não casa com
 * nada devolve lista vazia, nunca a lista inteira.
 */
final readonly class FiltroDaTimelineInput
{
    public const PERIODOS = ['tudo', 'hoje', 'ontem', 'semana', 'mes'];

    public const LIMITE_PADRAO = 200;
    public const LIMITE_MAXIMO = 600;

    public function __construct(
        public string $categoria = 'tudo',
        public string $periodo = 'tudo',
        public ?string $pessoa = null,
        public string $busca = '',
        public ?\DateTimeImmutable $desde = null,
        public int $limite = self::LIMITE_PADRAO,
    ) {
    }

    public static function daRequisicao(Request $request): self
    {
        $categoria = (string) $request->query->get('categoria', 'tudo');
        $validas   = array_merge(['tudo', 'pendente', 'marco'], array_keys(RegrasDaTimelineInteligente::CATEGORIAS));
        if (!in_array($categoria, $validas, true)) {
            $categoria = 'tudo';
        }

        $periodo = (string) $request->query->get('periodo', 'tudo');
        if (!in_array($periodo, self::PERIODOS, true)) {
            $periodo = 'tudo';
        }

        $pessoa = trim((string) $request->query->get('pessoa', ''));
        $busca  = mb_substr(trim((string) $request->query->get('q', '')), 0, 200);

        $desde      = null;
        $desdeBruto = (string) $request->query->get('desde', '');
        if ($desdeBruto !== '') {
            $lido  = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $desdeBruto)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $desdeBruto, new \DateTimeZone('UTC'));
            $desde = $lido !== false ? $lido : null;
        }

        $limite = (int) $request->query->get('limite', (string) self::LIMITE_PADRAO);
        $limite = max(1, min(self::LIMITE_MAXIMO, $limite));

        return new self(
            categoria: $categoria,
            periodo: $periodo,
            pessoa: $pessoa !== '' ? $pessoa : null,
            busca: $busca,
            desde: $desde,
            limite: $limite,
        );
    }
}
