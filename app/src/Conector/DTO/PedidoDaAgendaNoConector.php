<?php

declare(strict_types=1);

namespace App\Conector\DTO;

use App\ChatIa\DTO\ConsultaDePrazosInput;
use App\ChatIa\Enum\PeriodoDaConsulta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Tarefa\Enum\AbaMetas;

/**
 * A entrada da `ler_agenda` (`docs/specs/mcp-remoto-oauth.md`, 6.4, e 6.2, regra 10), conferida ação por ação. Os
 * argumentos vêm do app de I.A.: dado não confiável. O despacho já recusou campo fora do esquema; aqui:
 *
 *  - {@see acao()}: a ação (`agenda`, o padrão, ou `minhas_metas`); valor fora da lista é "pedido inválido";
 *  - campo de outra ação (ex.: `periodo` na `agenda`) é "pedido inválido": a ação não o usa;
 *  - valor que a ação não aceita é "filtro inválido no campo X", sem o valor digitado.
 *
 * `agenda`: `de` e `ate` em AAAA-MM-DD (padrão: hoje e os {@see DIAS_PADRAO} dias a partir de `de`), no máximo
 * {@see MAXIMO_DE_DIAS} dias contando o primeiro e o último; `so_minha` (padrão `true`); `incluir` (padrão
 * {@see INCLUIR_PADRAO}); `pagina` (padrão 1). `minhas_metas`: `periodo` (obrigatório) e `papel` (padrão
 * `responsavel`), a mesma {@see ConsultaDePrazosInput} do chat.
 */
final class PedidoDaAgendaNoConector
{
    public const AGENDA = 'agenda';
    public const MINHAS_METAS = 'minhas_metas';
    public const ACOES = [self::AGENDA, self::MINHAS_METAS];

    public const COMPROMISSOS = 'compromissos';
    public const PRAZOS = 'prazos';
    public const DATAS_DO_SISTEMA = 'datas_do_sistema';
    public const LEMBRETES = 'lembretes';
    public const INCLUIR = [self::COMPROMISSOS, self::PRAZOS, self::DATAS_DO_SISTEMA, self::LEMBRETES];
    /** Os lembretes são das próximas 24 horas, não do período: só entram quando pedidos. */
    public const INCLUIR_PADRAO = [self::COMPROMISSOS, self::PRAZOS, self::DATAS_DO_SISTEMA];

    public const MAXIMO_DE_DIAS = 31;
    public const DIAS_PADRAO = 7;

    /** Os campos de cada ação; `acao` vale nas duas. */
    public const CAMPOS = [
        self::AGENDA => ['acao', 'de', 'ate', 'so_minha', 'incluir', 'pagina'],
        self::MINHAS_METAS => ['acao', 'periodo', 'papel'],
    ];

    /** @param list<string> $incluir */
    private function __construct(
        public readonly \DateTimeImmutable $de,
        public readonly \DateTimeImmutable $ate,
        public readonly bool $soMinha,
        public readonly array $incluir,
        public readonly int $pagina,
    ) {
    }

    /**
     * A ação pedida; sem o campo, `agenda`.
     *
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function acao(array $argumentos): string
    {
        $acao = $argumentos['acao'] ?? self::AGENDA;
        if (!\is_string($acao) || !\in_array($acao, self::ACOES, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        self::soCamposDa($acao, $argumentos);

        return $acao;
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function daAgenda(array $argumentos, \DateTimeImmutable $hoje): self
    {
        self::soCamposDa(self::AGENDA, $argumentos);

        $de = \array_key_exists('de', $argumentos) ? self::data($argumentos['de'], 'de') : $hoje->setTime(0, 0);
        $ate = \array_key_exists('ate', $argumentos)
            ? self::data($argumentos['ate'], 'ate')
            : $de->modify('+' . (self::DIAS_PADRAO - 1) . ' days');
        if ($ate < $de || $de->diff($ate)->days > self::MAXIMO_DE_DIAS - 1) {
            throw ErroDaFerramenta::filtroInvalido('ate');
        }

        $soMinha = $argumentos['so_minha'] ?? true;
        if (!\is_bool($soMinha)) {
            throw ErroDaFerramenta::filtroInvalido('so_minha');
        }

        $pagina = $argumentos['pagina'] ?? 1;
        if (!\is_int($pagina) || $pagina < 1) {
            throw ErroDaFerramenta::filtroInvalido('pagina');
        }

        return new self($de, $ate, $soMinha, self::incluir($argumentos['incluir'] ?? self::INCLUIR_PADRAO), $pagina);
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function daConsultaDeMetas(array $argumentos): ConsultaDePrazosInput
    {
        self::soCamposDa(self::MINHAS_METAS, $argumentos);

        $periodo = \is_string($argumentos['periodo'] ?? null) ? PeriodoDaConsulta::tryFrom($argumentos['periodo']) : null;
        if ($periodo === null) {
            throw ErroDaFerramenta::filtroInvalido('periodo');
        }
        $papel = \array_key_exists('papel', $argumentos)
            ? (\is_string($argumentos['papel']) ? AbaMetas::tryFrom($argumentos['papel']) : null)
            : AbaMetas::RESPONSAVEL;
        if ($papel === null) {
            throw ErroDaFerramenta::filtroInvalido('papel');
        }

        return new ConsultaDePrazosInput($periodo, $papel);
    }

    public function inclui(string $parte): bool
    {
        return \in_array($parte, $this->incluir, true);
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    private static function soCamposDa(string $acao, array $argumentos): void
    {
        if (array_diff(array_keys($argumentos), self::CAMPOS[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    /** @throws ErroDaFerramenta */
    private static function data(mixed $valor, string $campo): \DateTimeImmutable
    {
        $data = \is_string($valor) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw ErroDaFerramenta::filtroInvalido($campo);
        }

        return $data;
    }

    /**
     * @return list<string>
     *
     * @throws ErroDaFerramenta
     */
    private static function incluir(mixed $valor): array
    {
        if (!\is_array($valor) || $valor === [] || !array_is_list($valor)) {
            throw ErroDaFerramenta::filtroInvalido('incluir');
        }
        foreach ($valor as $parte) {
            if (!\is_string($parte) || !\in_array($parte, self::INCLUIR, true)) {
                throw ErroDaFerramenta::filtroInvalido('incluir');
            }
        }

        return array_values(array_unique($valor));
    }
}
