<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\CodigoDaFolha;

/**
 * O que o aceite mensal da folha atesta, na forma canônica que o HMAC sela (spec ponto-desenho-0811, E16, decisão 14):
 * o escritório, a pessoa, a competência e o número do aceite, o código BJF da folha emitida vista, a situação
 * (`concordo` ou `ressalva`), o texto da ressalva (nulo no concordo), quando foi aceito, o IP e o navegador.
 *
 * A forma canônica é um JSON com as chaves sempre na mesma ordem, sem escapar acentos nem barras, como
 * {@see DadosDaFolhaEmitida}: o mesmo dado gera sempre o mesmo texto, então o HMAC gravado confere enquanto nada mudar.
 * A folha emitida e o aceite são selados com a mesma chave (finalidade `ponto-folha`); o marcador `tipo` é o que separa
 * um do outro, e o leitor de cada um recusa o texto do outro. O leitor só aceita o formato e o tipo que conhece, com as
 * chaves e os tipos certos e a ressalva coerente com a situação; o resto é recusado.
 */
final readonly class DadosDoAceiteDaFolha
{
    public const FORMATO = 1;
    public const TIPO = 'aceite-folha';
    private const CHAVES = ['formato', 'tipo', 'escritorio_id', 'pessoa_id', 'ano', 'mes', 'numero', 'codigo_folha_vista', 'situacao', 'ressalva', 'aceito_em', 'ip', 'navegador'];

    public function __construct(
        public int $escritorioId,
        public int $pessoaId,
        public int $ano,
        public int $mes,
        public int $numero,
        public string $codigoDaFolhaVista,
        public SituacaoDoAceiteDaFolha $situacao,
        public ?string $ressalva,
        public \DateTimeImmutable $aceitoEm,
        public ?string $ip,
        public ?string $navegador,
    ) {
    }

    /**
     * Os dados de um aceite novo sobre a folha emitida vista, montados antes da entidade (o selo vem antes da linha).
     * Escritório, pessoa e competência saem da folha vista, como na entidade. Os valores entram como chegam: quem grava
     * corta o navegador e apara a ressalva antes de montar.
     *
     * @throws \LogicException folha vista de escritório ou pessoa ainda sem id
     */
    public static function daAssinatura(FolhaEmitidaDoPonto $folhaVista, int $numero, SituacaoDoAceiteDaFolha $situacao, ?string $ressalva, \DateTimeImmutable $aceitoEm, ?string $ip, ?string $navegador): self
    {
        $escritorioId = $folhaVista->getTenant()?->getId() ?? throw new \LogicException('O aceite sai de uma folha de escritório já gravado.');
        $pessoaId = $folhaVista->getUser()->getId() ?? throw new \LogicException('O aceite sai de uma folha de pessoa já gravada.');

        return new self($escritorioId, $pessoaId, $folhaVista->getAno(), $folhaVista->getMes(), $numero, $folhaVista->getCodigo(), $situacao, $ressalva, $aceitoEm, $ip, $navegador);
    }

    public function canonico(): string
    {
        return json_encode(array_combine(self::CHAVES, [
            self::FORMATO, self::TIPO, $this->escritorioId, $this->pessoaId, $this->ano, $this->mes, $this->numero,
            $this->codigoDaFolhaVista, $this->situacao->value, $this->ressalva, $this->aceitoEm->format(\DATE_ATOM),
            $this->ip, $this->navegador,
        ]), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws \UnexpectedValueException quando o texto não é a forma canônica deste formato e deste tipo (outro número,
     *                                   outro tipo, outras chaves, tipos trocados, ressalva incoerente com a situação)
     */
    public static function deCanonico(string $canonico): self
    {
        $dados = json_decode($canonico, true);
        if (!is_array($dados) || array_keys($dados) !== self::CHAVES || $dados['formato'] !== self::FORMATO || $dados['tipo'] !== self::TIPO) {
            throw new \UnexpectedValueException('Dados do aceite da folha fora do formato canônico.');
        }
        self::exigirTipos($dados);
        $situacao = self::situacaoDe($dados['situacao'], $dados['ressalva']);

        return new self(
            $dados['escritorio_id'],
            $dados['pessoa_id'],
            $dados['ano'],
            $dados['mes'],
            $dados['numero'],
            $dados['codigo_folha_vista'],
            $situacao,
            $dados['ressalva'],
            self::dataDe($dados['aceito_em']),
            $dados['ip'],
            $dados['navegador'],
        );
    }

    /**
     * @param array<mixed> $dados
     *
     * @throws \UnexpectedValueException
     */
    private static function exigirTipos(array $dados): void
    {
        $certos = self::idValido($dados['escritorio_id']) && self::idValido($dados['pessoa_id']) && self::idValido($dados['numero'])
            && is_int($dados['ano']) && $dados['ano'] >= 1000 && $dados['ano'] <= 9999
            && is_int($dados['mes']) && $dados['mes'] >= 1 && $dados['mes'] <= 12
            && is_string($dados['codigo_folha_vista']) && CodigoDaFolha::temFormato($dados['codigo_folha_vista'])
            && is_string($dados['situacao']) && is_string($dados['aceito_em'])
            && self::textoOuNulo($dados['ressalva']) && self::textoOuNulo($dados['ip']) && self::textoOuNulo($dados['navegador']);
        if (!$certos) {
            throw new \UnexpectedValueException('Dados do aceite da folha com tipos fora do formato canônico.');
        }
    }

    private static function idValido(mixed $valor): bool
    {
        return is_int($valor) && $valor >= 1;
    }

    private static function textoOuNulo(mixed $valor): bool
    {
        return $valor === null || is_string($valor);
    }

    /**
     * A situação conhecida e a ressalva coerente com ela, pela mesma regra da entidade: texto não vazio e com até 1.000
     * caracteres na ressalva, nulo no concordo.
     *
     * @throws \UnexpectedValueException
     */
    private static function situacaoDe(string $valor, ?string $ressalva): SituacaoDoAceiteDaFolha
    {
        $situacao = SituacaoDoAceiteDaFolha::tryFrom($valor) ?? throw new \UnexpectedValueException('Situação do aceite da folha desconhecida.');
        try {
            AceiteDaFolhaDoPonto::exigirRessalvaCoerente($situacao, $ressalva);
        } catch (\DomainException $incoerente) {
            throw new \UnexpectedValueException('Ressalva do aceite da folha incoerente com a situação.', 0, $incoerente);
        }

        return $situacao;
    }

    /** A data em DATE_ATOM, exatamente como a forma canônica escreve (sem data que o PHP "acerta" sozinho). */
    private static function dataDe(string $iso): \DateTimeImmutable
    {
        $data = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $iso);
        if ($data === false || $data->format(\DATE_ATOM) !== $iso) {
            throw new \UnexpectedValueException('Data do aceite da folha inválida.');
        }

        return $data;
    }
}
