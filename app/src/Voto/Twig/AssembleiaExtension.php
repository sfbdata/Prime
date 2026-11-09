<?php

declare(strict_types=1);

namespace App\Voto\Twig;

use App\Voto\Controller\AssembleiaController;
use App\Voto\Service\AvisosDoVoto;
use Symfony\Component\HttpKernel\Controller\ControllerReference;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig da assembleia de condomínio:
 *
 *  - filtro `voto_fracao`: fração ou peso guardado com ponto e 6 casas ("0.025000") como o desenho mostra (`fp`,
 *    L294): até 6 casas, vírgula decimal e sem zeros à direita ("0,025"; "12" quando é inteiro);
 *  - função `voto_avisos()`: os avisos pendentes do toast ({@see AvisosDoVoto}), que saem da sessão ao serem lidos;
 *  - função `voto_assembleia_aba(acao)`: a ação de {@see AssembleiaController} que uma aba de /voto embute por
 *    `render()` (painel, resultados ou acompanhar), sem o nome da classe como texto no template.
 */
final class AssembleiaExtension extends AbstractExtension
{
    public function __construct(private readonly AvisosDoVoto $avisos)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('voto_fracao', self::fracao(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('voto_avisos', $this->avisos->retirar(...)),
            new TwigFunction('voto_assembleia_aba', self::aba(...)),
        ];
    }

    public static function aba(string $acao): ControllerReference
    {
        if (!\in_array($acao, AssembleiaController::ABAS_EMBUTIDAS, true)) {
            throw new \InvalidArgumentException(sprintf('Aba da assembleia desconhecida: %s.', $acao));
        }

        return new ControllerReference(AssembleiaController::class . '::' . $acao);
    }

    public static function fracao(?string $decimal): string
    {
        if (null === $decimal || '' === $decimal) {
            return '';
        }
        [$inteira, $casas] = array_pad(explode('.', $decimal, 2), 2, '');
        $casas = rtrim($casas, '0');
        $inteira = number_format((float) $inteira, 0, ',', '.');

        return '' === $casas ? $inteira : $inteira . ',' . $casas;
    }
}
