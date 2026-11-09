<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

use App\Ponto\DTO\DadosDoComprovante;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O comprovante da batida em imagem PNG, gerado no servidor (E15, P49 e D9), no arranjo do desenho 16 (`desenhar`,
 * L550 a L590): 1000 x 1480 na escala 2,48 (2480 x 3670 pixels). Os padrões de segurança saem do selo do próprio
 * comprovante, então o mesmo comprovante gera sempre a mesma imagem.
 *
 * Fica fora do desenho: a "LOCAL DO REGISTRO" (o comprovante não leva localização, P50) e as fontes, que são as
 * DejaVu do dompdf no lugar da Arial (o servidor não tem a Arial). O QR leva o endereço do próprio sistema (D20).
 */
final class ImagemDoComprovante
{
    public const ESCALA = 2.48;
    private const X = 92;
    private const QR_K = 1.4;
    private const AZUL_ESCURO = '#0b3550';
    private const AZUL = '#0a6fa0';
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

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

    /**
     * @param string $selo             o HMAC do comprovante (hexadecimal): semente dos padrões e linha impressa
     * @param string $enderecoDoQr     endereço absoluto da validação deste código (o QR)
     * @param string $enderecoDaPagina a página de validação, sem o protocolo, para o texto "Confira em ..."
     */
    public function gerar(DadosDoComprovante $dados, string $selo, string $enderecoDoQr, string $enderecoDaPagina, float $escala = self::ESCALA): string
    {
        $tela = new TelaDoComprovante($escala, $this->fontes);
        $sorteio = new SementeDoDesenho($selo);
        (new PadroesDeSeguranca($tela, $sorteio))->desenhar($dados->codigo, $dados->nome);
        $this->cabecalho($tela);
        $this->batida($tela, $dados);
        $this->campos($tela, $dados);
        $this->rodape($tela, $dados, $selo, $enderecoDoQr, $enderecoDaPagina);
        (new SeloHolografico($tela, $sorteio))->desenhar(self::X + 812 - 58 * self::QR_K, TelaDoComprovante::ALTURA - 252 + 60 * self::QR_K, self::QR_K, $dados->codigo);

        return $tela->png();
    }

    /** Símbolo, "BlueJus" em duas cores, a linha do sistema e o fio grosso (L571). */
    private function cabecalho(TelaDoComprovante $tela): void
    {
        (new SimboloBlueJus($tela))->desenhar(self::X + 34, 120, 62, $tela->cor(self::AZUL_ESCURO), $tela->cor(self::AZUL), 6.5);
        $tela->texto('Blue', 'negrito', 48, self::X + 80, 136, $tela->cor(self::AZUL_ESCURO));
        $tela->texto('Jus', 'negrito', 48, self::X + 80 + $tela->largura('Blue', 'negrito', 48), 136, $tela->cor(self::AZUL));
        $tela->texto('sistema de gestão jurídica e de arquivos', 'normal', 16, self::X + 80, 160, $tela->cor('#34505f'));
        $tela->retangulo(self::X, 210, 770, 3, $tela->cor(self::AZUL_ESCURO));
    }

    /** Título, tipo, a hora grande em degradê e a data por extenso (L572 a L574). */
    private function batida(TelaDoComprovante $tela, DadosDoComprovante $dados): void
    {
        $tela->texto('COMPROVANTE DE REGISTRO DE PONTO', 'negrito', 30, self::X, 260, $tela->cor(self::AZUL_ESCURO));
        $tela->texto(mb_strtoupper($dados->nomeDoTipo()), 'negrito', 34, self::X, 322, $tela->cor(self::AZUL));
        $tela->textoEmDegrade($dados->dataHora->format('H:i:s'), 'negrito', 132, self::X, 444, 110, self::AZUL_ESCURO, self::AZUL);
        $tela->texto(self::dataPorExtenso($dados->dataHora), 'normal', 26, self::X, 484, $tela->cor('#12242f'));
        $tela->retangulo(self::X, 510, 770, 1, $tela->cor(self::AZUL_ESCURO, .3));
    }

    /** TRABALHADOR, EMPREGADOR e MÉTODO · NSR, cada valor encolhendo até caber em 770 (L575 a L579). */
    private function campos(TelaDoComprovante $tela, DadosDoComprovante $dados): void
    {
        $cnpj = $dados->cnpjFormatado();
        $metodo = $dados->metodo === DadosDoComprovante::METODO_FACIAL ? 'Reconhecimento facial' : 'Registro no sistema';
        $this->campo($tela, 'TRABALHADOR', $dados->nome, 552);
        $this->campo($tela, 'EMPREGADOR', $dados->escritorio . ($cnpj !== null ? ' · CNPJ ' . $cnpj : ''), 622);
        $this->campo($tela, 'MÉTODO · NSR', $metodo . ' · ' . $dados->nsrFormatado(), 692);
    }

