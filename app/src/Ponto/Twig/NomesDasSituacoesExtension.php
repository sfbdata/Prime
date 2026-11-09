<?php

declare(strict_types=1);

namespace App\Ponto\Twig;

use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `nome_da_situacao('situacao.pendente')`: o nome da situação da justificativa na ficha do gestor, pelo ponto único do
 * P40 (NomesDasSituacoesDaJustificativa).
 */
final class NomesDasSituacoesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nome_da_situacao', [NomesDasSituacoesDaJustificativa::class, 'texto']),
        ];
    }
}
