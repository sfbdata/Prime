<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Service\CatalogoDeConfiguracoes;

/**
 * O que a seção "Configurações globais" mostra: o catálogo (grupos e campos com tipo, limites e opções), o valor
 * que vale agora, os padrões (para "Restaurar padrão") e a última alteração.
 */
final readonly class ConfiguracaoGlobalOutput
{
    /**
     * @param array<string, string> $valores
     * @param array<string, string> $padroesDoAmbiente padrões que o ambiente muda (ver `CatalogoDeConfiguracoes::sobreposicoes()`)
     */
    public function __construct(
        public array $valores,
        public ?\DateTimeImmutable $alteradoEm,
        public array $padroesDoAmbiente = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $grupos = [];
        foreach (CatalogoDeConfiguracoes::grupos($this->padroesDoAmbiente) as $grupo => $campos) {
            $lista = [];
            foreach ($campos as $chave => $campo) {
                $lista[] = ['chave' => $chave] + $campo;
            }
            $grupos[] = ['grupo' => $grupo, 'campos' => $lista];
        }

        return [
            'grupos' => $grupos,
            'valores' => $this->valores,
            'padroes' => CatalogoDeConfiguracoes::padroes($this->padroesDoAmbiente),
            'alteradoEm' => $this->alteradoEm?->format(\DATE_ATOM),
        ];
    }
}
