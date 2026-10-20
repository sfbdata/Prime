<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Bacen;

use App\Contribua\Banco\BancoPix;
use App\Contribua\Banco\CobrancaPix;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Banco\ResultadoDoTeste;
use App\Contribua\Banco\SituacaoDaCobranca;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Banco da API Pix do BACEN (C4b/C4c, D2): cobrança imediata `PUT {base}/cob/{txid}` e consulta
 * `GET {base}/cob/{txid}` (bacen.github.io/pix-api, "Cob"). Cada banco diz só as URLs, como pede o token e
 * o que mais a configuração exige.
 *
 * txid (D3): o adaptador gera, alfanumérico de 32 caracteres (o padrão aceita de 26 a 35 na criação por
 * PUT), DETERMINÍSTICO a partir do nosso código e da chave de idempotência: repetir a mesma tentativa repete
 * o mesmo txid, e o banco não cria duas cobranças. O txid é o id no banco (`CobrancaPix::$idNoBanco`); o
 * nosso código (`BJ<conta>-<n>`) vai em `infoAdicionais` e volta na consulta.
 */
abstract class BancoPixBacen implements BancoPix
{
    public const TAMANHO_TXID = 32;
    /** Nome do campo de `infoAdicionais` com o nosso código. */
    public const INFO_CODIGO = 'Código';
    private const EXPIRACAO_MINIMA_SEGUNDOS = 3600;

    public function __construct(
        private readonly ClientePixBacen $cliente,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly CofreDeCertificado $cofre,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    abstract public function codigo(): string;

    /** Com artigo, para as mensagens: "o Banco Inter". */
    abstract protected function nome(): string;

    abstract protected function urlDoToken(IntegracaoBancaria $i): string;

    abstract protected function urlBase(IntegracaoBancaria $i): string;

    /** Pede o token com `Authorization: Basic` (o padrão do OAuth2); o Inter quer no corpo. */
    protected function tokenPorBasic(): bool
    {
        return true;
    }

    /**
     * Parâmetros de consulta de toda chamada à API (a app key do BB).
     *
     * @return array<string, string>
     */
    protected function parametrosDaApi(IntegracaoBancaria $i, string $appKey): array
    {
        return [];
    }

    /**
     * Cabeçalhos de toda chamada à API (não vão no token): o `x-conta-corrente` do Inter.
     *
     * @return array<string, string>
     */
    protected function cabecalhosDaApi(IntegracaoBancaria $i): array
    {
        return [];
    }

    /** O banco aceita a conta corrente opcional (Inter); nos outros, salvar a apaga. */
    public function usaContaCorrente(): bool
    {
        return false;
    }

    protected function exigeAppKey(): bool
    {
        return false;
    }

    protected function exigeEnderecos(): bool
    {
        return false;
    }

    /**
     * O que falta na configuração deste banco (ao salvar e antes de chamar), ou null. O Client ID e o
     * certificado são conferidos à parte, como na Cora.
     */
    public function faltaNaConfiguracao(bool $temClientSecret, string $chavePix, bool $temAppKey, string $urlBase, string $urlToken): ?string
    {
        return match (true) {
            !$temClientSecret => 'Informe o Client Secret fornecido pelo banco.',
            '' === trim($chavePix) => 'Informe a chave PIX da conta que recebe.',
            $this->exigeAppKey() && !$temAppKey => 'Informe a app key do Banco do Brasil.',
            $this->exigeEnderecos() && ('' === trim($urlBase) || '' === trim($urlToken)) => 'Informe a URL base da API Pix e a URL do token do banco.',
            default => null,
        };
    }

    /** txid determinístico (D3): mesma tentativa, mesmo txid; `[a-zA-Z0-9]{26,35}`. */
    public static function txid(PedidoDeCobrancaPix $pedido): string
    {
        return 'BJ' . substr(hash('sha256', $pedido->codigo . '|' . $pedido->chaveDeIdempotencia), 0, self::TAMANHO_TXID - 2);
    }

    public function testarConexao(): ResultadoDoTeste
    {
        try {
            $credenciais = $this->credenciais();
        } catch (FalhaNoBanco $e) {
            return new ResultadoDoTeste(false, $e->getMessage());
        }
        try {
            $this->cliente->token($credenciais);
        } catch (FalhaNoBanco $e) {
            return new ResultadoDoTeste(false, ucfirst($this->nome()) . ' recusou a conexão: ' . $e->getMessage());
        }
        // Consulta leve (lista de cobranças da última hora, um item): confere a URL da API, o mTLS e o escopo.
        $agora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $this->cliente->pedir($credenciais, 'GET', '/cob', ['query' => [
                'inicio' => $agora->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'),
                'fim' => $agora->format('Y-m-d\TH:i:s\Z'),
                'paginacao.itensPorPagina' => '1',
            ]]);
        } catch (FalhaNoBanco $e) {
            return new ResultadoDoTeste(false, ucfirst($this->nome()) . ' aceitou as credenciais, mas a API Pix recusou a consulta: ' . $e->getMessage());
        }

        return new ResultadoDoTeste(true, 'Conexão com ' . $this->nome() . ' confirmada.');
    }

