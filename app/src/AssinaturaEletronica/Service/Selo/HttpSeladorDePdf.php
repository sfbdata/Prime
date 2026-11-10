<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as ExcecaoDeHttp;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * O selo pelo contêiner `validador_pdf` (spec assinatura-documentos-etapa-8, protocolo da seção 2): `POST /v1/selar`
 * com o token da etapa 6 e o PDF no corpo. A classe de cada resposta é a da tabela 2.1 da etapa 6:
 *
 * - `200` com `application/pdf`: o PDF selado, que precisa começar com os bytes do original e ser maior que ele (o selo
 *   é acréscimo incremental, E8-1). Se não for assim, ou se o `200` não for PDF, falha passageira e `logger->error`;
 * - `503 selo_nao_configurado` (só ele): nulo, como sem configuração;
 * - `503 selo_certificado_invalido`: classe própria (E8-4);
 * - os demais códigos do protocolo, pela tabela {@see RESPOSTAS}; as respostas HTML da biblioteca padrão do validador
 *   (`400`, `414`, `431`, `505`) são erro de cliente; qualquer outra resposta sem `{"v":1}`, erro de transporte e
 *   tempo esgotado (45 s) são passageiros.
 *
 * Tempo: os 45 s da etapa 6 (seção 2.1) são o teto do pedido inteiro (`max_duration`). No cliente curl, o estouro
 * desse teto chega como erro de transporte e vira `falha_de_transporte`, igual ao validador fora do ar; só a espera
 * sem nenhum byte (`timeout`, que conta depois do envio) chega como tempo esgotado (`tempo_esgotado_no_cliente`). A
 * classe é passageira nos dois casos.
 *
 * PDF acima de 20 MB é falha definitiva sem pedido. Sem redirecionamento (o token não segue para outro endereço). O
 * token com caractere fora do visível ASCII nunca vai para o cabeçalho: o cliente HTTP recusaria citando o valor. O
 * conteúdo do PDF e o token nunca vão para o log nem para a exceção.
 */
final class HttpSeladorDePdf implements SeladorDePdf
{
    public const ROTA = '/v1/selar';
    public const PDF_MAXIMO_EM_BYTES = 20 * 1024 * 1024;
    public const TEMPO_MAXIMO_EM_SEGUNDOS = 45.0;

    /**
     * Os espaços das pontas que saem da URL e do token: os do `trim()` padrão e o `\x0C` (avanço de página), como o
     * cliente da cadeia da etapa 6 (`HttpValidadorDeCadeia`), que lê as mesmas variáveis.
     */
    public const ESPACOS = " \t\n\r\0\x0B\x0C";

    // Códigos locais, para o que não é resposta do validador.
    public const PDF_GRANDE_DEMAIS = 'pdf_grande_demais';
    public const TEMPO_ESGOTADO_NO_CLIENTE = 'tempo_esgotado_no_cliente';
    public const FALHA_DE_TRANSPORTE = 'falha_de_transporte';
    public const FORA_DO_PROTOCOLO = 'resposta_fora_do_protocolo';
    public const SEM_O_ORIGINAL = 'selado_sem_o_original';
    public const CONFIGURACAO_INVALIDA = 'configuracao_invalida';
    public const NAO_CONFIGURADO = 'selo_nao_configurado';

    /** As respostas `{"v":1,"erro":...}` do protocolo, pela situação HTTP e pelo código (tabela 2.1 da etapa 6). */
    private const RESPOSTAS = [
        400 => ['pdf_ilegivel' => ClasseDaFalhaNoSelo::Definitiva, 'parametro_invalido' => ClasseDaFalhaNoSelo::Configuracao],
        401 => ['nao_autorizado' => ClasseDaFalhaNoSelo::Configuracao],
        404 => ['nao_encontrado' => ClasseDaFalhaNoSelo::Configuracao],
        405 => ['metodo_nao_permitido' => ClasseDaFalhaNoSelo::Configuracao],
        411 => ['tamanho_ausente' => ClasseDaFalhaNoSelo::Configuracao],
        413 => ['pdf_grande_demais' => ClasseDaFalhaNoSelo::Definitiva],
        422 => ['nao_selavel' => ClasseDaFalhaNoSelo::Definitiva],
        500 => ['erro_interno' => ClasseDaFalhaNoSelo::Passageira],
        502 => ['carimbo_indisponivel' => ClasseDaFalhaNoSelo::Passageira],
        503 => [
            'ocupado' => ClasseDaFalhaNoSelo::Passageira,
            'tempo_esgotado' => ClasseDaFalhaNoSelo::Passageira,
            'ancoras_indisponiveis' => ClasseDaFalhaNoSelo::Passageira,
            'selo_certificado_invalido' => ClasseDaFalhaNoSelo::CertificadoInvalido,
        ],
    ];

    /** Pedido HTTP malformado: a biblioteca padrão do validador responde em HTML, antes dele (erro de cliente). */
    private const RESPOSTAS_HTML_DE_CLIENTE = [400, 414, 431, 505];

