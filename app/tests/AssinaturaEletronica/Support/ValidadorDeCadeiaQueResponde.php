<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;

/**
 * Dublê do validador de cadeia para a assinatura com certificado (bloco 5C): responde, no protocolo `v1`, um item de
 * {@see CadeiaDeExemplo::item()} para cada `/ByteRange` do PDF recebido (a faixa é a do próprio arquivo, então casa
 * com a do motor), com os campos de {@see $troca}. Com {@see $erro}, devolve o erro definitivo do PDF; com
 * {@see $indisponivel}, lança {@see CadeiaIndisponivel}. Conta as chamadas e guarda as âncoras pedidas.
 *
 * No funcional, substitui o `HttpValidadorDeCadeia` no container antes da primeira requisição; as opções são públicas
 * para o teste trocá-las entre uma requisição e outra.
 */
final class ValidadorDeCadeiaQueResponde implements ValidadorDeCadeia
{
    public int $conferencias = 0;

    /** @var list<string> */
    public array $ancoras = [];

    /** @var array<string, mixed> os campos trocados em cada item (ex.: `['confiavel' => false]`) */
    public array $troca = [];

    public ?string $erro = null;

    public bool $indisponivel = false;

    public function __construct(
        private readonly bool $configurado = true,
    ) {
    }

    public function configurado(): bool
    {
        return $this->configurado;
    }

    public function conferir(string $pdf, string $ancora): ResultadoDaCadeia
    {
        ++$this->conferencias;
        $this->ancoras[] = $ancora;
        if ($this->indisponivel) {
            throw new CadeiaIndisponivel('Validador de teste fora do ar.');
        }
        if ($this->erro !== null) {
            return new ResultadoDaCadeia([], $this->erro);
        }

        $itens = [];
        foreach (self::faixas($pdf) as $posicao => $faixa) {
            $itens[] = CadeiaDeExemplo::item($this->troca + ['campo' => 'Signature' . ($posicao + 1), 'faixa' => $faixa]);
        }

        return CadeiaDeExemplo::resultado($itens);
    }

    /** @return list<list<int>> as faixas de bytes de cada assinatura do PDF, na ordem do arquivo */
    private static function faixas(string $pdf): array
    {
        preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $achados, \PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]], $achados);
    }
}
