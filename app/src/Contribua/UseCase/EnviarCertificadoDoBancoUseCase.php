<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Banco\CertificadoDoBanco;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;

/**
 * História: o Master envia o certificado e a chave privada que o banco forneceu (desenho 08: "Enviado direto
 * ao servidor e guardado em cofre. O conteúdo nunca aparece na tela nem fica no navegador."). Conferimos que
 * formam um par, guardamos os dois cifrados (D6) e devolvemos só os nomes dos arquivos.
 */
final class EnviarCertificadoDoBancoUseCase
{
    private const TAMANHO_MAXIMO = 64 * 1024;

    public function __construct(
        private readonly CertificadoDoBanco $leitor,
        private readonly CofreDeCertificado $cofre,
        private readonly IntegracaoBancariaRepository $integracoes,
    ) {
    }

    /**
     * @param array<string, string> $arquivos nome => conteúdo
     *
     * @return list<string> os nomes guardados
     */
    public function executar(array $arquivos, User $master): array
    {
        foreach ($arquivos as $conteudo) {
            if (strlen($conteudo) > self::TAMANHO_MAXIMO) {
                throw new \DomainException('Arquivo grande demais para um certificado.');
            }
        }
        $par = $this->leitor->ler($arquivos);
        $integracao = $this->integracoes->atual();
        $integracao->guardarCertificado($this->cofre->cifrar($par['certificado']), $this->cofre->cifrar($par['chave']), array_keys($arquivos), $master);
        $this->integracoes->salvar($integracao);

        return $integracao->getArquivos();
    }
}
