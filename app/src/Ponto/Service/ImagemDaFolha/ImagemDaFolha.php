<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\Service\ImagemDoComprovante\SementeDoDesenho;
use App\Ponto\Service\ImagemDoComprovante\TelaDoComprovante;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A folha do mês em imagem JPEG, desenhada no servidor (spec ponto-desenho-0811, E16, P34, D17 e decisão 5), no arranjo
 * do desenho 16 (`desenharFolha`, L634 a L705): 1000 x 1414 na escala 2,48 (2480 x 3507 pixels), qualidade 92 (L723),
 * com as camadas de segurança do comprovante, depois embrulhada num PDF de uma página ({@see \App\Ponto\Service\PdfDeUmaImagem}).
 * Só desenha: todo o texto vem pronto em {@see ConteudoDaFolha}.
 *
 * Os padrões saem do selo da folha emitida (o `rec.hash` do desenho), então a mesma emissão gera sempre a mesma imagem.
 * Sem o segredo do HMAC (decisão 16), a semente é o sha256 da competência, do nome, do empregador e da impressão dos
 * registros: a mesma folha, o mesmo fundo.
 *
 * Fora do desenho, só as fontes: as DejaVu do dompdf no lugar da Arial, como no comprovante (o servidor não tem a
 * Arial). Por ser mais larga, o texto que não cabe na célula é cortado com "...", como o `cap` do desenho.
 */
final class ImagemDaFolha implements DesenhoDaFolha
{
    public const ESCALA = 2.48;
    public const LARGURA = 1000;
    public const ALTURA = 1414;
    public const QUALIDADE_JPEG = 92;
    public const X = 46;
    public const DIREITA = 936;
    public const LARGURA_UTIL = self::DIREITA - self::X;
    /** Os quadros do pé nunca sobem acima disto, mesmo em fevereiro (L686). */
    private const TOPO_DOS_QUADROS = 964;

    /** @var array<string, string> */
    private readonly array $fontes;

    public function __construct(
        #[Autowire('%kernel.project_dir%/vendor/dompdf/dompdf/lib/fonts')]
        string $pastaDasFontes,
    ) {
        $this->fontes = [
            'normal'       => $pastaDasFontes . '/DejaVuSans.ttf',
            'negrito'      => $pastaDasFontes . '/DejaVuSans-Bold.ttf',
            'mono'         => $pastaDasFontes . '/DejaVuSansMono.ttf',
            'mono-negrito' => $pastaDasFontes . '/DejaVuSansMono-Bold.ttf',
        ];
    }

    public function gerar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina, float $escala = self::ESCALA): string
    {
        return $this->desenhar($conteudo, $enderecoDoQr, $enderecoDaPagina, $escala)->jpeg();
    }

    /** A página desenhada, antes do JPEG: os testes leem nela o que foi escrito e onde. */
    public function desenhar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina, float $escala = self::ESCALA): PaginaDaFolha
    {
        $pagina = new PaginaDaFolha(new TelaDoComprovante($escala, $this->fontes, self::ALTURA, self::QUALIDADE_JPEG));
        $sorteio = new SementeDoDesenho(self::semente($conteudo));
        (new PadroesDaFolha($pagina, $sorteio))->desenhar($conteudo->validacao?->codigo, $conteudo->cabecalho->nome);
        (new AltoDaFolha($pagina))->desenhar($conteudo);
        $fimDaTabela = (new TabelaDaFolha($pagina))->desenhar($conteudo->linhas);
        $topoDosQuadros = max(self::TOPO_DOS_QUADROS, $fimDaTabela + 6);
        (new QuadrosDaFolha($pagina))->desenhar($conteudo, $topoDosQuadros);
        (new PeDaFolha($pagina, $sorteio))->desenhar($conteudo, $enderecoDoQr, $enderecoDaPagina, $topoDosQuadros + QuadrosDaFolha::ALTURA + 8);

        return $pagina;
    }

    /** O selo HMAC da folha emitida; sem ele, o sha256 do que identifica a folha (decisão 16). */
    public static function semente(ConteudoDaFolha $conteudo): string
    {
        if ($conteudo->validacao !== null) {
            return $conteudo->validacao->hmac;
        }

        return hash('sha256', implode("\n", [
            $conteudo->competenciaFormatada(),
            $conteudo->cabecalho->nome,
            $conteudo->cabecalho->empregador,
            $conteudo->impressaoDosRegistros(),
        ]));
    }
}
