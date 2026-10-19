<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\ResultadoDoTeste;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\Service\CodigoPixDaContaDinamico;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * História: o Master clica em "Testar conexão" (desenho 08). O servidor pede o token ao banco no ambiente
 * escolhido, por mTLS. Deu certo: "Conectado" (a cobrança por conta passa a valer); não: "Não conectado",
 * com o motivo.
 */
final class TestarConexaoDoBancoUseCase
{
    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function executar(): ResultadoDoTeste
    {
        $integracao = $this->integracoes->atual();
        $banco = $this->bancos->doCodigo($integracao->getBanco());
        $resultado = $banco?->testarConexao() ?? new ResultadoDoTeste(false, 'Banco ainda não disponível.');
        $integracao->registrarTeste($resultado->conectou, $resultado->mensagem, new \DateTimeImmutable());
        if ($resultado->conectou) {
            $this->cache->deleteItem(CodigoPixDaContaDinamico::CHAVE_PAUSA);
        }
        $this->integracoes->salvar($integracao);

        return $resultado;
    }
}
