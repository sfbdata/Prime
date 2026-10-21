<?php

declare(strict_types=1);

namespace App\Processo\DTO;

/**
 * Os 11 campos que o advogado preenche à mão no cartão do processo (P5b; desenho `PJE_CAMPOS`).
 * Nulo = sem valor manual: o cartão mostra o valor base (Datajud, ou o da pasta no valor da causa)
 * ou "Não informado". `valorCausa` é decimal em string ("1234.56"), nunca float.
 */
final readonly class DadosManuaisDoProcesso
{
    /**
     * @param list<string>|null $assuntos
     */
    public function __construct(
        public ?string $classe = null,
        public ?array $assuntos = null,
        public ?string $jurisdicao = null,
        public ?string $orgao = null,
        public ?\DateTimeImmutable $distribuicao = null,
        public ?string $valorCausa = null,
        public ?bool $segredo = null,
        public ?bool $digital = null,
        public ?bool $gratuita = null,
        public ?bool $tutela = null,
        public ?bool $prioridade = null,
    ) {
    }

    public function temAlgum(): bool
    {
        foreach (get_object_vars($this) as $valor) {
            if ($valor !== null) {
                return true;
            }
        }

        return false;
    }
}
