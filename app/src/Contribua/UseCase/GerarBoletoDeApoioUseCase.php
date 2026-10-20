<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Banco\BancoBoleto;
use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeBoleto;
use App\Contribua\DTO\BoletoDaConta;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Apoio;
use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\Exception\BoletoIndisponivel;
use App\Contribua\Exception\BoletoNaoGerado;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Shared\Service\DigitoVerificador;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * História: na seção Apoie, a conta escolhe o valor e clica em "Gerar boleto" (chave `bol` do desenho 08).
 * Regras (spec docs/specs/contribua-boleto.md):
 * - só com a chave ligada, a integração conectada e um banco que emite boleto (só a Cora); no ambiente de
 *   testes, só o escritório do Master (a mesma regra do PIX dinâmico); senão, BoletoIndisponivel;
 * - valor de R$ 5,00 até o teto do apoio; CPF ou CNPJ válido do pagador; e-mail de quem está logado; nome
 *   do escritório; vencimento em 3 dias corridos; sem multa e sem juros;
 * - um boleto aberto por escritório, valor e dia: pedir de novo devolve o mesmo, sem ir ao banco. A trava
 *   da conta serializa cliques simultâneos; a chave de idempotência e o código são derivados do pedido
 *   (escritório, valor, dia, documento, n-ésimo boleto do dia), então repetir depois de uma falha não cria
 *   outra fatura na Cora;
 * - a linha só é gravada depois de o banco responder: falha não deixa linha e vira uma mensagem honesta;
 * - o documento vai só para o banco: não é gravado nem vai para o log.
 * A confirmação é a da cobrança PIX (webhook, reconsulta e conciliação): fatura PAID confirma o apoio.
 */
final class GerarBoletoDeApoioUseCase
{
    public const DIAS_PARA_VENCER = 3;
    public const MAXIMO_POR_DIA = 5;
    public const MINIMO = 'O boleto tem valor mínimo de R$ 5,00.';

    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    /** Se a seção Apoie mostra o botão "Gerar boleto" para quem está vendo. */
    public function disponivelPara(Solicitante $quem): bool
    {
        return null !== $this->bancoDoBoleto($quem);
    }

    /**
     * @throws BoletoIndisponivel
     * @throws BoletoNaoGerado
     * @throws \DomainException valor ou documento recusado, limite do dia
     */
    public function executar(Solicitante $quem, int $valorCentavos, string $documento): BoletoDaConta
    {
        $banco = $this->bancoDoBoleto($quem) ?? throw new BoletoIndisponivel();
        $documento = DigitoVerificador::digitos($documento);
        self::validar($valorCentavos, $documento);
        $boleto = $this->cobrancas->comTravaDeBoleto(
            $quem->escritorio,
            fn (): CobrancaPixDaConta => $this->doDiaOuNovo($quem, $banco, $valorCentavos, $documento),
        );

        return BoletoDaConta::de($boleto);
    }

    private function bancoDoBoleto(Solicitante $quem): ?BancoBoleto
    {
        $integracao = $this->integracoes->atual();
        $banco = $this->bancos->doCodigo($integracao->getBanco());
        $ambienteServe = 'producao' === $integracao->getAmbiente() || $quem->ehMaster;

        return $banco instanceof BancoBoleto && $integracao->boletoLigado() && $ambienteServe ? $banco : null;
    }

    private static function validar(int $valorCentavos, string $documento): void
    {
        if ($valorCentavos < BancoBoleto::MINIMO_CENTAVOS) {
            throw new \DomainException(self::MINIMO);
        }
        if ($valorCentavos > Apoio::VALOR_MAXIMO_CENTAVOS) {
            throw new \DomainException('Valor acima do permitido para o apoio.');
        }
        if (!DigitoVerificador::cpfValido($documento) && !DigitoVerificador::cnpjValido($documento)) {
            throw new \DomainException('Informe um CPF ou CNPJ válido do pagador.');
        }
    }

    /** Dentro da trava da conta: o boleto aberto de hoje neste valor ou um novo, emitido no banco. */
    private function doDiaOuNovo(Solicitante $quem, BancoBoleto $banco, int $valorCentavos, string $documento): CobrancaPixDaConta
    {
        $integracao = $this->integracoes->atual();
        $hoje = new \DateTimeImmutable('today');
        $existente = $this->cobrancas->boletoAbertoDoDia($quem->escritorio, $integracao->getBanco(), $integracao->getAmbiente(), $valorCentavos, $hoje);
        if (null !== $existente) {
            return $existente;
        }
        $jaHoje = $this->cobrancas->criadasDesde($quem->escritorio, $hoje, CobrancaPixDaConta::FORMA_BOLETO);
        if ($jaHoje >= self::MAXIMO_POR_DIA) {
            throw new \DomainException('Limite de boletos de hoje atingido. Use um boleto já gerado ou tente amanhã.');
        }
        $pedido = $this->pedido($quem, $valorCentavos, $documento, $hoje, $jaHoje);

        return $this->emitir($quem, $banco, $pedido);
    }

    /** Chave de idempotência e código derivados do pedido (HMAC com o segredo da aplicação: o documento não sai dele). */
    private function pedido(Solicitante $quem, int $valorCentavos, string $documento, \DateTimeImmutable $hoje, int $enesimo): PedidoDeBoleto
    {
        $integracao = $this->integracoes->atual();
        $base = implode('|', ['boleto', $quem->tenantId(), $integracao->getBanco(), $integracao->getAmbiente(), $valorCentavos, $hoje->format('Y-m-d'), $documento, $enesimo]);
        $hash = hash_hmac('sha256', $base, $this->segredo);

        return new PedidoDeBoleto(
            'BJ' . $quem->numeroDaConta() . '-B' . substr($hash, 32, 10),
            self::uuid(substr($hash, 0, 32)),
            (string) $quem->escritorio->getName(),
            $documento,
            (string) $quem->usuario->getEmail(),
            $valorCentavos,
            $hoje->modify('+' . self::DIAS_PARA_VENCER . ' days'),
        );
    }

    private function emitir(Solicitante $quem, BancoBoleto $banco, PedidoDeBoleto $pedido): CobrancaPixDaConta
    {
        $integracao = $this->integracoes->atual();
        try {
            $emitido = $banco->emitirBoleto($pedido);
        } catch (FalhaNoBanco $e) {
            // Sem o documento no log: números longos do motivo do banco saem mascarados.
            $this->logger->warning('Contribua: boleto não gerado no banco', ['conta' => $quem->numeroDaConta(), 'motivo' => preg_replace('/\d[\d.\/-]{9,}\d/', '[número]', $e->getMessage())]);

            throw new BoletoNaoGerado($e);
        }
        // Repetição que a Cora resolveu pela chave de idempotência: a fatura já é nossa.
        $mesma = $this->cobrancas->doBanco($integracao->getBanco(), $emitido->idNoBanco);
        if (null !== $mesma) {
            return $mesma;
        }
        $boleto = CobrancaPixDaConta::boletoEmitido($quem->escritorio, $integracao->getBanco(), $integracao->getAmbiente(), $pedido->valorCentavos, $pedido->vencimento, $pedido->chaveDeIdempotencia, $pedido->codigo, $emitido->idNoBanco, $emitido->linhaDigitavel, $emitido->url, $emitido->pixCopiaECola);
        $this->cobrancas->salvar($boleto);

        return $boleto;
    }

    /** 32 dígitos hexadecimais no formato UUID v4 (a Cora pede o Idempotency-Key assim). */
    private static function uuid(string $hex): string
    {
        $b = (string) hex2bin($hex);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
