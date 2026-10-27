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
        /** Colaborador que o alerta nomeia (sugestão de responsável ao criar a tarefa); null = nenhum. */
        public ?int $pessoaId = null,
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

    /**
     * Alerta que pode virar tarefa: tem ação sugerida e não é "Normal" (ali a ação é só "Manter").
     * Título e descrição saem só do texto do alerta, sem dado de pasta.
     */
    public function podeVirarTarefa(): bool
    {
        return $this->nivel !== NivelDeAlerta::Normal && $this->acao !== null && trim($this->acao) !== '';
    }

    public function tituloDaTarefa(): string
    {
        return mb_substr((string) $this->acao, 0, 255);
    }

    public function descricaoDaTarefa(): string
    {
        $partes = array_filter([$this->titulo . ': ' . $this->problema, $this->evidencia, $this->acao], static fn (?string $v): bool => $v !== null && $v !== '');

        return mb_substr(implode("\n", $partes), 0, 1500);
    }
}
