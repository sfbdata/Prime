<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;

/**
 * História: o Master preenche ambiente, Client ID e as chaves do que a integração faz e clica em "Salvar
 * integração" (desenho 08, L255). Sem Client ID, recusa. Com certificado já enviado, fica "Aguardando
 * servidor" até o teste de conexão confirmar; sem, "Não conectado".
 */
final class SalvarIntegracaoBancariaUseCase
{
    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
    ) {
    }

    /** @param array<string, bool> $recursos */
    public function executar(string $ambiente, string $clientId, array $recursos, User $master): string
    {
        if ('' === trim($clientId)) {
            throw new \DomainException('Informe o Client ID fornecido pelo banco.');
        }
        $integracao = $this->integracoes->atual();
        $integracao->configurar($ambiente, $clientId, $recursos, $master);
        $this->integracoes->salvar($integracao);

        return 'Integração salva. Ela é ativada quando o servidor validar o certificado.';
    }
}
