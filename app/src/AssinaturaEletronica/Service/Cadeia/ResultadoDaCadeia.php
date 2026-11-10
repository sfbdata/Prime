<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use App\AssinaturaEletronica\Service\LeitorDeJsonEstrito;

/**
 * A resposta do validador de cadeia (contrato da etapa 6, seção 2, protocolo `v1`), imutável: as assinaturas
 * conferidas (`200 {"v":1,"assinaturas":[...]}`) ou o erro do PDF (`400 {"v":1,"erro":"pdf_ilegivel"}`). Os demais
 * erros do protocolo (`401`, `413`, `503`) viram {@see CadeiaIndisponivel} no cliente, não resultado.
 *
 * {@see paraJson()} é o que vai para a coluna `cadeia` da versão (E6-7) e volta igual por {@see deJson()}.
 */
final readonly class ResultadoDaCadeia
{
    public const VERSAO = 1;
    public const ERRO_PDF_ILEGIVEL = 'pdf_ilegivel';

    /**
     * @param list<AssinaturaDaCadeia> $assinaturas
     * @param string|null              $erro        o código do erro do validador; com ele, nenhuma assinatura
     */
    public function __construct(
        public array $assinaturas,
        public ?string $erro = null,
    ) {
        if ($erro !== null && $assinaturas !== []) {
            throw new \LogicException('Resultado da cadeia com erro não traz assinaturas.');
        }
    }

    /**
     * @param array<mixed> $json o corpo da resposta, já decodificado
     *
     * @throws \UnexpectedValueException versão desconhecida ou corpo fora do protocolo (o cliente trata como
     *                                   indisponível)
     */
    public static function deJson(array $json): self
    {
        if (LeitorDeJsonEstrito::inteiro($json, 'v') !== self::VERSAO) {
            throw new \UnexpectedValueException('Versão desconhecida do protocolo do validador de cadeia.');
        }
        if (\array_key_exists('erro', $json)) {
            $erro = LeitorDeJsonEstrito::texto($json, 'erro');

            return $erro !== '' ? new self([], $erro) : throw new \UnexpectedValueException('Erro vazio do validador de cadeia.');
        }

        return new self(array_map(
            static fn (mixed $item): AssinaturaDaCadeia => \is_array($item)
                ? AssinaturaDaCadeia::deJson($item)
                : throw new \UnexpectedValueException('Assinatura da cadeia que não é objeto.'),
            LeitorDeJsonEstrito::lista($json, 'assinaturas'),
        ));
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        if ($this->erro !== null) {
            return ['v' => self::VERSAO, 'erro' => $this->erro];
        }

        return [
            'v' => self::VERSAO,
            'assinaturas' => array_map(static fn (AssinaturaDaCadeia $a): array => $a->paraJson(), $this->assinaturas),
        ];
    }

    /** O PDF que o validador não conseguiu ler. */
    public function ilegivel(): bool
    {
        return $this->erro === self::ERRO_PDF_ILEGIVEL;
    }

    /**
     * A assinatura conferida que casa com a do motor pela faixa de bytes (o `/ByteRange`); nula se o validador não a
     * conferiu.
     *
     * @param list<int> $faixa
     */
    public function daFaixa(array $faixa): ?AssinaturaDaCadeia
    {
        foreach ($this->assinaturas as $assinatura) {
            if ($assinatura->temAFaixa($faixa)) {
                return $assinatura;
            }
        }

        return null;
    }
}
