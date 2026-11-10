<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\DTO\CarimboDoAceiteNaFolha;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\ValidacaoDaFolha;
use App\Ponto\Service\ImagemDoComprovante\QrNaTela;
use App\Ponto\Service\ImagemDoComprovante\SeloHolografico;
use App\Ponto\Service\ImagemDoComprovante\SementeDoDesenho;

/**
 * O pé da folha em PDF (desenho 16 L696 a L703): a declaração com as duas assinaturas e o carimbo do aceite, a faixa de
 * validação (blocos holográficos, QR de 116 com o endereço da validação, "CÓDIGO DE VALIDAÇÃO", o código em vermelho
 * com letras que crescem, o HMAC em duas linhas, as duas frases e o selo holográfico) e a "Base Legal".
 *
 * A faixa de validação só entra na folha emitida com código; sem o segredo do HMAC ela sai inteira, com o selo, e a
 * "Base Legal" sobe para o lugar dela (decisão 16 da E16). O rótulo do HMAC é "HMAC-SHA256", como no comprovante
 * (decisão 21; o desenho diz "SHA-256").
 */
final class PeDaFolha
{
    private const X = ImagemDaFolha::X;
    private const R = ImagemDaFolha::DIREITA;
    private const VERMELHO = '#a3232b';
    private const VERDE = '#1e7b45';
    private const AZUL_ACINZENTADO = '#243845';
    private const HOLOGRAFICO = ['#7fb4cf', '#d9eef7', '#8fcaa5', '#e8dca8', '#7fb4cf'];
    private const LADO_DO_QR = 116;
    private const X_DO_SELO = 866;
    private const RAIO_DO_SELO = 58;

    public function __construct(
        private readonly PaginaDaFolha $pagina,
        private readonly SementeDoDesenho $sorteio,
    ) {
    }

