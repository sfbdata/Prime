<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** Tudo o que o HTML da janela da campanha precisa (`contribua/campanha/_janela.html.twig`). */
final readonly class JanelaDaCampanhaView
{
    /**
     * @param list<string>          $regras
     * @param list<string>          $beneficios
     * @param array<string, string> $urls       inscrever, devolucao
     */
    public function __construct(
        public CampanhaView $campanha,
        public array $regras,
        public array $beneficios,
        /** Só com o contador ligado e a campanha não desligada. */
        public ?int $inscritos,
        public int $vagas,
        public string $nomeSugerido,
        public string $csrf,
        public array $urls,
        /** Só para o Master: o painel da campanha no Contribua. */
        public ?string $urlConfigurar,
        public ?ParticipacaoDaCampanhaView $participacao,
    ) {
    }
}
