<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Comercial\Entity\CampanhaDeEmail;
use App\Conector\Exception\ErroDaFerramenta;

/**
 * A entrada da `campanhas_de_email` já conferida: a ação e os campos que ela aceita. Campo de outra ação ou de tipo
 * errado é entrada inválida; texto acima do limite é recusado com o nome do campo e o limite, nunca cortado em silêncio.
 * Pelo conector só saem os públicos `leads`, `prim`, `sec` e `todos` (6.9: nunca lista de e-mails).
 */
final class CampanhasDeEmailEntrada
{
    public const PUBLICOS = ['leads', 'prim', 'sec', 'todos'];
    public const TAMANHO_DA_DATA = 19;
    public const TAMANHO_DO_MODELO = 40;
    public const PASSOU_DO_LIMITE = 'O campo %s passa do limite de %d caracteres.';

    private const CAMPOS_DA_ACAO = [
        CampanhasDeEmailFerramenta::CRIAR => ['modelo', 'publico', 'frequencia', 'agendar'],
        CampanhasDeEmailFerramenta::EDITAR => ['campanha_id', 'frequencia', 'agendar', 'atualizar_do_modelo'],
        CampanhasDeEmailFerramenta::CANCELAR => ['campanha_id'],
        CampanhasDeEmailFerramenta::ENVIAR_TESTE => ['modelo'],
    ];

    /** @param array<string, mixed> $campos */
    private function __construct(
        public readonly string $acao,
        public readonly array $campos,
    ) {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function deArgumentos(array $argumentos): self
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach ($argumentos as $campo => $valor) {
            if (!self::tipoValido($campo, $valor)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        if (isset($argumentos['modelo'])) {
            $argumentos['modelo'] = self::chaveDoModelo($argumentos['modelo']);
        }
        foreach (['criar' => ['modelo', 'publico'], 'editar' => ['campanha_id'], 'cancelar' => ['campanha_id'], 'enviar_teste' => ['modelo']][$acao] as $obrigatorio) {
            if (!isset($argumentos[$obrigatorio])) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        self::exigirValoresValidos($argumentos);

        return new self($acao, $argumentos);
    }

    /** `12`, `cu12` ou a chave de um padrão (`convite`, `data-natal`): vira `cu12` ou a chave. */
    private static function chaveDoModelo(string $valor): string
    {
        $valor = trim($valor);
        if (mb_strlen($valor) > self::TAMANHO_DO_MODELO) {
            throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, 'modelo', self::TAMANHO_DO_MODELO));
        }
        if (preg_match('/^(?:cu)?(\d{1,9})$/', $valor, $partes) === 1) {
            return 'cu' . $partes[1];
        }

        return preg_match('/^[a-z0-9-]{1,40}$/', $valor) === 1 ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /** @param array<string, mixed> $campos */
    private static function exigirValoresValidos(array $campos): void
    {
        if (isset($campos['publico']) && !\in_array($campos['publico'], self::PUBLICOS, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if (isset($campos['frequencia']) && !\in_array($campos['frequencia'], CampanhaDeEmail::FREQUENCIAS, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if (isset($campos['agendar']) && mb_strlen($campos['agendar']) > self::TAMANHO_DA_DATA) {
            throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, 'agendar', self::TAMANHO_DA_DATA));
        }
        if (isset($campos['campanha_id']) && $campos['campanha_id'] < 1) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    private static function tipoValido(string $campo, mixed $valor): bool
    {
        return match ($campo) {
            'campanha_id' => \is_int($valor),
            'atualizar_do_modelo' => \is_bool($valor),
            default => \is_string($valor),
        };
    }
}
