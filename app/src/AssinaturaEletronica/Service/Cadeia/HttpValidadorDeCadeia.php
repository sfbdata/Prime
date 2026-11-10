<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * O cliente do contêiner `validador_pdf` (etapa 6, bloco 6B; protocolo `v1` da seção 2 da spec
 * `docs/specs/assinatura-documentos-etapa-6.md`). Cliente HTTP nomeado `assinatura_validador.client`
 * (`config/packages/assinatura_eletronica.yaml`): 45 s de teto e nenhum redirecionamento.
 *
 * Configuração (E6-4): `ASSINATURA_VALIDADOR_URL` e `ASSINATURA_VALIDADOR_TOKEN`, sem os espaços das pontas; vazia ou
 * só com espaços = não configurado, e o envio recusa gov.br e ICP.
 *
 * As respostas seguem as classes da seção 2.1:
 * - **resultado**: `200` com as assinaturas conferidas;
 * - **definitivo**: `400 pdf_ilegivel` e `413 pdf_grande_demais` voltam como {@see ResultadoDaCadeia} com o erro (o
 *   mesmo PDF dá o mesmo resultado); o PDF acima de 20 MB nem é enviado;
 * - **passageiro**: `500 erro_interno`, `503 ocupado`, `503 tempo_esgotado`, `503 ancoras_indisponiveis`, falha de
 *   transporte e tempo do cliente esgotado viram {@see CadeiaIndisponivel}, com aviso no log;
 * - **cliente ou configuração**: o resto (`401`, `404`, `405`, `411`, `400 parametro_invalido`, a resposta em HTML da
 *   biblioteca do validador, um redirecionamento, o `200` fora do protocolo) também vira {@see CadeiaIndisponivel},
 *   com erro no log: é um problema do nosso lado.
 *
 * O log leva só rota, âncora, situação, código do erro, tamanho e tempo; nunca o PDF, o token ou o corpo da resposta.
 */
final class HttpValidadorDeCadeia implements ValidadorDeCadeia
{
    /** O teto do validador (seção 2: acima disso, `413`). O motor já recusa antes (E5-8). */
    public const TETO_DO_PDF = 20 * 1024 * 1024;

    public const ERRO_PDF_GRANDE_DEMAIS = 'pdf_grande_demais';

    private const ROTA = '/v1/conferir';

    private const ANCORAS = [self::ANCORA_ICP, self::ANCORA_GOVBR, self::ANCORA_QUALQUER];

    /** @var array<int, list<string>> situação => códigos que não mudam se o mesmo PDF for mandado de novo */
    private const DEFINITIVOS = [
        400 => [ResultadoDaCadeia::ERRO_PDF_ILEGIVEL],
        413 => [self::ERRO_PDF_GRANDE_DEMAIS],
    ];

    /** @var array<int, list<string>> situação => códigos de "tente de novo em alguns minutos" */
    private const PASSAGEIROS = [
        500 => ['erro_interno'],
        503 => ['ocupado', 'tempo_esgotado', 'ancoras_indisponiveis'],
    ];

    private readonly string $url;

    private readonly string $token;