    /** @param float $y o alto da caixa da declaração */
    public function desenhar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina, float $y): void
    {
        $this->declaracao($conteudo, $y);
        $y += 92;
        if ($conteudo->validacao !== null && $enderecoDoQr !== null) {
            $this->validacao($conteudo->validacao, $enderecoDoQr, $enderecoDaPagina, $y);
            $y += 130;
        }
        $this->baseLegal($y);
    }

    /** A caixa de 84 com a declaração, o "Local / Data", as duas assinaturas e o carimbo do aceite (L696 e L697). */
    private function declaracao(ConteudoDaFolha $conteudo, float $y): void
    {
        $this->pagina->quadro(self::X, $y, ImagemDaFolha::LARGURA_UTIL, 84);
        $this->pagina->texto(ConteudoDaFolha::DECLARACAO, 'normal', 11, self::X + 10, $y + 17);
        $this->pagina->texto(sprintf('Local: ______________________________     Data: ____/____/%04d', $conteudo->ano), 'normal', 11, self::X + 10, $y + 34);
        $this->assinatura('Assinatura do Empregado', $conteudo->cabecalho->nome, self::X + 16, self::R - 500 - 10, $y);
        $this->assinatura('Assinatura do Responsável', $conteudo->cabecalho->responsavel, self::R - 430, self::R - 16, $y);
        if ($conteudo->carimbo !== null) {
            $this->carimbo($conteudo->carimbo, (self::X + 16 + self::R - 500 - 10) / 2, $y);
        }
    }

    /**
     * A linha de assinatura em y + 56, o título em negrito embaixo e o nome em 10, centrado; o nome que não cabe na
     * largura da linha encolhe até 8 antes do "..." (decisões 26 e 27; Coordenador, 10/11/2026).
     */
    private function assinatura(string $titulo, string $nome, float $de, float $ate, float $y): void
    {
        $this->pagina->retangulo($de, $y + 56, $ate - $de, .8, PaginaDaFolha::TEXTO);
        $this->pagina->texto($titulo, 'negrito', 11, ($de + $ate) / 2, $y + 68, PaginaDaFolha::TEXTO, true);
        $this->pagina->linhaQueCabe($nome, 'normal', 10, 8, ($de + $ate) / 2, $y + 80, $ate - $de, PaginaDaFolha::TEXTO, true);
    }

    /** "Aceite eletrônico em ..." em verde ou "Aceite com ressalva em ..." em vermelho, e a ressalva cortada em 480. */
    private function carimbo(CarimboDoAceiteNaFolha $carimbo, float $centro, float $y): void
    {
        $this->pagina->texto($carimbo->texto(), 'negrito', 10.5, $centro, $y + 51, $carimbo->concordou() ? self::VERDE : self::VERMELHO, true);
        $ressalva = $carimbo->textoDaRessalva();
        if ($ressalva !== null) {
            $this->pagina->texto($this->pagina->caber(self::umaLinha($ressalva), 'normal', 10, 480), 'normal', 10, self::R - 496, $y + 34, self::VERMELHO);
        }
    }

    /** A faixa de blocos, o QR, o código, o HMAC, as duas frases e o selo (L698 a L702). */
    private function validacao(ValidacaoDaFolha $validacao, string $enderecoDoQr, string $enderecoDaPagina, float $y): void
    {
        $this->faixaHolografica($y);
        (new QrNaTela($this->pagina->tela()))->desenhar($enderecoDoQr, self::X, $y, self::LADO_DO_QR);
        $this->pagina->marcar('qr');
        $x = self::X + self::LADO_DO_QR + 24;
        $this->pagina->texto(ValidacaoDaFolha::TITULO, 'negrito', 11, $x, $y + 14, '#4a6373');
        $this->codigoCrescente($validacao->codigo, $x, $y + 42);
        $this->pagina->texto(ValidacaoDaFolha::ROTULO_DO_SELO . ' ' . substr($validacao->hmac, 0, 32), 'mono', 10.5, $x, $y + 60, '#34505f');
        $this->pagina->texto(str_repeat(' ', mb_strlen(ValidacaoDaFolha::ROTULO_DO_SELO) + 1) . substr($validacao->hmac, 32), 'mono', 10.5, $x, $y + 73, '#34505f');
        $limite = self::X_DO_SELO - self::RAIO_DO_SELO - 8 - $x;
        $this->pagina->linhaQueCabe('Confira em ' . $enderecoDaPagina . ' ou leia o QR Code.', 'normal', 11, 8, $x, $y + 94, $limite, self::AZUL_ACINZENTADO);
        $this->pagina->linhaQueCabe($validacao->textoDaEmissao(), 'normal', 10, 8, $x, $y + 110, $limite, self::AZUL_ACINZENTADO);
        (new SeloHolografico($this->pagina->tela(), $this->sorteio))->desenhar(self::X_DO_SELO, $y + 60, 1.0, $validacao->codigo);
        $this->pagina->marcar('selo');
    }

    /** Blocos de 86 x 6,5 a cada 92, com "BLUEJUS" em negrito 5,2, logo acima do QR (L698). */
    private function faixaHolografica(float $y): void
    {
        for ($x = self::X; $x + 86 <= self::R + 1; $x += 92) {
            $this->pagina->tela()->degrade($x, $y - 7.5, 86, 6.5, self::HOLOGRAFICO, true);
            $this->pagina->texto('BLUEJUS', 'negrito', 5.2, $x + 43, $y - 2.6, PaginaDaFolha::AZUL_ESCURO, true);
        }
        $this->pagina->marcar('faixa-de-validacao');
    }

    /** Cada letra do código um pouco maior que a anterior, em vermelho, monoespaçada (L699). */
    private function codigoCrescente(string $codigo, float $x, float $y): void
    {
        foreach (str_split($codigo) as $i => $letra) {
            $tamanho = 20 + $i * .85;
            $this->pagina->texto($letra, 'mono-negrito', $tamanho, $x, $y, self::VERMELHO);
            $x += $this->pagina->largura($letra, 'mono-negrito', $tamanho) + 1.5;
        }
    }

    /** "Base Legal:" e as normas numa caixa de 38, com a nota embaixo (L703). */
    private function baseLegal(float $y): void
    {
        $this->pagina->quadro(self::X, $y, ImagemDaFolha::LARGURA_UTIL, 38);
        $this->pagina->texto('Base Legal:', 'negrito', 10.5, self::X + 8, $y + 15);
        $depoisDoRotulo = self::X + 8 + $this->pagina->largura('Base Legal: ', 'negrito', 10.5);
        $this->pagina->texto(ConteudoDaFolha::BASE_LEGAL, 'normal', 10.5, max(self::X + 72, $depoisDoRotulo), $y + 15);
        $this->pagina->texto(ConteudoDaFolha::NOTA_DA_BASE_LEGAL, 'normal', 10, self::X + 8, $y + 30);
    }

    /** A ressalva numa linha só: as quebras viram espaço (o PDF mostra uma linha cortada, decisão 19). */
    private static function umaLinha(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
