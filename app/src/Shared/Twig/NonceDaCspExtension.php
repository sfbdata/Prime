<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Http\NonceDaCsp;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()`: o nonce da CSP desta requisição, para o atributo `nonce` dos `<script>` inline do layout
 * (ver NonceDaCsp). O valor é base64, seguro dentro do atributo com o escape padrão do Twig.
 */
final class NonceDaCspExtension extends AbstractExtension
{
    public function __construct(private readonly NonceDaCsp $nonce)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', $this->nonce->nonce(...))];
    }
}
