<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A logo "BlueJus" do PDF com selo: a imagem `BJAssinaLogoBJ` do desenho (bj-assina-modelos.js, dc:394), que é o mesmo
 * arquivo estático da tela ({@see ApresentacaoDoDocumento::LOGO_BLUEJUS}, PNG de 1000 x 304). Arquivo do repositório,
 * não de usuário: lido do `public/` uma vez por processo.
 */
final class LogoDaBluejusNoPdf
{
    private ?string $bytes = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $raiz,
    ) {
    }

    /** Os bytes do PNG; nulo se o arquivo sumiu (a folha cai na marca "BlueJus" em texto). */
    public function bytes(): ?string
    {
        if ($this->bytes === null) {
            $lidos = @file_get_contents($this->raiz . '/public/' . ApresentacaoDoDocumento::LOGO_BLUEJUS);
            $this->bytes = $lidos === false ? '' : $lidos;
        }

        return $this->bytes === '' ? null : $this->bytes;
    }
}
