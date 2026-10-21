<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

/**
 * O que "Candidatar-se" ou "Aceitar oferta" fez (spec VS 18.10.26 §4.6): se criou a candidatura, se
 * foi aceite de oferta, e o toast do desenho para a resposta.
 */
final readonly class ResultadoDaCandidatura
{
    public function __construct(
        public bool $criou,
        public bool $aceite,
        public string $titulo,
        public string $texto,
        public string $tituloDaVaga,
    ) {
    }

    /** @return array{titulo: string, texto: string} */
    public function toast(): array
    {
        return ['titulo' => $this->titulo, 'texto' => $this->texto];
    }

    /**
     * O toast de "Salvar e candidatar-se" (`salvarCv`, l.1006): "Currículo salvo e candidatura
     * enviada" só quando criou a candidatura comum; no aceite e na repetida, o toast do próprio caso.
     *
     * @return array{titulo: string, texto: string}
     */
    public function toastAposSalvarOCurriculo(): array
    {
        return $this->criou && !$this->aceite
            ? ['titulo' => 'Currículo salvo e candidatura enviada', 'texto' => $this->tituloDaVaga]
            : $this->toast();
    }
}
