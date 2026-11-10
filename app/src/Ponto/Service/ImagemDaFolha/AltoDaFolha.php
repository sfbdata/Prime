<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\DTO\CabecalhoDaFolha;
use App\Ponto\DTO\ConteudoDaFolha;

/**
 * O alto da folha em PDF (desenho 16 L659 a L666): a faixa do título, o período, o empregador com o endereço, o quadro
 * "REGIME DE JORNADA" e os "DADOS DO EMPREGADO" em quatro linhas de células com as larguras do desenho.
 *
 * Fora do desenho, só o corte: a razão social encolhe de 16 até 11 (L662) e, se ainda não couber em 580, é cortada com
 * "..."; o endereço também corta em 580, para não entrar no quadro do regime (a DejaVu é mais larga que a Arial).
 */
final class AltoDaFolha
{
    private const X = ImagemDaFolha::X;
    private const LARGURA = ImagemDaFolha::LARGURA_UTIL;
    private const LARGURA_DO_EMPREGADOR = 580;
    private const X_DO_REGIME = 640;

    public function __construct(
        private readonly PaginaDaFolha $pagina,
    ) {
    }

    public function desenhar(ConteudoDaFolha $conteudo): void
    {
        $this->titulo($conteudo);
        $this->empregador($conteudo->cabecalho);
        $this->regime($conteudo->cabecalho);
        $this->empregado($conteudo->cabecalho);
    }

    /** "FOLHA DE PONTO · MM/AAAA" na faixa azul de 32 e o período na faixa clara de 20 (L659 a L661). */
    private function titulo(ConteudoDaFolha $conteudo): void
    {
        $this->pagina->retangulo(self::X, 46, self::LARGURA, 32, PaginaDaFolha::AZUL_ESCURO);
        $this->pagina->texto($conteudo->titulo(), 'negrito', 17, 500, 68, '#ffffff', true);
        $this->pagina->tela()->retangulo(self::X, 78, self::LARGURA, 20, $this->pagina->tela()->corRgb(229, 241, 247, .92));
        $this->pagina->quadro(self::X, 78, self::LARGURA, 20);
        $periodo = sprintf('Período: %s a %s', $conteudo->cabecalho->inicioDoPeriodo, $conteudo->cabecalho->fimDoPeriodo);
        $this->pagina->texto($periodo, 'normal', 11.5, 500, 92, PaginaDaFolha::TEXTO, true);
    }

    /** A razão social em negrito sublinhada, o CNPJ (se houver) e o endereço em duas linhas (L662 e L663). */
    private function empregador(CabecalhoDaFolha $cabecalho): void
    {
        $y = 104;
        $tamanho = 16.0;
        while ($tamanho > 11 && $this->pagina->largura($cabecalho->empregador, 'negrito', $tamanho) > self::LARGURA_DO_EMPREGADOR) {
            --$tamanho;
        }
        $razao = $this->pagina->caber($cabecalho->empregador, 'negrito', $tamanho, self::LARGURA_DO_EMPREGADOR);
        $this->pagina->texto($razao, 'negrito', $tamanho, self::X, $y + 16);
        $this->pagina->retangulo(self::X, $y + 22, min(self::LARGURA_DO_EMPREGADOR, $this->pagina->largura($razao, 'negrito', $tamanho)), 1.4, PaginaDaFolha::AZUL_ESCURO);
        if ($cabecalho->cnpj !== '') {
            $this->pagina->texto('CNPJ: ' . $cabecalho->cnpj, 'normal', 12.5, self::X, $y + 42);
        }
        $this->pagina->texto($this->pagina->caber($cabecalho->enderecoLinha1, 'normal', 12.5, self::LARGURA_DO_EMPREGADOR), 'normal', 12.5, self::X, $y + 61);
        $this->pagina->texto($this->pagina->caber($cabecalho->enderecoLinha2, 'normal', 12.5, self::LARGURA_DO_EMPREGADOR), 'normal', 12.5, self::X, $y + 80);
    }

    /** O quadro "REGIME DE JORNADA" em x = 640, com cinco linhas de rótulo e valor a cada 13,5 (L664). */
    private function regime(CabecalhoDaFolha $cabecalho): void
    {
        $largura = ImagemDaFolha::DIREITA - self::X_DO_REGIME;
        $this->pagina->cabeca('REGIME DE JORNADA', self::X_DO_REGIME, 104, $largura, true);
        $this->pagina->quadro(self::X_DO_REGIME, 124, $largura, 72);
        $linhas = [
            ['Jornada:', $cabecalho->jornada],
            ['Distribuição:', $cabecalho->distribuicao],
            ['Banco de Horas:', CabecalhoDaFolha::BANCO_DE_HORAS],
            ['Intervalo Intrajornada:', $cabecalho->intervaloIntrajornada],
            ['Intervalo Interjornada:', $cabecalho->intervaloInterjornada],
        ];
        foreach ($linhas as $i => [$rotulo, $valor]) {
            $this->pagina->rotulo($rotulo, $valor, self::X_DO_REGIME + 7, 104 + 34 + $i * 13.5, $largura - 14);
        }
    }

    /** "DADOS DO EMPREGADO": quatro linhas de células de 19 de altura, com as larguras do desenho (L665 e L666). */
    private function empregado(CabecalhoDaFolha $cabecalho): void
    {
        $this->pagina->cabeca('DADOS DO EMPREGADO', self::X, 204, self::LARGURA, false);
        $y = 224;
        foreach (self::celulas($cabecalho) as $linha) {
            $x = self::X;
            foreach ($linha as [$largura, $rotulo, $valor]) {
                $this->pagina->quadro($x, $y, $largura, 19);
                $this->pagina->rotulo($rotulo, $valor, $x + 6, $y + 13.5, $largura - 12);
                $x += $largura;
            }
            $y += 19;
        }
    }

    /** @return list<list<array{int, string, string}>> largura, rótulo e valor de cada célula, linha a linha */
    private static function celulas(CabecalhoDaFolha $cabecalho): array
    {
        return [
            [[180, 'Código:', $cabecalho->codigo], [430, 'Nome:', $cabecalho->nome], [280, 'CPF:', $cabecalho->cpf]],
            [[180, 'Cargo:', $cabecalho->cargo], [250, 'CTPS:', $cabecalho->ctps], [180, 'Série:', $cabecalho->serie], [280, 'Lotação:', $cabecalho->lotacao]],
            [[180, 'Jornada Semanal:', $cabecalho->jornadaSemanal], [250, 'Horário Contratual:', $cabecalho->horarioContratual], [180, 'Intervalo:', $cabecalho->intervalo], [280, 'Tipo de Ponto:', CabecalhoDaFolha::TIPO_DE_PONTO]],
            [[430, 'Escala:', $cabecalho->escala], [460, 'Data de admissão:', $cabecalho->admissao]],
        ];
    }
}
