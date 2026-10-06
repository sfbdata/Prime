<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Um alerta da Central de Inteligência (Modo avançado): problema → evidência → causa →
 * impacto → ação → responsável/prazo/acompanhamento, mais os fatores da classificação e
 * os dados analisados. Campo nulo = a regra não tem o dado para preenchê-lo (fica de fora
 * da tela, nunca inventado).
 */
final readonly class Alerta
{
    /**
     * @param string[] $fatores
     * @param string[] $dados
     */
    public function __construct(
        public string $id,
        public NivelDeAlerta $nivel,
        public string $titulo,
        public string $problema,
        public ?string $evidencia = null,
        public ?string $causa = null,
        public ?string $impacto = null,
        public ?string $acao = null,
        public ?string $responsavel = null,
        public ?string $prazo = null,
        public ?string $acompanhamento = null,
        public array $fatores = [],
        public array $dados = [],
    ) {}

    /**
     * Linhas rótulo → valor do cartão aberto, na ordem do desenho (dc L2623), só as que
     * têm valor (o JS filtra vazios e "-").
     *
     * @return array<string, string>
     */
    public function linhas(): array
    {
        $linhas = [
            'Evidência'      => $this->evidencia,
            'Causa provável' => $this->causa,
            'Impacto'        => $this->impacto,
            'Ação'           => $this->acao,
            'Responsável'    => $this->responsavel,
            'Prazo'          => $this->prazo,
            'Acompanhar'     => $this->acompanhamento,
        ];

        return array_filter($linhas, static fn (?string $v): bool => $v !== null && $v !== '' && $v !== '-');
    }
}
