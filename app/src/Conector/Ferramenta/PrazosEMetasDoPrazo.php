<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\PastaPrazo;

/**
 * Os valores de um prazo ou audiência que a `prazos_e_metas_da_pasta` compara e mostra: tipo, texto, data, hora e
 * responsável. Serve ao "antes" lido do banco, ao "depois" pedido e ao que a proposta guarda nos dados
 * (`docs/specs/mcp-remoto-oauth.md`, 6.6 e 6.7). Texto e responsável ficam CRUS aqui (o que o UseCase grava); a
 * proteção de texto do chat é de quem monta a resposta ou o resumo.
 */
final class PrazosEMetasDoPrazo
{
    public const TIPOS = [PastaPrazo::TIPO_PRAZO => 'Prazo', PastaPrazo::TIPO_AUDIENCIA => 'Audiência'];

    public function __construct(
        public readonly string $tipo,
        public readonly string $texto,
        /** `aaaa-mm-dd`. */
        public readonly string $data,
        public readonly ?string $hora,
        public readonly ?string $responsavel,
        public readonly ?int $responsavelId = null,
    ) {
    }

    public static function dePrazo(PastaPrazo $prazo): self
    {
        return new self($prazo->getTipo(), $prazo->getTitulo(), $prazo->getData()->format('Y-m-d'), $prazo->getHora(), $prazo->getResponsavel());
    }

    /**
     * Os dados que a proposta guarda (`antes` ou `depois`); volta igual por {@see dosDados()}.
     *
     * @return array{tipo: string, texto: string, data: string, hora: ?string, responsavel: ?string, responsavel_id: ?int}
     */
    public function comoDados(): array
    {
        return [
            'tipo' => $this->tipo,
            'texto' => $this->texto,
            'data' => $this->data,
            'hora' => $this->hora,
            'responsavel' => $this->responsavel,
            'responsavel_id' => $this->responsavelId,
        ];
    }

    /**
     * @param mixed $dados o `antes` ou o `depois` da proposta
     *
     * @throws ErroDaFerramenta dados que não têm a forma de {@see comoDados()}
     */
    public static function dosDados(mixed $dados): self
    {
        if (!\is_array($dados) || !\is_string($dados['tipo'] ?? null) || !\is_string($dados['texto'] ?? null) || !\is_string($dados['data'] ?? null)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $hora = $dados['hora'] ?? null;
        $responsavel = $dados['responsavel'] ?? null;
        $responsavelId = $dados['responsavel_id'] ?? null;
        if (($hora !== null && !\is_string($hora)) || ($responsavel !== null && !\is_string($responsavel)) || ($responsavelId !== null && !\is_int($responsavelId))) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return new self($dados['tipo'], $dados['texto'], $dados['data'], $hora, $responsavel, $responsavelId);
    }

    /** Os cinco valores iguais (o id do responsável escolhido não conta: o que vale é o nome gravado). */
    public function mesmosValoresQue(self $outro): bool
    {
        return $this->tipo === $outro->tipo
            && $this->texto === $outro->texto
            && $this->data === $outro->data
            && ($this->hora ?? '') === ($outro->hora ?? '')
            && ($this->responsavel ?? '') === ($outro->responsavel ?? '');
    }

    public function rotuloDoTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function dataPorExtenso(): string
    {
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->data);

        return $dia === false ? $this->data : $dia->format('d/m/Y');
    }

    /**
     * Rótulo e valor de cada campo, para o resumo: o texto e o responsável passam pelo `$texto` de quem chama.
     *
     * @param \Closure(string): string $texto
     *
     * @return array<string, string>
     */
    public function paraOResumo(\Closure $texto): array
    {
        return [
            'Tipo' => $this->rotuloDoTipo(),
            'Data' => $this->dataPorExtenso(),
            'Hora' => $this->hora === null || $this->hora === '' ? 'sem hora' : $this->hora,
            'Responsável' => $this->responsavel === null || $this->responsavel === '' ? 'sem responsável' : $texto($this->responsavel),
            'Texto' => $texto($this->texto),
        ];
    }
}
