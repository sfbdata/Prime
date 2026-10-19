<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * O PIX da conta com a integração bancária (C4, desenho 08: "cada conta recebe uma cobrança PIX própria e
 * o apoio é confirmado sozinho"). Decora o ponto de extensão da f6: devolve o copia e cola da cobrança da
 * conta no banco quando vale, e o do decorado (o estático) quando não vale: integração desligada, recurso
 * "Cobrança PIX por conta" desligado, escritório sem CNPJ (o banco exige o documento), valor abaixo do
 * mínimo do banco ou falha do banco. Reaproveita a cobrança aberta da mesma conta e valor.
 */
#[AsDecorator(decorates: CodigoPixDaConta::class)]
final class CodigoPixDaContaDinamico implements CodigoPixDaConta
{
    /** Mínimo do QR Code Pix da Cora (developers.cora.com.br/reference/qr-code-pix-v2: amount 500). */
    public const MINIMO_CENTAVOS = 500;
    private const DIAS_PARA_VENCER = 7;

    public function __construct(
        #[AutowireDecorated]
        private readonly CodigoPixDaConta $estatico,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function para(Solicitante $quem, int $valorCentavos): array
    {
        $integracao = $this->integracoes->atual();
        $banco = $this->bancos->doCodigo($integracao->getBanco());
        $cnpj = preg_replace('/\D/', '', (string) $quem->escritorio->getCnpj());
        if (null === $banco || !$integracao->cobraPorConta() || 14 !== strlen((string) $cnpj) || $valorCentavos < self::MINIMO_CENTAVOS) {
            return $this->estatico->para($quem, $valorCentavos);
        }
        $hoje = new \DateTimeImmutable('today');
        $cobranca = $this->cobrancas->abertaParaReusar($quem->escritorio, $banco->codigo(), $valorCentavos, $hoje)
            ?? $this->criar($quem, (string) $cnpj, $valorCentavos, $banco->codigo(), $hoje);

        return null !== $cobranca && null !== $cobranca->getCopiaECola()
            ? ['codigo' => $cobranca->getCopiaECola(), 'valido' => true]
            : $this->estatico->para($quem, $valorCentavos);
    }

    private function criar(Solicitante $quem, string $cnpj, int $valorCentavos, string $codigoDoBanco, \DateTimeImmutable $hoje): ?CobrancaPixDaConta
    {
        $cobranca = new CobrancaPixDaConta($quem->escritorio, $codigoDoBanco, $valorCentavos, $hoje->modify('+' . self::DIAS_PARA_VENCER . ' days'), self::uuid());
        $this->cobrancas->salvar($cobranca);
        $codigo = $cobranca->definirCodigo($quem->numeroDaConta());
        try {
            $criada = $this->bancos->doCodigo($codigoDoBanco)?->criarCobranca(new PedidoDeCobrancaPix(
                $codigo,
                $cobranca->getChaveDeIdempotencia(),
                (string) $quem->escritorio->getName(),
                $cnpj,
                $valorCentavos,
                $cobranca->getVencimento(),
            ));
        } catch (FalhaNoBanco $e) {
            $this->logger->warning('Contribua: cobrança PIX não criada no banco; vale o PIX estático', ['conta' => $quem->numeroDaConta(), 'motivo' => $e->getMessage()]);
            $cobranca->marcarCancelada();
            $this->cobrancas->salvar($cobranca);

            return null;
        }
        if (null !== $criada) {
            $cobranca->registrarNoBanco($criada->idNoBanco, $criada->copiaECola);
        }
        $this->cobrancas->salvar($cobranca);

        return $cobranca;
    }

    /** UUID v4 (a Cora pede o Idempotency-Key nesse formato). */
    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
