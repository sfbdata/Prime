<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\CenarioDaAssembleia;

/**
 * A cédula da unidade (`/votar/{token}`): a assembleia, a unidade e as pautas na ordem. Nada do escritório além do
 * título da assembleia. `simulacao`: a assembleia é de treino, e a cabine mostra a faixa "votação de treino, sem validade".
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
        public string $email = '',
        /** Nome da entidade (condomínio, associação...) que promove a votação; vazio se não informado. */
        public string $entidade = '',
        /** O cenário só rotula (identificação da unidade, modalidade): nunca entra no hash. */
        public CenarioDaAssembleia $cenario = CenarioDaAssembleia::Condominio,
        public bool $simulacao = false,
        /** A última ocorrência da unidade, mostrada só à unidade não apta (desenho 18, H449); null sem ocorrência. */
        public ?string $ocorrencia = null,
        /** A ocorrência tem avaliação pendente: "(em avaliação pelo conselho)". */
        public bool $ocorrenciaEmAvaliacao = false,
    ) {
    }

    /** Alguma pauta ainda pode mudar sem ação do morador (abrir, encerrar): a página se atualiza sozinha. */
    public function esperaAMesa(): bool
    {
        foreach ($this->pautas as $pauta) {
            if ($pauta->aguardando() || $pauta->suspensa() || ($pauta->aberta() && (null !== $pauta->votouEm || !$this->apta))) {
                return true;
            }
        }

        return false;
    }
}
