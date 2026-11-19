<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;

/**
 * O pedido da `documentos_e_pecas`, já conferido: a chave da tabela (`acao`), a operação, a pasta e os campos da
 * operação. Nasce dos argumentos da chamada ({@see dosArgumentos()}) ou dos dados guardados na proposta
 * ({@see dosDadosDaProposta()}), que levam os mesmos campos mais a operação e a pasta.
 */
final class DocumentosEPecasPedido
{
    /** @param array<string, mixed> $campos */
    private function __construct(
        public readonly string $chave,
        public readonly string $operacao,
        public readonly int $pastaId,
        public readonly array $campos,
    ) {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function dosArgumentos(array $argumentos): self
    {
        $chave = $argumentos['acao'] ?? null;
        if (!\is_string($chave) || !DocumentosEPecasAcoes::temChave($chave)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $operacao = $argumentos['operacao'] ?? DocumentosEPecasAcoes::operacaoUnica($chave);
        if (!\is_string($operacao) || !DocumentosEPecasAcoes::existe($chave, $operacao)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pastaId = $argumentos['pasta_id'] ?? null;
        if (\is_string($pastaId) && ctype_digit($pastaId) && \strlen($pastaId) <= 9) {
            $pastaId = (int) $pastaId;
        }
        if (!\is_int($pastaId) || $pastaId < 1) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao'], $argumentos['operacao'], $argumentos['pasta_id']);

        return new self($chave, $operacao, $pastaId, DocumentosEPecasCampos::conferir(DocumentosEPecasAcoes::regras($chave, $operacao), $argumentos));
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public static function dosDadosDaProposta(string $chave, array $dados): self
    {
        $operacao = $dados['operacao'] ?? null;
        $pastaId = $dados['pasta_id'] ?? null;
        if (!\is_string($operacao) || !\is_int($pastaId)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $argumentos = ['acao' => $chave, 'operacao' => $operacao, 'pasta_id' => $pastaId] + $dados;

        return self::dosArgumentos($argumentos);
    }

    public function acao(): string
    {
        return DocumentosEPecasAcoes::junta($this->chave, $this->operacao);
    }

    public function porProposta(): bool
    {
        return DocumentosEPecasAcoes::porProposta($this->chave, $this->operacao, $this->campos);
    }

    /**
     * Os dados que ficam na proposta: tudo o que será feito, exatamente.
     *
     * @return array<string, mixed>
     */
    public function dados(): array
    {
        return ['operacao' => $this->operacao, 'pasta_id' => $this->pastaId] + $this->campos;
    }
}
