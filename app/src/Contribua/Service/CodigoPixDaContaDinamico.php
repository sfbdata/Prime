<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Banco\BancoPix;
use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Apoio;
use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * O PIX da conta com a integração bancária (C4, desenho 08: "cada conta recebe uma cobrança PIX própria e
 * o apoio é confirmado sozinho"). Decora o ponto de extensão da f6: devolve o copia e cola da cobrança da
 * conta no banco quando vale, e o do decorado (o estático) quando não vale:
 * - integração não conectada ou "Cobrança PIX por conta" desligada;
 * - ambiente de testes (sandbox) para quem não é o Master: só o escritório do Master testa;
 * - escritório sem CNPJ (o banco exige o documento), valor abaixo do mínimo do banco ou acima do teto;
 * - limite de cobranças da conta no dia (a página cria cobrança a cada valor escolhido);
 * - banco fora do ar há pouco (pausa de 5 min só para falha passageira; a cobrança que já tem código segue
 *   valendo durante a pausa). Recusa do banco para o pedido de uma conta cancela só aquela cobrança.
 * Reaproveita a cobrança aberta da mesma conta, ambiente e valor; se ela ficou sem código (o banco falhou),
 * repete a criação com a MESMA chave de idempotência (o banco devolve a cobrança se já a tinha criado).
 */
#[AsDecorator(decorates: CodigoPixDaConta::class)]
final class CodigoPixDaContaDinamico implements CodigoPixDaConta
{
    /** Mínimo do QR Code Pix da Cora (developers.cora.com.br/reference/qr-code-pix-v2: amount 500). */
    public const MINIMO_CENTAVOS = 500;
    public const MAXIMO_POR_DIA = 10;
    private const DIAS_PARA_VENCER = 7;
    private const PAUSA_SEGUNDOS = 300;
    public const CHAVE_PAUSA = 'contribua_banco_em_pausa';

    public function __construct(
        #[AutowireDecorated]
        private readonly CodigoPixDaConta $estatico,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function para(Solicitante $quem, int $valorCentavos): array
    {
        $integracao = $this->integracoes->atual();
        $banco = $this->bancos->doCodigo($integracao->getBanco());
        $cnpj = (string) preg_replace('/\D/', '', (string) $quem->escritorio->getCnpj());
        if (null === $banco || !$this->vale($integracao, $quem, $cnpj, $valorCentavos)) {
            return $this->estatico->para($quem, $valorCentavos);
        }
        $hoje = new \DateTimeImmutable('today');
        $cobranca = $this->cobrancas->abertaParaReusar($quem->escritorio, $banco->codigo(), $integracao->getAmbiente(), $valorCentavos, $hoje);
        if (null !== $cobranca && null !== $cobranca->getCopiaECola()) {
            // Já registrada no banco: não depende da pausa nem do limite.
            return ['codigo' => $cobranca->getCopiaECola(), 'valido' => true];
        }
        if ($this->emPausa() || (null === $cobranca && $this->cobrancas->criadasDesde($quem->escritorio, $hoje) >= self::MAXIMO_POR_DIA)) {
            return $this->estatico->para($quem, $valorCentavos);
        }
        $cobranca ??= $this->nova($quem, $banco, $integracao->getAmbiente(), $valorCentavos, $hoje);
        if (null === $cobranca->getCopiaECola()) {
            $this->registrarNoBanco($quem, $banco, $cobranca, $cnpj);
        }

        return null !== $cobranca->getCopiaECola()
            ? ['codigo' => $cobranca->getCopiaECola(), 'valido' => true]
            : $this->estatico->para($quem, $valorCentavos);
    }

    private function vale(IntegracaoBancaria $integracao, Solicitante $quem, string $cnpj, int $valorCentavos): bool
    {
        return $integracao->cobraPorConta()
            && ('producao' === $integracao->getAmbiente() || $quem->ehMaster)
            && 14 === strlen($cnpj)
            && $valorCentavos >= self::MINIMO_CENTAVOS && $valorCentavos <= Apoio::VALOR_MAXIMO_CENTAVOS;
    }

    private function emPausa(): bool
    {
        return $this->cache->getItem(self::CHAVE_PAUSA)->isHit();
    }


    private function nova(Solicitante $quem, BancoPix $banco, string $ambiente, int $valorCentavos, \DateTimeImmutable $hoje): CobrancaPixDaConta
    {
        $cobranca = new CobrancaPixDaConta($quem->escritorio, $banco->codigo(), $ambiente, $valorCentavos, $hoje->modify('+' . self::DIAS_PARA_VENCER . ' days'), self::uuid());
        $this->cobrancas->salvar($cobranca);
        $cobranca->definirCodigo($quem->numeroDaConta());
        $this->cobrancas->salvar($cobranca);

        return $cobranca;
    }

    private function registrarNoBanco(Solicitante $quem, BancoPix $banco, CobrancaPixDaConta $cobranca, string $cnpj): void
    {
        try {
            $criada = $banco->criarCobranca(new PedidoDeCobrancaPix((string) $cobranca->getCodigo(), $cobranca->getChaveDeIdempotencia(), (string) $quem->escritorio->getName(), $cnpj, $cobranca->getValorCentavos(), $cobranca->getVencimento()));
        } catch (FalhaNoBanco $e) {
            $this->logger->warning('Contribua: cobrança PIX não criada no banco; vale o PIX estático', ['conta' => $quem->numeroDaConta(), 'motivo' => $e->getMessage()]);
            if ($e->passageira) {
                // Banco fora: pausa para todos, e esta cobrança é refeita com a mesma chave depois.
                $this->cache->save($this->cache->getItem(self::CHAVE_PAUSA)->set(true)->expiresAfter(self::PAUSA_SEGUNDOS));

                return;
            }
            // O banco recusou o pedido desta conta: repetir não adianta, e os outros não pagam por isso.
            $cobranca->marcarCancelada();
            $this->cobrancas->salvar($cobranca);

            return;
        }
        $cobranca->registrarNoBanco($criada->idNoBanco, $criada->copiaECola);
        $this->cobrancas->salvar($cobranca);
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