    private function campo(TelaDoComprovante $tela, string $rotulo, string $valor, float $y): void
    {
        $tela->texto($rotulo, 'negrito', 14, self::X, $y, $tela->cor('#4a6373'));
        $tamanho = 24.0;
        while ($tamanho > 14 && $tela->largura($valor, 'normal', $tamanho) > 770) {
            --$tamanho;
        }
        $tela->texto($valor, 'normal', $tamanho, self::X, $y + 29, $tela->cor('#12242f'));
    }

    /** Faixa holográfica, QR, código em vermelho que cresce, selo em hexadecimal e as duas linhas finais (L581 a L586). */
    private function rodape(TelaDoComprovante $tela, DadosDoComprovante $dados, string $selo, string $enderecoDoQr, string $enderecoDaPagina): void
    {
        $k = self::QR_K;
        $y0 = TelaDoComprovante::ALTURA - 252;
        $xT = self::X + 116 * $k + 24 * $k;
        $this->faixaHolografica($tela, $y0);
        $this->qr($tela, $enderecoDoQr, self::X, $y0, 116 * $k);
        $tela->texto('CÓDIGO DE VALIDAÇÃO', 'negrito', 11 * $k, $xT, $y0 + 14 * $k, $tela->cor('#4a6373'));
        $this->codigoCrescente($tela, $dados->codigo, $xT, $y0 + 42 * $k);
        $tela->texto('HMAC-SHA256 ' . substr($selo, 0, 32), 'mono', 10.5 * $k, $xT, $y0 + 60 * $k, $tela->cor('#34505f'));
        $tela->texto(str_repeat(' ', 12) . substr($selo, 32), 'mono', 10.5 * $k, $xT, $y0 + 73 * $k, $tela->cor('#34505f'));
        $this->linhaQueCabe($tela, 'Confira em ' . $enderecoDaPagina . ' ou leia o QR Code.', 11 * $k, $xT, $y0 + 94 * $k);
        $this->linhaQueCabe($tela, 'Emitido em ' . $dados->dataHora->format('d/m/Y, H:i:s') . '. Vale somente com registro no BlueJus.', 9.4 * $k, $xT, $y0 + 110 * $k);
    }

    private function faixaHolografica(TelaDoComprovante $tela, float $y0): void
    {
        $cor = $tela->cor(self::AZUL_ESCURO);
        for ($x = self::X; $x + 96 <= self::X + 812; $x += 104) {
            $tela->degrade($x, $y0 - 22, 96, 13, ['#7fb4cf', '#d9eef7', '#8fcaa5', '#e8dca8', '#7fb4cf'], true);
            $tela->texto('BLUEJUS', 'negrito', 9.5, $x + 48, $y0 - 12, $cor, 0.0, true);
        }
    }

    private function qr(TelaDoComprovante $tela, string $endereco, float $x, float $y, float $lado): void
    {
        $matriz = Encoder::encode($endereco, ErrorCorrectionLevel::M())->getMatrix();
        $modulos = $matriz->getWidth();
        $u = $lado / $modulos;
        $tela->retangulo($x, $y, $lado + 8, $lado + 8, $tela->cor('#ffffff'));
        $escuro = $tela->cor(self::AZUL_ESCURO);
        for ($linha = 0; $linha < $modulos; ++$linha) {
            for ($coluna = 0; $coluna < $modulos; ++$coluna) {
                if ($matriz->get($coluna, $linha) === 1) {
                    $tela->retangulo($x + 4 + $coluna * $u, $y + 4 + $linha * $u, $u + .25, $u + .25, $escuro);
                }
            }
        }
    }

    /** Cada letra do código um pouco maior que a anterior, em vermelho, monoespaçada (L583). */
    private function codigoCrescente(TelaDoComprovante $tela, string $codigo, float $x, float $y): void
    {
        $cor = $tela->cor('#a3232b');
        foreach (str_split($codigo) as $i => $letra) {
            $tamanho = (20 + $i * .85) * self::QR_K;
            $tela->texto($letra, 'mono-negrito', $tamanho, $x, $y, $cor);
            $x += $tela->largura($letra, 'mono-negrito', $tamanho) + 1.5 * self::QR_K;
        }
    }

    /** A linha encolhe até caber antes do selo (a DejaVu é mais larga que a Arial do desenho). */
    private function linhaQueCabe(TelaDoComprovante $tela, string $texto, float $tamanho, float $x, float $y): void
    {
        $limite = self::X + 812 - 58 * self::QR_K * 2 - $x - 8;
        while ($tamanho > 8 && $tela->largura($texto, 'normal', $tamanho) > $limite) {
            $tamanho -= .5;
        }
        $tela->texto($texto, 'normal', $tamanho, $x, $y, $tela->cor('#243845'));
    }

    /** "segunda-feira, 09 de novembro de 2026", como o `toLocaleDateString('pt-BR', ...)` do desenho (L574). */
    private static function dataPorExtenso(\DateTimeImmutable $data): string
    {
        return sprintf('%s, %s de %s de %s', self::DIAS[(int) $data->format('w')], $data->format('d'), self::MESES[(int) $data->format('n') - 1], $data->format('Y'));
    }
}
