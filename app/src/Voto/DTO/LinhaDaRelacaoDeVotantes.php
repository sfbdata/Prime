<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma linha da relação de votantes da ata (desenho 18, `relacaoVotantes` e `linhasVot`): nome, identificação (o rótulo
 * do cenário) e situação. Quem votou traz só a HORA CHEIA do voto (a faixa horária "14h às 15h"), nunca o minuto nem o
 * segundo, e nunca em quê (E237 do ESPEC: a hora exata, cruzada com a ordem da urna, aproximaria voto e pessoa).
 */
final readonly class LinhaDaRelacaoDeVotantes
{
    public const VOTOU = 'votou';
    public const NAO_VOTOU = 'nao_votou';
    public const INAPTO = 'inapto';

    /** @param self::VOTOU|self::NAO_VOTOU|self::INAPTO $situacao */
    public function __construct(
        public string $nome,
        public string $identificacao,
        public string $situacao,
        public ?\DateTimeImmutable $faixa = null,
    ) {
    }
}