    private readonly string $url;
    private readonly string $token;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        string $url,
        string $token,
    ) {
        $this->url = rtrim(trim($url, self::ESPACOS), '/');
        $this->token = trim($token, self::ESPACOS);
    }

    public function selar(string $pdf): ?string
    {
        $this->exigirPedidoPossivel($pdf);
        [$situacao, $tipo, $corpo] = $this->pedir($pdf);
        if ($situacao === 200) {
            return $this->conferirSelado($pdf, $tipo, $corpo);
        }

        $erro = self::erroDoProtocolo($corpo);
        if ($situacao === 503 && $erro === self::NAO_CONFIGURADO) {
            return null;
        }

        throw self::falhaDaResposta($situacao, $erro);
    }

    /** O que nem chega a virar pedido: PDF grande demais (definitiva) ou endereço e token que não servem (configuração). */
    private function exigirPedidoPossivel(string $pdf): void
    {
        if (\strlen($pdf) > self::PDF_MAXIMO_EM_BYTES) {
            throw new FalhaNoSelo(self::PDF_GRANDE_DEMAIS, ClasseDaFalhaNoSelo::Definitiva);
        }
        if (preg_match('#^https?://[^\s/]+#i', $this->url) !== 1 || preg_match('/^[\x21-\x7E]+$/', $this->token) !== 1) {
            throw new FalhaNoSelo(self::CONFIGURACAO_INVALIDA, ClasseDaFalhaNoSelo::Configuracao);
        }
    }

    /**
     * O pedido e a resposta inteira. A exceção do cliente HTTP não é encadeada (ver o docblock de {@see FalhaNoSelo}).
     *
     * @return array{int, string, string} a situação HTTP, o `Content-Type` e o corpo
     */
    private function pedir(string $pdf): array
    {
        try {
            $resposta = $this->http->request('POST', $this->url . self::ROTA, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/pdf'],
                'body' => $pdf,
                'timeout' => self::TEMPO_MAXIMO_EM_SEGUNDOS,
                'max_duration' => self::TEMPO_MAXIMO_EM_SEGUNDOS,
                'max_redirects' => 0,
            ]);
            $situacao = $resposta->getStatusCode();
            $tipo = $resposta->getHeaders(false)['content-type'][0] ?? '';
            $corpo = $resposta->getContent(false);
        } catch (TimeoutExceptionInterface) {
            throw new FalhaNoSelo(self::TEMPO_ESGOTADO_NO_CLIENTE, ClasseDaFalhaNoSelo::Passageira);
        } catch (ExcecaoDeHttp) {
            throw new FalhaNoSelo(self::FALHA_DE_TRANSPORTE, ClasseDaFalhaNoSelo::Passageira);
        }

        return [$situacao, $tipo, $corpo];
    }

    /** O `200`: só vale o PDF que começa com o original e cresce (acréscimo incremental, E8-1). */
    private function conferirSelado(string $original, string $tipo, string $selado): string
    {
        if (strtolower(trim(explode(';', $tipo)[0])) !== 'application/pdf') {
            $this->logger->error('Selo do PDF final: o validador respondeu 200 sem PDF ({tipo}); confira a URL do validador.', [
                'tipo' => mb_substr($tipo, 0, 100),
            ]);

            throw new FalhaNoSelo(self::FORA_DO_PROTOCOLO, ClasseDaFalhaNoSelo::Passageira, 200);
        }
        if (\strlen($selado) <= \strlen($original) || !str_starts_with($selado, $original)) {
            $this->logger->error('Selo do PDF final: o validador devolveu um PDF que não começa com o original ({original} bytes enviados, {selado} recebidos).', [
                'original' => \strlen($original),
                'selado' => \strlen($selado),
            ]);

            throw new FalhaNoSelo(self::SEM_O_ORIGINAL, ClasseDaFalhaNoSelo::Passageira, 200);
        }

        return $selado;
    }

    /** O `erro` de um corpo `{"v":1,"erro":"..."}`; nulo para qualquer outro corpo (HTML, vazio, outra versão). */
    private static function erroDoProtocolo(string $corpo): ?string
    {
        try {
            $json = json_decode($corpo, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($json) || ($json['v'] ?? null) !== 1 || !\is_string($json['erro'] ?? null) || $json['erro'] === '') {
            return null;
        }

        return $json['erro'];
    }

    private static function falhaDaResposta(int $situacao, ?string $erro): FalhaNoSelo
    {
        if ($erro === null) {
            return \in_array($situacao, self::RESPOSTAS_HTML_DE_CLIENTE, true)
                ? new FalhaNoSelo('http_' . $situacao, ClasseDaFalhaNoSelo::Configuracao, $situacao)
                : new FalhaNoSelo(self::FORA_DO_PROTOCOLO, ClasseDaFalhaNoSelo::Passageira, $situacao);
        }

        $classe = self::RESPOSTAS[$situacao][$erro] ?? null;

        return $classe === null
            ? new FalhaNoSelo(self::FORA_DO_PROTOCOLO, ClasseDaFalhaNoSelo::Passageira, $situacao)
            : new FalhaNoSelo($erro, $classe, $situacao);
    }
}