    public function criarCobranca(PedidoDeCobrancaPix $pedido): CobrancaPix
    {
        $c = $this->credenciais();
        $txid = self::txid($pedido);
        try {
            $r = $this->cliente->pedir($c, 'PUT', '/cob/' . $txid, ['json' => [
                'calendario' => ['expiracao' => $this->expiracao($pedido->vencimento)],
                'devedor' => ['cnpj' => $pedido->cnpj, 'nome' => mb_substr($pedido->nomeDoEscritorio, 0, 200)],
                'valor' => ['original' => self::reais($pedido->valorCentavos)],
                'chave' => $c->chavePix,
                'solicitacaoPagador' => mb_substr('Apoio ao BlueJus, conta ' . $pedido->codigo, 0, 140),
                'infoAdicionais' => [['nome' => self::INFO_CODIGO, 'valor' => $pedido->codigo]],
            ]]);
        } catch (FalhaNoBanco $e) {
            // Repetição da mesma tentativa (o banco criou e a resposta se perdeu): o txid já existe.
            $r = !$e->passageira && in_array($e->getCode(), [400, 409, 422], true) ? $this->jaCriada($c, $txid) : null;
            if (null === $r) {
                throw $e;
            }
        }
        $emv = $r['pixCopiaECola'] ?? null;
        if ((isset($r['txid']) && $r['txid'] !== $txid) || !is_string($emv) || '' === $emv) {
            throw new FalhaNoBanco(ucfirst($this->nome()) . ' não devolveu o código PIX da cobrança.');
        }

        return new CobrancaPix($txid, $emv);
    }

    public function consultar(string $idNoBanco): SituacaoDaCobranca
    {
        $r = $this->cliente->pedir($this->credenciais(), 'GET', '/cob/' . rawurlencode($idNoBanco));
        if (isset($r['txid']) && $r['txid'] !== $idNoBanco) {
            throw new FalhaNoBanco(ucfirst($this->nome()) . ' devolveu outra cobrança na consulta.', 0, null, false);
        }
        $status = (string) ($r['status'] ?? '');
        $itens = is_array($r['pix'] ?? null) ? array_values($r['pix']) : [];
        $pix = is_array($itens[0] ?? null) ? $itens[0] : [];
        $paga = 'CONCLUIDA' === $status;
        // O valor pago é a soma de pix[] (a cobrança pode ser paga em mais de um PIX); sem pix[], o valor.original.
        $valor = !$paga ? 0 : ([] === $itens ? self::centavos($r['valor']['original'] ?? null) : self::somaDosPix($itens));
        if ($paga && $valor <= 0) {
            // Paga sem valor legível: não confirma com o valor esperado; fica para a próxima consulta.
            $this->logger->warning('Contribua: o banco deu a cobrança por paga sem valor legível; não confirmada', ['banco' => $this->codigo(), 'txid' => $idNoBanco]);
            $paga = false;
        }

        return new SituacaoDaCobranca(
            paga: $paga,
            valorPagoCentavos: $valor,
            pagaEm: $paga ? (self::horario($pix['horario'] ?? null) ?? new \DateTimeImmutable()) : null,
            codigo: self::nossoCodigo($r['infoAdicionais'] ?? null),
            cancelada: str_starts_with($status, 'REMOVIDA_'),
        );
    }

