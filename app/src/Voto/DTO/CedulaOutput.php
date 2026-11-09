<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * A cédula da unidade (`/votar/{token}`): a assembleia, a unidade e as pautas na ordem. Nada do escritório além do
 * título da assembleia.
 */
final readonly class CedulaOutput
{
    /**
     * @param list<PautaNaCedulaOutput> $pautas pela ordem da assembleia
     * @param bool                      $porFracao o peso do voto é a fração ideal (o resultado mostra "{n} un · {fração}")
     */
    public function __construct(
        public string $assembleia,
        public bool $assembleiaPublicada,
        public string $unidade,
        public string $bloco,
        public bool $apta,
        public bool $porFracao,
        public array $pautas,
    ) {
    }

    /** Alguma pauta ainda pode mudar sem ação do morador (abrir, encerrar): a página se atualiza sozinha. */
    public function esperaAMesa(): bool
    {
        foreach ($this->pautas as $pauta) {
            if ($pauta->aguardando() || ($pauta->aberta() && (null !== $pauta->votouEm || !$this->apta))) {
                return true;
            }
        }

        return false;
    }
}
