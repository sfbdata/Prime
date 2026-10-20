<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Banco\Bacen\BancoPixBacen;
use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\DTO\DadosDoBancoInput;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;

/**
 * História: o Master escolhe o banco, preenche ambiente, Client ID, os campos que o banco pede e as chaves
 * do que a integração faz e clica em "Salvar integração" (desenho 08, L255). Sem Client ID, recusa; banco
 * fora de BancosPix, recusa (D5). Inter, BB e Outro exigem Client Secret e chave PIX; o BB, a app key; o
 * Outro, as URLs (D4). Os segredos vão cifrados para o cofre; em branco, fica o que já estava, salvo se
 * o banco ou as URLs mudaram: aí o segredo guardado é esquecido e tem de ser informado de novo. Com
 * certificado já enviado, fica "Aguardando servidor" até o teste de conexão confirmar; sem, "Não conectado".
 */
final class SalvarIntegracaoBancariaUseCase
{
    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly CofreDeCertificado $cofre,
    ) {
    }

    /**
     * @param array<string, bool> $recursos
     * @param DadosDoBancoInput|null $dados null mantém o banco e os campos extras como estão
     */
    public function executar(string $ambiente, string $clientId, array $recursos, User $master, ?DadosDoBancoInput $dados = null): string
    {
        if ('' === trim($clientId)) {
            throw new \DomainException('Informe o Client ID fornecido pelo banco.');
        }
        $integracao = $this->integracoes->atual();
        $banco = $this->bancos->doCodigo($dados->banco ?? $integracao->getBanco()) ?? throw new \DomainException('Banco não disponível.');
        if (null !== $dados) {
            $secret = trim($dados->clientSecret);
            $appKey = trim($dados->appKey);
            // Outro banco ou outro endereço: o segredo guardado não serve (nunca vai para um host novo).
            $trocou = $banco->codigo() !== $integracao->getBanco()
                || rtrim(trim($dados->urlBase), '/') !== (string) $integracao->getUrlBase()
                || trim($dados->urlToken) !== (string) $integracao->getUrlToken();
            if ($banco instanceof BancoPixBacen) {
                $this->exigir($banco, $dados, $secret, $appKey, $integracao->temClientSecret(), $integracao->temAppKey(), $trocou);
            }
            // Confere a chave PIX e as URLs antes de mudar qualquer coisa.
            $integracao->definirEnderecos($dados->chavePix, $dados->urlBase, $dados->urlToken);
            if ($trocou) {
                $integracao->esquecerSegredos();
            }
            $integracao->guardarSegredos('' === $secret ? null : $this->cofre->cifrar($secret), '' === $appKey ? null : $this->cofre->cifrar($appKey));
        }
        $integracao->escolherBanco($banco->codigo());
        $integracao->configurar($ambiente, $clientId, $recursos, $master);
        $this->integracoes->salvar($integracao);

        return 'Integração salva. Ela é ativada quando o servidor validar o certificado.';
    }

    /** `$tinha*`: o segredo guardado; na troca ele não vale. */
    private function exigir(BancoPixBacen $banco, DadosDoBancoInput $dados, string $secret, string $appKey, bool $tinhaSecret, bool $tinhaAppKey, bool $trocou): void
    {
        $falta = $banco->faltaNaConfiguracao('' !== $secret || ($tinhaSecret && !$trocou), $dados->chavePix, '' !== $appKey || ($tinhaAppKey && !$trocou), $dados->urlBase, $dados->urlToken);
        if (null === $falta) {
            return;
        }
        // A app key só existe no BB, e trocar de banco a esquece: "de novo" só vale para o Client Secret.
        if ($trocou && $tinhaSecret && 'Informe o Client Secret fornecido pelo banco.' === $falta) {
            throw new \DomainException('Ao trocar de banco ou de endereço, informe de novo o Client Secret.');
        }

        throw new \DomainException($falta);
    }
}