    /** "12.34" (o formato do padrão para `valor.original`). */
    public static function reais(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    public static function centavos(mixed $valor): int
    {
        if (!is_string($valor) || 1 !== preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', trim($valor), $m)) {
            return 0;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * Soma de `pix[].valor` em centavos; um item ilegível (ou zerado) faz a soma valer 0, e a cobrança paga
     * sem valor legível não é confirmada.
     *
     * @param list<mixed> $itens
     */
    private static function somaDosPix(array $itens): int
    {
        $soma = 0;
        foreach ($itens as $item) {
            $centavos = is_array($item) ? self::centavos($item['valor'] ?? null) : 0;
            if ($centavos <= 0) {
                return 0;
            }
            $soma += $centavos;
        }

        return $soma;
    }

    /** @return array<string, mixed>|null */
    private function jaCriada(CredenciaisBacen $c, string $txid): ?array
    {
        try {
            $r = $this->cliente->pedir($c, 'GET', '/cob/' . $txid);
        } catch (FalhaNoBanco) {
            return null;
        }

        return 'ATIVA' === ($r['status'] ?? null) ? $r : null;
    }

    private function expiracao(\DateTimeImmutable $vencimento): int
    {
        return max(self::EXPIRACAO_MINIMA_SEGUNDOS, $vencimento->setTime(23, 59, 59)->getTimestamp() - time());
    }

    /**
     * O `horario` do PSP é RFC 3339, quase sempre em UTC (`Z`). Vai para o fuso do PHP antes de virar `pagoEm`:
     * a coluna grava a hora sem fuso, e `2026-10-31T01:30:00Z` tem de ficar 30/10 22:30 (America/Sao_Paulo).
     */
    private static function horario(mixed $horario): ?\DateTimeImmutable
    {
        if (!is_string($horario) || '' === $horario) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($horario))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }

    private static function nossoCodigo(mixed $infoAdicionais): ?string
    {
        foreach (is_array($infoAdicionais) ? $infoAdicionais : [] as $info) {
            if (is_array($info) && self::INFO_CODIGO === ($info['nome'] ?? null) && is_string($info['valor'] ?? null)) {
                return $info['valor'];
            }
        }

        return null;
    }

    private function credenciais(): CredenciaisBacen
    {
        $i = $this->integracoes->atual();
        // As credenciais guardadas são do banco configurado: nunca vão para outro banco.
        if ($i->getBanco() !== $this->codigo()) {
            throw new FalhaNoBanco('A integração foi trocada para outro banco; esta cobrança não é mais consultada automaticamente.', 0, null, false);
        }
        if (null === $i->getClientId() || '' === $i->getClientId() || !$i->temCertificado()) {
            throw new FalhaNoBanco('Informe o Client ID e envie o certificado antes de testar.');
        }
        $falta = $this->faltaNaConfiguracao($i->temClientSecret(), (string) $i->getChavePix(), $i->temAppKey(), (string) $i->getUrlBase(), (string) $i->getUrlToken());
        if (null !== $falta) {
            throw new FalhaNoBanco($falta);
        }
        $appKey = $i->temAppKey() ? $this->cofre->decifrar((string) $i->getAppKeyCifrada()) : '';

        return new CredenciaisBacen(
            $this->codigo(),
            $i->getClientId(),
            $this->cofre->decifrar((string) $i->getClientSecretCifrado()),
            $this->cofre->decifrar((string) $i->getCertificadoCifrado()),
            $this->cofre->decifrar((string) $i->getChaveCifrada()),
            (string) $i->getChavePix(),
            $this->urlDoToken($i),
            $this->urlBase($i),
            $this->tokenPorBasic(),
            $this->parametrosDaApi($i, $appKey),
            $this->cabecalhosDaApi($i),
        );
    }
}