    public function __construct(
        #[Target('assinatura_validador.client')]
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'ASSINATURA_VALIDADOR_URL')]
        string $url,
        #[Autowire(env: 'ASSINATURA_VALIDADOR_TOKEN')]
        string $token,
    ) {
        $this->url = rtrim(trim($url), '/');
        $this->token = trim($token);
    }

    public function configurado(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    public function conferir(string $pdf, string $ancora): ResultadoDaCadeia
    {
        if (!\in_array($ancora, self::ANCORAS, true)) {
            throw new \InvalidArgumentException(sprintf('Âncora fora do protocolo do validador de cadeia: "%s".', $ancora));
        }
        if (!$this->configurado()) {
            throw new CadeiaIndisponivel('O validador de cadeia não está configurado.');
        }
        $contexto = ['rota' => self::ROTA, 'ancora' => $ancora, 'tamanho' => \strlen($pdf)];
        if (\strlen($pdf) > self::TETO_DO_PDF) {
            $this->logger->info('Validador de cadeia: PDF acima do teto, não enviado.', $contexto);

            return new ResultadoDaCadeia([], self::ERRO_PDF_GRANDE_DEMAIS);
        }

        $inicio = hrtime(true);
        [$situacao, $corpo, $tipo] = $this->enviar($pdf, $ancora, $contexto, $inicio);
        $contexto += ['situacao' => $situacao, 'ms' => self::ms($inicio)];

        return $this->classificar($situacao, self::doProtocolo($corpo, $tipo), $contexto);
    }

    /**
     * O `POST` do protocolo; falha de transporte ou tempo do cliente esgotado é passageiro.
     *
     * @param array<string, mixed> $contexto
     *
     * @return array{int, string, string} situação, corpo e `Content-Type` da resposta
     *
     * @throws CadeiaIndisponivel
     */
    private function enviar(string $pdf, string $ancora, array $contexto, int $inicio): array
    {
        try {
            $resposta = $this->http->request('POST', $this->url . self::ROTA, [
                'query' => ['ancora' => $ancora],
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/pdf'],
                'body' => $pdf,
            ]);

            return [$resposta->getStatusCode(), $resposta->getContent(false), $resposta->getHeaders(false)['content-type'][0] ?? ''];
        } catch (TransportExceptionInterface $falha) {
            $this->logger->warning('Validador de cadeia fora do ar ou sem resposta a tempo.', $contexto + [
                'ms' => self::ms($inicio),
                'falha' => $falha::class,
            ]);

            throw new CadeiaIndisponivel('O validador de cadeia não respondeu.', 0, $falha);
        }
    }

    /**
     * A classe da resposta (seção 2.1): resultado, definitivo, passageiro ou do nosso lado.
     *
     * @param array<string, mixed> $contexto
     *
     * @throws CadeiaIndisponivel passageiro, cliente, configuração ou fora do protocolo
     */
    private function classificar(int $situacao, ?ResultadoDaCadeia $resultado, array $contexto): ResultadoDaCadeia
    {
        if ($situacao === 200 && $resultado !== null && $resultado->erro === null) {
            $this->logger->info('Validador de cadeia: conferência feita.', $contexto + ['assinaturas' => \count($resultado->assinaturas)]);

            return $resultado;
        }
        $erro = $situacao === 200 ? null : $resultado?->erro;
        $contexto += ['erro' => $erro];
        if ($erro !== null && \in_array($erro, self::DEFINITIVOS[$situacao] ?? [], true)) {
            $this->logger->info('Validador de cadeia: PDF recusado em definitivo.', $contexto);

            return new ResultadoDaCadeia([], $erro);
        }
        if ($erro !== null && \in_array($erro, self::PASSAGEIROS[$situacao] ?? [], true)) {
            $this->logger->warning('Validador de cadeia indisponível por enquanto.', $contexto);

            throw new CadeiaIndisponivel(sprintf('O validador de cadeia respondeu %d %s.', $situacao, $erro));
        }
        $this->logger->error('Validador de cadeia: resposta de erro do nosso lado (cliente ou configuração) ou fora do protocolo.', $contexto);

        throw new CadeiaIndisponivel(sprintf('O validador de cadeia respondeu %d fora do esperado.', $situacao));
    }

    /** O corpo como resultado do protocolo `v1`; nulo se não for JSON do protocolo. */
    private static function doProtocolo(string $corpo, string $tipo): ?ResultadoDaCadeia
    {
        if (!str_starts_with(strtolower(trim($tipo)), 'application/json')) {
            return null;
        }
        try {
            $json = json_decode($corpo, true, 64, \JSON_THROW_ON_ERROR);

            return \is_array($json) ? ResultadoDaCadeia::deJson($json) : null;
        } catch (\JsonException|\UnexpectedValueException) {
            return null;
        }
    }

    private static function ms(int $inicio): int
    {
        return intdiv(hrtime(true) - $inicio, 1_000_000);
    }
}
