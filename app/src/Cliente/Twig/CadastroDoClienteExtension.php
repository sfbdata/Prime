<?php

declare(strict_types=1);

namespace App\Cliente\Twig;

use App\Cliente\Service\PendenciasDoCadastro;
use App\Cliente\Service\QualificacaoDoCliente;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ponte fina entre os templates e os serviços do cadastro do cliente: o trilho da
 * pasta não decide o que é "cadastro completo", só pergunta ao mesmo serviço que a
 * janela "Detalhes do cliente" usa.
 */
final class CadastroDoClienteExtension extends AbstractExtension
{
    public function __construct(
        private readonly PendenciasDoCadastro $pendencias,
        private readonly QualificacaoDoCliente $qualificacao,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cliente_pendencias', $this->pendencias->de(...)),
            new TwigFunction('cliente_qualificacao', $this->qualificacao->de(...)),
        ];
    }
}
