<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Cliente\Entity\Cliente;
use App\Pasta\Service\ClientesDuplicadosDaPasta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ponte fina entre o trilho de Clientes da pasta e o `ClientesDuplicadosDaPasta`: o template não
 * compara documento nenhum, só pergunta ao serviço quais clientes têm o documento repetido.
 *
 * É pela função (e não por variável do controller) que a linha do cliente se resolve sozinha nos
 * dois caminhos: a página (`_dados_trilho`) e a resposta XHR de vincular/cadastrar
 * (`PastaController::htmlLinhaCliente`), que renderiza o mesmo partial.
 *
 * `pasta_cliente_doc_chave`: a chave com que o JS (`pasta-clientes-duplicados.js`) refaz a mesma
 * comparação depois de vincular ou desvincular sem recarregar. É um HMAC dos dígitos com o segredo
 * da aplicação, não os dígitos: o documento cru não vai para o HTML (quem não acessa Clientes vê só
 * o documento formatado da linha) e a chave não se reverte por força bruta sem o segredo.
 */
final class ClientesDuplicadosExtension extends AbstractExtension
{
    public function __construct(
        private readonly ClientesDuplicadosDaPasta $duplicados,
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pasta_clientes_duplicados', $this->duplicados->idsDe(...)),
            new TwigFunction('pasta_cliente_doc_chave', $this->chaveDoDocumento(...)),
        ];
    }

    public function chaveDoDocumento(Cliente $cliente): string
    {
        $digitos = $this->duplicados->digitosDe($cliente);
        if ($digitos === '') {
            return '';
        }

        return substr(hash_hmac('sha256', 'pasta-cliente-doc:' . $digitos, $this->segredo), 0, 20);
    }
}
