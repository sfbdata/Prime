<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * A moldura de uma página do PDF final: fundo de segurança, cabeçalho do escritório e rodapé de validação
 * (`fundo` e `pe` do `pdf()`, dc:409-411).
 *
 * Uso por página (o montador faz isto; o bloco 1b só desenha o miolo pelo {@see PaginasDepoisDoConteudo}):
 *  1. {@see fundoECabecalho()} na {@see Tela} nova: fundo (ou branco, na folha branca) e, fora das duas colunas,
 *     logo ou marca, nome, endereço e telefone do escritório e a régua;
 *  2. o miolo, entre {@see TOPO} e {@see BASE};
 *  3. {@see rodape()}: QR, "CÓDIGO DE VALIDAÇÃO · PÁGINA n DE total", código, hash, instrução, emissão e selo.
 *     Nas duas colunas o rodapé é o "mini" (0,8 do tamanho, alinhado às colunas).
 */
final class MolduraDaFolha
{
    public const X = 92.0;
    public const L = 790.0;
    public const TOPO = 170.0;
    public const BASE = 1220.0;
    public const NV = '#0b3550';

    /** Margens das duas colunas (dc:399), onde o rodapé mini se alinha. */
    private const XL = 104.0;
    private const XR = 930.0;

    public function __construct(
        private readonly FundoDeSeguranca $fundo = new FundoDeSeguranca(),
        private readonly Selo $selo = new Selo(),
        private readonly QrDaValidacao $qr = new QrDaValidacao(),
    ) {
    }

    public function fundoECabecalho(Tela $tela, RecursosDaFolha $recursos, int $pagina, bool $branca, bool $semCabecalho): void
    {
        $folha = $recursos->folha;
        if (!$branca) {
            $this->fundo->desenhar($tela, $folha, $pagina);
        }

        if (!$semCabecalho) {
            $this->cabecalho($tela, $folha, $recursos->logo());
        }
    }

    public function rodape(Tela $tela, RecursosDaFolha $recursos, int $pagina, int $total, bool $mini): void
    {
        $folha = $recursos->folha;
        $textos = $folha->textos;
        $k = $mini ? .8 : 1.0;
        $lado = 116 * $k;
        $y0 = $mini ? Tela::H - 142 : Tela::H - 172;
        $x0 = $mini ? self::XL : self::X;
        $xt = $x0 + $lado + 24 * $k;
        $direita = $mini ? self::XR : self::X + self::L;

        $this->qr->desenhar($tela, $x0 + 4, $y0 + 4, $lado, $folha->urlDeValidacao);
        $tela->texto($textos->rotuloDoRodape($pagina, $total), $xt, $y0 + 14 * $k, Fonte::SansNegrito, 11 * $k, Cor::hex('#4a6373'));
        $this->codigo($tela, $folha->codigoImpresso(), $xt, $y0 + 42 * $k, $k);

        $hash = Cor::hex('#34505f');
        $tela->texto($textos->prefixoDoHash . substr($folha->hash, 0, 32), $xt, $y0 + 60 * $k, Fonte::Mono, 10.5 * $k, $hash);
        $tela->texto($textos->recuoDoHash . substr($folha->hash, 32), $xt, $y0 + 73 * $k, Fonte::Mono, 10.5 * $k, $hash);

        $texto = Cor::hex('#243845');
        $tela->texto($textos->confira, $xt, $y0 + 94 * $k, Fonte::Sans, 11 * $k, $texto);
        $tela->texto($textos->emitido($folha->emitidoEm), $xt, $y0 + 110 * $k, Fonte::Sans, 10 * $k, $texto);

        $this->selo->desenhar($tela, $direita - 62 * $k, $y0 + 60 * $k, 58 * $k, $folha);
    }

    /** Logo em (92, 60) com altura 64 e largura até 280; sem logo, "Blue" azul-marinho e "Jus" azul em negrito 34. */
    private function cabecalho(Tela $tela, FolhaParaMontar $folha, ?\GdImage $logo): void
    {
        $azul = Cor::hex(self::NV);
        $textos = $folha->textos;
        if ($logo !== null) {
            $tela->imagem($logo, self::X, 60, min(280, imagesx($logo) * 64 / imagesy($logo)), 64);
        } else {
            $tela->texto($textos->marcaAzul, self::X, 102, Fonte::SansNegrito, 34, $azul);
            $recuo = $tela->largura($textos->marcaAzul, Fonte::SansNegrito, 34);
            $tela->texto($textos->marcaClara, self::X + $recuo, 102, Fonte::SansNegrito, 34, Cor::hex('#0a6fa0'));
        }

        $escritorio = $folha->escritorio;
        $tela->texto($escritorio->nome, self::X + self::L, 84, Fonte::SansNegrito, 12.5, $azul, Alinhamento::Direita);
        $contato = implode(' · ', array_filter([$escritorio->endereco, $escritorio->telefone], static fn (string $v): bool => trim($v) !== ''));
        $tela->texto($contato, self::X + self::L, 101, Fonte::Sans, 11.5, $azul, Alinhamento::Direita);
        $tela->retangulo(self::X, 136, self::L, 1.6, $azul);
    }

    /** Cada caractere em Courier negrito, do tamanho 20 crescendo 0,85 por letra, com 1,5 de espaço entre eles. */
    private function codigo(Tela $tela, string $codigo, float $x, float $y, float $k): void
    {
        $vermelho = Cor::hex('#a3232b');
        foreach (mb_str_split($codigo) as $i => $letra) {
            $tamanho = (20 + $i * .85) * $k;
            $tela->texto($letra, $x, $y, Fonte::MonoNegrito, $tamanho, $vermelho);
            $x += $tela->largura($letra, Fonte::MonoNegrito, $tamanho) + 1.5 * $k;
        }
    }
}
