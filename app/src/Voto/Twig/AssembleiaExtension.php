<?php

declare(strict_types=1);

namespace App\Voto\Twig;

use App\Voto\Controller\AssembleiaController;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\CnpjDaEntidade;
use Symfony\Component\HttpKernel\Controller\ControllerReference;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig da assembleia de condomínio:
 *
 *  - filtro `voto_fracao`: fração ou peso guardado com ponto e 6 casas ("0.025000") como o desenho mostra (`fp`,
 *    L294): até 6 casas, vírgula decimal e sem zeros à direita ("0,025"; "12" quando é inteiro);
 *  - filtro `voto_cnpj`: o CNPJ de 14 dígitos com a máscara "00.000.000/0000-00";
 *  - função `voto_avisos()`: os avisos pendentes do toast ({@see AvisosDoVoto}), que saem da sessão ao serem lidos;
 *  - função `voto_assembleia_aba(acao)`: a ação de {@see AssembleiaController} que uma aba de /voto embute por
 *    `render()` (painel, resultados, acompanhar ou votar), sem o nome da classe como texto no template; o segundo argumento
 *    leva parâmetros de URL à sub-requisição (o painel lê `arquivadas`).
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
            new TwigFilter('voto_cnpj', self::cnpj(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('voto_avisos', $this->avisos->retirar(...)),
            new TwigFunction('voto_assembleia_aba', self::aba(...)),
        ];
    }

    /** @param array<string, scalar> $query parâmetros de URL da sub-requisição (ex.: `arquivadas` na Gestão eleitoral) */
    public static function aba(string $acao, array $query = []): ControllerReference
    {
        if (!\in_array($acao, AssembleiaController::ABAS_EMBUTIDAS, true)) {
            throw new \InvalidArgumentException(sprintf('Aba da assembleia desconhecida: %s.', $acao));
        }

        return new ControllerReference(AssembleiaController::class . '::' . $acao, [], $query);
    }

    /** CNPJ digitado, com ou sem máscara, como "00.000.000/0000-00" quando são 14 dígitos; senão como veio (o erro do formulário volta o que se digitou). */
    public static function cnpj(?string $informado): string
    {
        $informado = (string) $informado;
        $digitos = CnpjDaEntidade::somenteDigitos($informado);

        return 14 === \strlen($digitos) ? CnpjDaEntidade::formatar($digitos) : $informado;
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
