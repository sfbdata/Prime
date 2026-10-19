<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\Service\ClientesDuplicadosDaPasta;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ponte fina entre o trilho de Clientes da pasta e o `ClientesDuplicadosDaPasta`: o template não
 * compara documento nenhum, só pergunta ao serviço quais clientes têm o documento repetido.
 *
 * É pela função (e não por variável do controller) que a linha do cliente se resolve sozinha nos
 * dois caminhos: a página (`_dados_trilho`) e a resposta XHR de vincular/cadastrar
 * (`PastaController::htmlLinhaCliente`), que renderiza o mesmo partial.
 */
final class ClientesDuplicadosExtension extends AbstractExtension
{
    public function __construct(
        private readonly ClientesDuplicadosDaPasta $duplicados,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pasta_clientes_duplicados', $this->duplicados->idsDe(...)),
        ];
    }
}
