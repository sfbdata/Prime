<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;

/**
 * Filtro das ferramentas de leitura do Agente de Pastas (`buscar_pastas`/`contar_pastas`), traduzido
 * para as MESMAS chaves que a Lista do Expediente manda ao `PastaRepository`
 * (`ExpedienteController::filtrosDaRequest`) — o agente enxerga o que a Lista enxerga, com a mesma
 * semântica (`docs/specs/designer-central-ia.md` §3).
 *
 * Por isso não há `cliente` nem `acao`, que o desenho do Designer usa: a Lista não manda essas chaves, e
 * os ramos que o repositório tem para elas não ignoram acento nem caixa (`acao=cobrança` não acharia
 * "COBRANÇA"). O texto livre é a `busca` da Lista, que cobre identificador, cliente, credor/devedor,
 * ação, classe, número e assunto do processo.
 *
 * Tudo que o repositório ignoraria em silêncio é recusado aqui (ver {@see FiltroDoAgenteInvalidoException}).
 */
final class FiltroDoAgenteInput
{
    public const TETO_DE_NUPS = 500;
    public const TETO_DE_PAGINA = 10_000;

    private const CHAVES_DE_FILTRO = ['busca', 'responsavel', 'marcador', 'situacao', 'prioridade', 'desde', 'ate', 'nups'];
    private const CHAVES_DE_CONTROLE = ['pagina', 'contar'];
    private const CHAVES_SUBSTITUIDAS_PELA_BUSCA = ['cliente', 'acao'];

    /**
     * @param list<string> $nups
     */
    private function __construct(
        public readonly string $busca,
        public readonly ?int $responsavelId,
        public readonly ?int $marcadorId,
        public readonly string $situacao,
        public readonly string $prioridade,
        public readonly string $desde,
        public readonly string $ate,
        public readonly array $nups,
        public readonly int $pagina,
        public readonly bool $contar,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @throws FiltroDoAgenteInvalidoException
     */
    public static function daQuery(array $query): self
    {
        foreach ($query as $chave => $valor) {
            self::conferirChave((string) $chave, $valor);
        }

        $texto = static fn (string $chave): string => trim((string) ($query[$chave] ?? ''));

        $desde = self::data($texto('desde'), 'desde');
        $ate   = self::data($texto('ate'), 'ate');
        if ($desde !== '' && $ate !== '' && $ate < $desde) {
            throw new FiltroDoAgenteInvalidoException('ate', 'O fim do período é anterior ao início.');
        }

        $filtro = new self(
            busca: $texto('busca'),
            responsavelId: self::id($texto('responsavel'), 'responsavel'),
            marcadorId: self::id($texto('marcador'), 'marcador'),
            situacao: self::situacao($texto('situacao')),
            prioridade: self::prioridade($texto('prioridade')),
            desde: $desde,
            ate: $ate,
            nups: self::nups($texto('nups')),
            pagina: self::pagina($texto('pagina')),
            contar: self::contar($texto('contar')),
        );

        if ($filtro->nups !== [] && $filtro->temOutroFiltroAlemDosNups()) {
            throw new FiltroDoAgenteInvalidoException('nups', 'A lista de pastas não combina com outros filtros.');
        }

        return $filtro;
    }

    /**
     * As chaves da Lista do Expediente — o marcador e os NUPs não estão aqui porque têm consulta
     * própria.
     *
     * @return array<string, string>
     */
    public function paraRepositorio(): array
    {
        return array_filter([
            'busca'       => $this->busca,
            'responsavel' => $this->responsavelId !== null ? (string) $this->responsavelId : '',
            'status'      => $this->situacao,
            'prioridade'  => $this->prioridade,
            'data_de'     => $this->desde,
            'data_ate'    => $this->ate,
        ], static fn (string $valor): bool => $valor !== '');
    }

    private function temOutroFiltroAlemDosNups(): bool
    {
        return $this->paraRepositorio() !== [] || $this->marcadorId !== null;
    }

    /**
     * Chave desconhecida e valor em forma de lista (`?nups[]=1`) são recusados: os dois passariam
     * calados, e o agente receberia o acervo inteiro achando que filtrou.
     *
     * @throws FiltroDoAgenteInvalidoException
     */
    private static function conferirChave(string $chave, mixed $valor): void
    {
        if (in_array($chave, self::CHAVES_SUBSTITUIDAS_PELA_BUSCA, true)) {
            throw new FiltroDoAgenteInvalidoException($chave, sprintf('Use "busca" (a mesma busca da Lista do Expediente) em vez de "%s".', $chave));
        }
        if (!in_array($chave, self::CHAVES_DE_FILTRO, true) && !in_array($chave, self::CHAVES_DE_CONTROLE, true)) {
            throw new FiltroDoAgenteInvalidoException($chave, sprintf('Filtro "%s" não existe.', $chave));
        }
        if (!is_scalar($valor)) {
            throw new FiltroDoAgenteInvalidoException($chave, sprintf('"%s" precisa ser um valor só (lista de NUPs vai separada por vírgula).', $chave));
        }
    }

    private static function id(string $valor, string $campo): ?int
    {
        if ($valor === '') {
            return null;
        }
        if (!ctype_digit($valor) || strlen($valor) > 9 || (int) $valor < 1) {
            throw new FiltroDoAgenteInvalidoException($campo, sprintf('"%s" precisa ser o id numérico (veja /api/agente/opcoes).', $campo));
        }

        return (int) $valor;
    }

    private static function situacao(string $valor): string
    {
        if ($valor === '' || in_array($valor, [Pasta::SITUACAO_ATIVA, Pasta::SITUACAO_ARQUIVADA], true)) {
            return $valor;
        }

        throw new FiltroDoAgenteInvalidoException('situacao', sprintf('Situação "%s" não existe (use ativo ou arquivado).', $valor));
    }

    private static function prioridade(string $valor): string
    {
        if ($valor === '' || PrioridadePasta::tryFrom($valor) !== null) {
            return $valor;
        }

        throw new FiltroDoAgenteInvalidoException('prioridade', sprintf('Prioridade "%s" não existe (use normal, prioridade ou urgente).', $valor));
    }

    private static function data(string $valor, string $campo): string
    {
        if ($valor === '') {
            return '';
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new FiltroDoAgenteInvalidoException($campo, sprintf('"%s" precisa ser uma data AAAA-MM-DD válida.', $campo));
        }

        return $valor;
    }

    /** @return list<string> */
    private static function nups(string $valor): array
    {
        $nups = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $valor)),
            static fn (string $nup): bool => $nup !== '',
        )));

        if (count($nups) > self::TETO_DE_NUPS) {
            throw new FiltroDoAgenteInvalidoException('nups', sprintf('No máximo %d pastas por consulta.', self::TETO_DE_NUPS));
        }

        return $nups;
    }

    private static function pagina(string $valor): int
    {
        if ($valor === '') {
            return 1;
        }
        if (!ctype_digit($valor) || strlen($valor) > 5 || (int) $valor < 1 || (int) $valor > self::TETO_DE_PAGINA) {
            throw new FiltroDoAgenteInvalidoException('pagina', sprintf('A página vai de 1 a %d.', self::TETO_DE_PAGINA));
        }

        return (int) $valor;
    }

    private static function contar(string $valor): bool
    {
        if ($valor === '' || $valor === '1') {
            return $valor === '1';
        }

        throw new FiltroDoAgenteInvalidoException('contar', 'Use contar=1 (ou não mande).');
    }
}
