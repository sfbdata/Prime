<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use App\AssinaturaEletronica\Service\LeitorDeJsonEstrito;

/**
 * Uma assinatura como o validador de cadeia a conferiu (contrato da etapa 6, seção 2, protocolo `v1`). Casa com a
 * assinatura do motor pela `faixa` (o `/ByteRange`). Titular e CPF aqui também são o que o certificado diz; passam a
 * valer só com a regra da E6-6 satisfeita (cadeia `confiavel` com a âncora do nível, revogação `boa`...), que é de quem
 * chama.
 *
 * Sem carimbo do tempo, o validador manda `{"presente":false,"valido":false,"hora":null}`. O `motivo` é um código do
 * validador (nulo quando nada falhou), nunca texto para a tela. A `hora` do carimbo fica como veio (ISO 8601), para a
 * volta ao JSON gravar o mesmo texto; {@see horaDoCarimbo()} a lê como data.
 *
 * A `faixa` vem nula quando o validador não leu o `/ByteRange` da assinatura (o item `assinatura_ilegivel`): o item fica
 * na resposta, nunca casa com uma assinatura do motor e é sempre não íntegro (`intacta` falso, mesmo que venha
 * verdadeiro).
 */
final readonly class AssinaturaDaCadeia
{
    /** Íntegra para o validador; sempre falso sem a faixa. */
    public bool $intacta;

    /**
     * @param list<int>|null                           $faixa  os quatro números do `/ByteRange`; nula se o validador não
     *                                                         o leu
     * @param list<array{cn: ?string, sha256: string}> $cadeia do signatário até a raiz
     */
    public function __construct(
        public ?string $campo,
        public ?array $faixa,
        bool $intacta,
        public bool $valida,
        public bool $confiavel,
        /** `icp` ou `govbr` quando a cadeia chegou a uma âncora; nulo sem âncora */
        public ?string $ancora,
        public ?string $titularCn,
        /** 11 dígitos, declarado pelo certificado */
        public ?string $titularCpf,
        /** 14 dígitos, declarado pelo certificado */
        public ?string $titularCnpj,
        public array $cadeia,
        public RevogacaoDaCadeia $revogacao,
        public bool $carimboPresente,
        public bool $carimboValido,
        public ?string $carimboHora,
        public CoberturaDaCadeia $cobertura,
        public ModificacaoDaCadeia $nivelDeModificacao,
        public ?bool $docmdpOk,
        public ?string $motivo,
    ) {
        $this->intacta = $faixa !== null && $intacta;
    }

    /**
     * @param array<mixed> $json um item de `assinaturas`
     *
     * @throws \UnexpectedValueException item fora do protocolo
     */
    public static function deJson(array $json): self
    {
        $titular = LeitorDeJsonEstrito::objeto($json, 'titular');
        $carimbo = LeitorDeJsonEstrito::objeto($json, 'carimbo');

        return new self(
            campo: LeitorDeJsonEstrito::textoOuNulo($json, 'campo'),
            faixa: self::faixa($json),
            intacta: LeitorDeJsonEstrito::booleano($json, 'intacta'),
            valida: LeitorDeJsonEstrito::booleano($json, 'valida'),
            confiavel: LeitorDeJsonEstrito::booleano($json, 'confiavel'),
            ancora: self::ancora($json),
            titularCn: LeitorDeJsonEstrito::textoOuNulo($titular, 'cn'),
            titularCpf: self::digitos($titular, 'cpf', 11),
            titularCnpj: self::digitos($titular, 'cnpj', 14),
            cadeia: array_map(self::certificado(...), LeitorDeJsonEstrito::lista($json, 'cadeia')),
            revogacao: LeitorDeJsonEstrito::enum($json, 'revogacao', RevogacaoDaCadeia::class),
            carimboPresente: LeitorDeJsonEstrito::booleano($carimbo, 'presente'),
            carimboValido: LeitorDeJsonEstrito::booleano($carimbo, 'valido'),
            carimboHora: self::hora($carimbo),
            cobertura: LeitorDeJsonEstrito::enum($json, 'cobertura', CoberturaDaCadeia::class),
            nivelDeModificacao: LeitorDeJsonEstrito::enum($json, 'nivel_de_modificacao', ModificacaoDaCadeia::class),
            docmdpOk: LeitorDeJsonEstrito::booleanoOuNulo($json, 'docmdp_ok'),
            motivo: LeitorDeJsonEstrito::textoOuNulo($json, 'motivo'),
        );
    }

    /** @return array<string, mixed> o item como o protocolo o escreve (vai para a coluna `cadeia`, E6-7) */
    public function paraJson(): array
    {
        return [
            'campo' => $this->campo,
            'faixa' => $this->faixa,
            'intacta' => $this->intacta,
            'valida' => $this->valida,
            'confiavel' => $this->confiavel,
            'ancora' => $this->ancora,
            'titular' => ['cn' => $this->titularCn, 'cpf' => $this->titularCpf, 'cnpj' => $this->titularCnpj],
            'cadeia' => $this->cadeia,
            'revogacao' => $this->revogacao->value,
            'carimbo' => ['presente' => $this->carimboPresente, 'valido' => $this->carimboValido, 'hora' => $this->carimboHora],
            'cobertura' => $this->cobertura->value,
            'nivel_de_modificacao' => $this->nivelDeModificacao->value,
            'docmdp_ok' => $this->docmdpOk,
            'motivo' => $this->motivo,
        ];
    }

    public function horaDoCarimbo(): ?\DateTimeImmutable
    {
        return $this->carimboHora === null ? null : new \DateTimeImmutable($this->carimboHora);
    }

    /** @param list<int> $faixa */
    public function temAFaixa(array $faixa): bool
    {
        return $faixa === $this->faixa;
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<int>|null
     */
    private static function faixa(array $json): ?array
    {
        if (\array_key_exists('faixa', $json) && $json['faixa'] === null) {
            return null;
        }
        $faixa = LeitorDeJsonEstrito::listaDeInteiros($json, 'faixa');
        if (\count($faixa) !== 4 || min($faixa) < 0) {
            throw new \UnexpectedValueException('A faixa da assinatura não tem quatro números não negativos.');
        }

        return $faixa;
    }

    /** @param array<mixed> $json */
    private static function ancora(array $json): ?string
    {
        $ancora = LeitorDeJsonEstrito::textoOuNulo($json, 'ancora');
        if ($ancora !== null && $ancora !== ValidadorDeCadeia::ANCORA_ICP && $ancora !== ValidadorDeCadeia::ANCORA_GOVBR) {
            throw new \UnexpectedValueException('Âncora da assinatura fora do protocolo.');
        }

        return $ancora;
    }

    /** @param array<mixed> $json */
    private static function digitos(array $json, string $campo, int $quantos): ?string
    {
        $valor = LeitorDeJsonEstrito::textoOuNulo($json, $campo);
        if ($valor !== null && preg_match('/^\d{' . $quantos . '}$/', $valor) !== 1) {
            throw new \UnexpectedValueException(sprintf('O %s do titular não tem %d dígitos.', $campo, $quantos));
        }

        return $valor;
    }

    /**
     * @param array<mixed> $json
     */
    private static function hora(array $json): ?string
    {
        $hora = LeitorDeJsonEstrito::textoOuNulo($json, 'hora');
        $formato = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';
        if ($hora !== null && (preg_match($formato, $hora) !== 1 || self::dataImpossivel($hora))) {
            throw new \UnexpectedValueException('A hora do carimbo do tempo não está em ISO 8601.');
        }

        return $hora;
    }

    /** Erro ou aviso do analisador de datas (31 de fevereiro, 25 horas...). */
    private static function dataImpossivel(string $hora): bool
    {
        $analise = date_parse($hora);

        return $analise['error_count'] > 0 || $analise['warning_count'] > 0;
    }

    /** @return array{cn: ?string, sha256: string} */
    private static function certificado(mixed $item): array
    {
        if (!\is_array($item)) {
            throw new \UnexpectedValueException('Item da cadeia que não é objeto.');
        }
        $sha256 = LeitorDeJsonEstrito::texto($item, 'sha256');
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new \UnexpectedValueException('Impressão digital da cadeia fora do formato (64 hexadecimais minúsculos).');
        }

        return ['cn' => LeitorDeJsonEstrito::textoOuNulo($item, 'cn'), 'sha256' => $sha256];
    }
}
