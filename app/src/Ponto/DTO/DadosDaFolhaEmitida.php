<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;

/**
 * O que a folha emitida atesta, na forma canônica que o HMAC sela (spec ponto-desenho-0811, E16, decisões 2, 3, 17 e
 * 21): o código BJF, a competência, o escritório (id, nome e CNPJ), a pessoa (id e nome inteiro), quando e por quem foi
 * emitida, a impressão dos registros (sha256 do resumo dia a dia e dos totais, decisão 3), o próprio resumo (dias e
 * totais, montado por quem emite e guardado aqui como está) e o aceite impresso no PDF (situação, data e ressalva) ou
 * nulo. Sem CPF.
 *
 * A validação (janela e página pública) mostra só {@see self::textoDaValidacao()}, no molde do comprovante e com o
 * texto do desenho (L803): "Folha de Ponto 11/2026 de NOME em dd/mm/aaaa, HH:MM:SS.", debaixo do "Comprovante
 * autêntico." (decisão 17). A ressalva e o resumo ficam fora da página aberta.
 *
 * A forma canônica é um JSON com as chaves sempre na mesma ordem, sem escapar acentos nem barras: o mesmo dado gera
 * sempre o mesmo texto, então o HMAC gravado confere enquanto nada mudar. O número do formato vai junto: o leitor só
 * aceita o formato que conhece, e o resto não vira folha autêntica ("Código não localizado." na validação). O resumo
 * entra no JSON como chegou: listas e mapas de texto, inteiros e booleanos (sem mapa vazio, que volta como lista).
 *
 * A folha e o aceite dela ({@see DadosDoAceiteDaFolha}) são selados com a mesma chave (finalidade `ponto-folha`): o
 * marcador `tipo` é o que separa um do outro, e o leitor de cada um recusa o texto do outro.
 */
