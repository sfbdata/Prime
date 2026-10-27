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
    /** @param array<string, string> $valores */
    public function __construct(
        public array $valores,
        public ?\DateTimeImmutable $alteradoEm,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $grupos = [];
        foreach (CatalogoDeConfiguracoes::grupos() as $grupo => $campos) {
            $lista = [];
            foreach ($campos as $chave => $campo) {
                $lista[] = ['chave' => $chave] + $campo;
            }
            $grupos[] = ['grupo' => $grupo, 'campos' => $lista];
        }

        return [
            'grupos' => $grupos,
            'valores' => $this->valores,
            'padroes' => CatalogoDeConfiguracoes::padroes(),
            'alteradoEm' => $this->alteradoEm?->format(\DATE_ATOM),
        ];
    }
}
