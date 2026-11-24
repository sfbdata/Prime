<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Enum\BeneficioDoConvenio;

/**
 * Um convênio que vale hoje para um escritório, como a {@see \App\Master\Service\ConveniosDoEscritorio} o entrega
 * (spec docs/specs/convenios.md, §3.4). `implicito`: o convênio OAB de quem ainda não tem vínculo gravado (decisão de
 * 24/11). `modoUf` e `valorUf`: a exceção `dif` do estado do escritório no convênio OAB, só para a receita estimada.
 */
final class ConvenioVigente
{
    public function __construct(
        public readonly string $chave,
        public readonly string $nome,
        public readonly string $instituicao,
        public readonly BeneficioDoConvenio $beneficio,
        public readonly int $beneficioValor,
        public readonly string $textoSelo,
        public readonly bool $implicito,
        public readonly ?string $modoUf = null,
        public readonly ?float $valorUf = null,
    ) {
    }

    public static function de(Convenio $convenio, bool $implicito, ?ConvenioDaUf $excecaoDaUf = null): self
    {
        return new self(
            $convenio->getChave(),
            $convenio->getNome(),
            $convenio->getInstituicao(),
            $convenio->getBeneficio(),
            $convenio->getBeneficioValor(),
            $convenio->getTextoSelo(),
            $implicito,
            $excecaoDaUf?->getModo(),
            $excecaoDaUf?->getValor(),
        );
    }

    public function ehOab(): bool
    {
        return $this->chave === Convenio::CHAVE_OAB;
    }
}