final readonly class DadosDaFolhaEmitida
{
    public const FORMATO = 1;
    public const TIPO = 'folha';
    private const CHAVES = ['formato', 'tipo', 'codigo', 'competencia', 'escritorio_id', 'pessoa_id', 'pessoa', 'escritorio', 'cnpj', 'emitida_em', 'emitida_por_id', 'emitida_por', 'impressao_registros', 'resumo', 'aceite'];
    private const CHAVES_DO_ACEITE = ['situacao', 'em', 'ressalva'];
    private const IMPRESSAO = '/^[0-9a-f]{64}$/';

    /**
     * @param array<mixed> $resumo os dias e os totais da folha, como quem emite montou (o leitor não interpreta)
     *
     * @throws \InvalidArgumentException aceite pela metade (situação sem data, ou data ou ressalva sem situação)
     */
    public function __construct(
        public string $codigo,
        public int $ano,
        public int $mes,
        public int $escritorioId,
        public int $pessoaId,
        public string $pessoa,
        public string $escritorio,
        public ?string $cnpj,
        public \DateTimeImmutable $emitidaEm,
        public ?int $emitidaPorId,
        public ?string $emitidaPor,
        public string $impressaoRegistros,
        public array $resumo,
        public ?SituacaoDoAceiteDaFolha $aceiteSituacao = null,
        public ?\DateTimeImmutable $aceiteEm = null,
        public ?string $aceiteRessalva = null,
    ) {
        if (($aceiteSituacao === null) !== ($aceiteEm === null) || ($aceiteSituacao === null && $aceiteRessalva !== null)) {
            throw new \InvalidArgumentException('O aceite impresso na folha vem inteiro (situação e data) ou não vem.');
        }
    }

    /**
     * Os dados de uma emissão nova. O nome é o nome completo (sem nome, vazio: nunca o e-mail ou outro dado pessoal).
     *
     * @param array<mixed> $resumo
     *
     * @throws \LogicException escritório ou pessoa ainda sem id, ou aceite de outra pessoa, escritório ou competência
     */
    public static function daEmissao(string $codigo, Tenant $escritorio, User $pessoa, int $ano, int $mes, \DateTimeImmutable $emitidaEm, ?User $emitidaPor, string $impressaoRegistros, array $resumo, ?AceiteDaFolhaDoPonto $aceite = null): self
    {
        $escritorioId = $escritorio->getId() ?? throw new \LogicException('A folha emitida sai de um escritório já gravado.');
        $pessoaId = $pessoa->getId() ?? throw new \LogicException('A folha emitida sai de uma pessoa já gravada.');
        if ($aceite !== null && ($aceite->getTenant() !== $escritorio || $aceite->getUser() !== $pessoa || $aceite->getAno() !== $ano || $aceite->getMes() !== $mes)) {
            throw new \LogicException('O aceite impresso é da mesma pessoa, escritório e competência da folha.');
        }

        return new self(
            $codigo,
            $ano,
            $mes,
            $escritorioId,
            $pessoaId,
            self::nomeDe($pessoa),
            trim((string) $escritorio->getName()),
            $escritorio->getCnpj(),
            $emitidaEm,
            $emitidaPor?->getId(),
            $emitidaPor === null ? null : self::nomeDe($emitidaPor),
            $impressaoRegistros,
            $resumo,
            $aceite?->getSituacao(),
            $aceite?->getAceitoEm(),
            $aceite?->getRessalva(),
        );
    }

    public function canonico(): string
    {
        return json_encode(array_combine(self::CHAVES, [
            self::FORMATO, self::TIPO, $this->codigo, sprintf('%04d-%02d', $this->ano, $this->mes), $this->escritorioId,
            $this->pessoaId, $this->pessoa, $this->escritorio, $this->cnpj, $this->emitidaEm->format(\DATE_ATOM),
            $this->emitidaPorId, $this->emitidaPor, $this->impressaoRegistros, $this->resumo, $this->aceiteCanonico(),
        ]), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /** @throws \UnexpectedValueException quando o texto não é a forma canônica deste formato e deste tipo (outro número, outro tipo, outras chaves, tipos trocados) */
    public static function deCanonico(string $canonico): self
    {
        $dados = json_decode($canonico, true);
        if (!is_array($dados) || array_keys($dados) !== self::CHAVES || $dados['formato'] !== self::FORMATO || $dados['tipo'] !== self::TIPO) {
            throw new \UnexpectedValueException('Dados da folha fora do formato canônico.');
        }
        self::exigirTipos($dados);
        [$ano, $mes] = self::competenciaDe($dados['competencia']);
        [$situacao, $aceitoEm, $ressalva] = self::aceiteDe($dados['aceite']) ?? [null, null, null];

        return new self(
            $dados['codigo'],
            $ano,
            $mes,
            $dados['escritorio_id'],
            $dados['pessoa_id'],
            $dados['pessoa'],
            $dados['escritorio'],
            $dados['cnpj'],
            self::dataDe($dados['emitida_em']),
            $dados['emitida_por_id'],
            $dados['emitida_por'],
            $dados['impressao_registros'],
            $dados['resumo'],
            $situacao,
            $aceitoEm,
            $ressalva,
        );
    }

    /** "11/2026", como a folha do desenho (L727). */
    public function competenciaFormatada(): string
    {
        return sprintf('%02d/%04d', $this->mes, $this->ano);
    }

    /** "Folha de Ponto 11/2026 de NOME em dd/mm/aaaa, HH:MM:SS." (o `valTxt` do desenho 16 L803, sem NSR). */
    public function textoDaValidacao(): string
    {
        return sprintf('Folha de Ponto %s de %s em %s.', $this->competenciaFormatada(), $this->pessoa, $this->emitidaEm->format('d/m/Y, H:i:s'));
    }

    private static function nomeDe(User $usuario): string
    {
        return trim((string) $usuario->getFullName());
    }

    /** @return array{situacao: string, em: string, ressalva: ?string}|null */
    private function aceiteCanonico(): ?array
    {
        if ($this->aceiteSituacao === null || $this->aceiteEm === null) {
            return null;
        }

        return array_combine(self::CHAVES_DO_ACEITE, [$this->aceiteSituacao->value, $this->aceiteEm->format(\DATE_ATOM), $this->aceiteRessalva]);
    }

    /**
     * @param array<mixed> $dados
     *
     * @throws \UnexpectedValueException
     */
    private static function exigirTipos(array $dados): void
    {
        $certos = is_string($dados['codigo']) && is_int($dados['escritorio_id']) && is_int($dados['pessoa_id']) && is_string($dados['pessoa'])
            && is_string($dados['escritorio']) && ($dados['cnpj'] === null || is_string($dados['cnpj']))
            && ($dados['emitida_por_id'] === null || is_int($dados['emitida_por_id']))
            && ($dados['emitida_por'] === null || is_string($dados['emitida_por']))
            && is_string($dados['impressao_registros']) && preg_match(self::IMPRESSAO, $dados['impressao_registros']) === 1
            && is_array($dados['resumo']) && is_string($dados['competencia']) && is_string($dados['emitida_em']);
        if (!$certos) {
            throw new \UnexpectedValueException('Dados da folha com tipos fora do formato canônico.');
        }
    }

    /**
     * @return array{int, int}
     *
     * @throws \UnexpectedValueException
     */
    private static function competenciaDe(string $competencia): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $competencia, $partes) !== 1 || (int) $partes[2] < 1 || (int) $partes[2] > 12) {
            throw new \UnexpectedValueException('Competência da folha inválida.');
        }

        return [(int) $partes[1], (int) $partes[2]];
    }

    /** @throws \UnexpectedValueException */
    private static function dataDe(mixed $iso): \DateTimeImmutable
    {
        $data = is_string($iso) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $iso) : false;
        if ($data === false) {
            throw new \UnexpectedValueException('Data da folha inválida.');
        }

        return $data;
    }

    /**
     * @return array{SituacaoDoAceiteDaFolha, \DateTimeImmutable, ?string}|null
     *
     * @throws \UnexpectedValueException
     */
    private static function aceiteDe(mixed $aceite): ?array
    {
        if ($aceite === null) {
            return null;
        }
        $situacao = is_array($aceite) && array_keys($aceite) === self::CHAVES_DO_ACEITE && is_string($aceite['situacao'])
            ? SituacaoDoAceiteDaFolha::tryFrom($aceite['situacao'])
            : null;
        if ($situacao === null || ($aceite['ressalva'] !== null && !is_string($aceite['ressalva']))) {
            throw new \UnexpectedValueException('Aceite impresso na folha fora do formato canônico.');
        }

        return [$situacao, self::dataDe($aceite['em']), $aceite['ressalva']];
    }
}
