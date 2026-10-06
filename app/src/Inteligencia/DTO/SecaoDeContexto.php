<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\SecaoDoContexto;

/**
 * Uma seção do contexto da pasta já preparada para o prompt: as linhas (texto plano, neutralizado,
 * mascarado, truncado) e quantas ficaram de fora pelo limite — o corte é declarado ao modelo e
 * gravado em `contexto_resumo`, nunca silencioso.
 *
 * `assinaturas` é a forma ESTÁVEL de cada linha (ids, datas absolutas, estado, conteúdo), uma por
 * linha, para o `contexto_hash`: a linha do prompt pode dizer "vence em 3 dia(s)", que muda todo
 * dia sem o dado mudar; a assinatura não. Sem assinaturas (testes de prompt), o hash usa as linhas.
 */
final readonly class SecaoDeContexto
{
    /**
     * @param list<string> $linhas
     * @param list<string> $assinaturas
     */
    public function __construct(
        public SecaoDoContexto $secao,
        public array $linhas,
        public int $omitidas = 0,
        public array $assinaturas = [],
    ) {
    }

    /** @return list<string> */
    public function assinaturasParaHash(): array
    {
        return $this->assinaturas === [] ? $this->linhas : $this->assinaturas;
    }

    public function vazia(): bool
    {
        return $this->linhas === [];
    }

    public function total(): int
    {
        return count($this->linhas);
    }
}
