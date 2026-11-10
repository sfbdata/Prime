<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\ValidacaoDaFolha;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\ImagemDaFolha\AltoDaFolha;
use App\Ponto\Service\ImagemDaFolha\ImagemDaFolha;
use App\Ponto\Service\ImagemDaFolha\PadroesDaFolha;
use App\Ponto\Service\ImagemDaFolha\PaginaDaFolha;
use App\Ponto\Service\ImagemDaFolha\PeDaFolha;
use App\Ponto\Service\ImagemDaFolha\QuadrosDaFolha;
use App\Ponto\Service\ImagemDaFolha\TabelaDaFolha;
use App\Tests\Ponto\Support\ConteudoDaFolhaDeExemplo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A folha do mês em imagem (E16, P34 e decisão 5; desenho 16 L634 a L705): JPEG 92 em 2480 x 3507, padrões tirados do
 * selo da folha emitida, o carimbo só quando o conteúdo o traz e, sem o segredo do HMAC (decisão 16), sem código, sem
 * "AUTENTICA", sem a faixa de validação e sem o selo, com a "Base Legal" no lugar deles.
 */
#[Group('ponto')]
#[CoversClass(ImagemDaFolha::class)]
#[CoversClass(PaginaDaFolha::class)]
#[CoversClass(PadroesDaFolha::class)]
#[CoversClass(AltoDaFolha::class)]
#[CoversClass(TabelaDaFolha::class)]
#[CoversClass(QuadrosDaFolha::class)]
#[CoversClass(PeDaFolha::class)]
final class ImagemDaFolhaTest extends TestCase
{
    /** O que a geração pode somar ao que a requisição já usa: metade do limite de produção (128 MB). */
    private const TETO_DE_MEMORIA = 64 * 1024 * 1024;
    private const QR = 'https://bluejus.test/ponto/validar/' . ConteudoDaFolhaDeExemplo::CODIGO;
    private const PAGINA = 'bluejus.test/ponto/validar';

    #[TestDox('JPEG em alta (2480 x 3507), e a geração isolada fica abaixo de 64 MB de pico')]
    public function testJpegEmAltaComFolgaDeMemoria(): void
    {
        $gerador = $this->gerador();
        $conteudo = ConteudoDaFolhaDeExemplo::outubro(aceite: SituacaoDoAceiteDaFolha::Ressalva);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $antes = memory_get_usage(true);

        $jpeg = $gerador->gerar($conteudo, self::QR, self::PAGINA);

        $pico = memory_get_peak_usage(true) - $antes;
        self::assertLessThan(self::TETO_DE_MEMORIA, $pico, sprintf('pico de %.1f MB', $pico / 1048576));
        $tamanho = getimagesizefromstring($jpeg);
        self::assertNotFalse($tamanho);
        self::assertSame([2480, 3507, IMAGETYPE_JPEG], [$tamanho[0], $tamanho[1], $tamanho[2]]);
    }

    #[TestDox('A mesma folha gera a mesma imagem; outro selo, outra imagem; sem o segredo, o fundo sai do conteúdo')]
    public function testMesmaFolhaMesmaImagem(): void
    {
        $gerador = $this->gerador();
        $gerar = fn (ConteudoDaFolha $conteudo, ?string $qr): string => $gerador->gerar($conteudo, $qr, self::PAGINA, 0.5);
        $folha = ConteudoDaFolhaDeExemplo::outubro();
        $outroSelo = ConteudoDaFolhaDeExemplo::outubro(false)->comValidacao(new ValidacaoDaFolha(ConteudoDaFolhaDeExemplo::CODIGO, str_repeat('9f', 32), new \DateTimeImmutable('2026-11-09 10:15:30')));
        $semSegredo = ConteudoDaFolhaDeExemplo::outubro(false);

        self::assertSame($gerar($folha, self::QR), $gerar($folha, self::QR));
        self::assertNotSame($gerar($folha, self::QR), $gerar($outroSelo, self::QR));
        self::assertSame($gerar($semSegredo, null), $gerar($semSegredo, null));
    }

    #[TestDox('A semente: o selo HMAC da folha emitida; sem ele, o sha256 da competência, do nome, do empregador e dos registros')]
    public function testSemente(): void
    {
        $semSegredo = ConteudoDaFolhaDeExemplo::outubro(false);

        self::assertSame(ConteudoDaFolhaDeExemplo::HMAC, ImagemDaFolha::semente(ConteudoDaFolhaDeExemplo::outubro()));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ImagemDaFolha::semente($semSegredo));
        self::assertSame(ImagemDaFolha::semente($semSegredo), ImagemDaFolha::semente(ConteudoDaFolhaDeExemplo::outubro(false)));
        self::assertNotSame(ImagemDaFolha::semente($semSegredo), ImagemDaFolha::semente(ConteudoDaFolhaDeExemplo::outubro(false, nomesLongos: true)));
    }

    #[TestDox('Com o segredo: o título "CÓDIGO DE VALIDAÇÃO", o código em vermelho, o HMAC em duas linhas, o QR, o selo e o código na marca d\'água e no microtexto')]
    public function testComOSegredo(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(), self::QR, self::PAGINA, 0.5);
        $textos = array_column($pagina->textos(), 'texto');

        self::assertContains(ValidacaoDaFolha::TITULO, $textos);
        self::assertContains('HMAC-SHA256 ' . substr(ConteudoDaFolhaDeExemplo::HMAC, 0, 32), $textos);
        self::assertContains(str_repeat(' ', 12) . substr(ConteudoDaFolhaDeExemplo::HMAC, 32), $textos);
        self::assertContains('Confira em ' . self::PAGINA . ' ou leia o QR Code.', $textos);
        self::assertSame(['qr', 'selo', 'faixa-de-validacao'], array_values(array_intersect(['qr', 'selo', 'faixa-de-validacao'], $pagina->pecas())));
        self::assertSame(ConteudoDaFolhaDeExemplo::CODIGO, $this->codigoDesenhado($pagina), 'letra por letra, em vermelho, em mono negrito');
        self::assertNotEmpty($this->comecandoCom($pagina, 'BLUEJUS · ' . ConteudoDaFolhaDeExemplo::CODIGO . ' · MARIA DA SILVA · '), 'a marca d\'água leva o código e o nome');
        self::assertNotEmpty($this->comecandoCom($pagina, 'BLUEJUS PONTO ELETRONICO FOLHA AUTENTICA ' . ConteudoDaFolhaDeExemplo::CODIGO), 'o microtexto leva o código');
        self::assertEqualsWithDelta(1325.0, $this->yDe($pagina, 'Base Legal:'), .01, 'a Base Legal fica abaixo da faixa de validação');
    }

    #[TestDox('Sem o segredo: nada do código nem "AUTENTICA", sem QR, sem selo e sem a faixa; a Base Legal sobe para o lugar deles')]
    public function testSemOSegredo(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(false), null, self::PAGINA, 0.5);
        $tudo = implode("\n", array_column($pagina->textos(), 'texto'));

        self::assertStringNotContainsString('BJF', $tudo);
        self::assertStringNotContainsString('AUTENTICA', $tudo);
        self::assertStringNotContainsString(ValidacaoDaFolha::TITULO, $tudo);
        self::assertStringNotContainsString('HMAC', $tudo);
        self::assertStringNotContainsString('Confira em', $tudo);
        self::assertSame([], array_values(array_intersect(['qr', 'selo', 'faixa-de-validacao'], $pagina->pecas())));
        self::assertNotEmpty($this->comecandoCom($pagina, 'BLUEJUS · MARIA DA SILVA · '), 'a marca d\'água só com o nome');
        self::assertNotEmpty($this->comecandoCom($pagina, 'BLUEJUS PONTO ELETRONICO FOLHA BLUEJUS'), 'o microtexto sem a palavra AUTENTICA');
        self::assertEqualsWithDelta(1195.0, $this->yDe($pagina, 'Base Legal:'), .01, 'logo abaixo da declaração');
    }

    #[TestDox('O carimbo só com o aceite: "Aceite eletrônico em ..." em verde; "Aceite com ressalva em ..." em vermelho, com a ressalva')]
    public function testCarimbo(): void
    {
        $gerador = $this->gerador();
        $concordo = $gerador->desenhar(ConteudoDaFolhaDeExemplo::outubro(aceite: SituacaoDoAceiteDaFolha::Concordo), self::QR, self::PAGINA, 0.5);
        $ressalva = $gerador->desenhar(ConteudoDaFolhaDeExemplo::outubro(aceite: SituacaoDoAceiteDaFolha::Ressalva), self::QR, self::PAGINA, 0.5);
        $semAceite = $gerador->desenhar(ConteudoDaFolhaDeExemplo::outubro(), self::QR, self::PAGINA, 0.5);

        self::assertSame(['#1e7b45'], array_column($this->comecandoCom($concordo, 'Aceite eletrônico em 03/11/2026, 09:10:11'), 'cor'));
        self::assertSame([], $this->comecandoCom($concordo, 'Ressalva: '));
        self::assertSame(['#a3232b'], array_column($this->comecandoCom($ressalva, 'Aceite com ressalva em 03/11/2026, 09:10:11'), 'cor'));
        self::assertSame(['Ressalva: ' . ConteudoDaFolhaDeExemplo::RESSALVA], array_column($this->comecandoCom($ressalva, 'Ressalva: '), 'texto'));
        self::assertSame([], $this->comecandoCom($semAceite, 'Aceite '));
    }

    #[TestDox('Nome e razão social que não cabem: cortados com "..." dentro da largura do desenho')]
    public function testNomesLongosCortados(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(nomesLongos: true), self::QR, self::PAGINA, 0.5);

        $razao = $this->comecandoCom($pagina, 'ESCRITÓRIO DE ADVOCACIA');
        self::assertCount(1, $razao);
        self::assertStringEndsWith('...', $razao[0]['texto']);
        self::assertLessThanOrEqual(ImagemDaFolha::X + 580 + .5, $razao[0]['x'] + $pagina->largura($razao[0]['texto'], $razao[0]['fonte'], $razao[0]['tamanho']));
        $nomes = $this->comecandoCom($pagina, 'MARIA APARECIDA');
        self::assertCount(2, $nomes, 'nos dados do empregado e na assinatura');
        foreach ($nomes as $nome) {
            self::assertStringEndsWith('...', $nome['texto']);
            self::assertLessThanOrEqual(ImagemDaFolha::DIREITA, $nome['x'] + $pagina->largura($nome['texto'], $nome['fonte'], $nome['tamanho']));
        }
    }

    #[TestDox('Endereço e nomes da assinatura que não cabem encolhem até 8 antes do "..." (decisões 26 e 27); o que cabe fica no tamanho do desenho')]
    public function testEnderecoENomesEncolhemAntesDeCortar(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(nomesLongos: true), self::QR, self::PAGINA, 0.5);

        $endereco = $this->comecandoCom($pagina, 'Setor de Habitações');
        self::assertCount(1, $endereco);
        self::assertSame('Setor de Habitações Individuais Norte, Conjunto 3, Lote 31, Taguatinga Norte, Taguatinga/DF CEP 72125-530', $endereco[0]['texto'], 'inteiro, sem "..."');
        self::assertLessThan(12.5, $endereco[0]['tamanho']);
        self::assertGreaterThanOrEqual(8.0, $endereco[0]['tamanho']);
        self::assertLessThanOrEqual(ImagemDaFolha::X + 580 + .5, $endereco[0]['x'] + $pagina->largura($endereco[0]['texto'], $endereco[0]['fonte'], $endereco[0]['tamanho']));
        self::assertSame(12.5, $this->comecandoCom($pagina, 'Quadra QNF 3')[0]['tamanho'], 'a linha que cabe não encolhe');

        $nomes = $this->comecandoCom($pagina, 'MARIA APARECIDA');
        usort($nomes, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);
        $assinatura = $nomes[array_key_last($nomes)];
        self::assertSame(8.0, $assinatura['tamanho'], 'o nome da assinatura chega a 8 antes de cortar');
        self::assertStringEndsWith('...', $assinatura['texto']);
        self::assertSame(10.0, $this->comecandoCom($pagina, 'FARLEI ASSIS')[0]['tamanho'], 'o nome do responsável cabe em 10');
    }

    #[TestDox('A tabela pinta de vermelho as batidas fora de sequência e o saldo negativo')]
    public function testCoresDaTabela(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(), self::QR, self::PAGINA, 0.5);

        self::assertSame('#a3232b', $this->coresDaLinha($pagina, '07')['12:30'], 'fora de sequência');
        self::assertSame('#a3232b', $this->coresDaLinha($pagina, '21')['-7:40'], 'saldo negativo');
        self::assertNotSame('#a3232b', $this->coresDaLinha($pagina, '05')['08:00'], 'o dia comum não é vermelho');
    }

    #[TestDox('As três frases das observações gerais (P36), inteiras, na ordem, quebradas por palavra no quadro')]
    public function testObservacoesGerais(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(), self::QR, self::PAGINA, 0.5);

        self::assertSame(ConteudoDaFolha::OBSERVACOES_GERAIS, $this->frasesDasObservacoes($pagina));
    }

    #[TestDox('A "Base Legal": o rótulo, as normas e a nota do Quadro de Horário, com e sem o segredo')]
    public function testBaseLegal(): void
    {
        foreach ([[ConteudoDaFolhaDeExemplo::outubro(), self::QR], [ConteudoDaFolhaDeExemplo::outubro(false), null]] as [$conteudo, $qr]) {
            $textos = array_column($this->gerador()->desenhar($conteudo, $qr, self::PAGINA, 0.5)->textos(), 'texto');

            self::assertContains('Base Legal:', $textos);
            self::assertContains(ConteudoDaFolha::BASE_LEGAL, $textos);
            self::assertContains(ConteudoDaFolha::NOTA_DA_BASE_LEGAL, $textos);
        }
        self::assertStringContainsString('Art. 74, §2º da CLT', ConteudoDaFolha::BASE_LEGAL);
        self::assertStringContainsString('Portaria MTP nº 671/2021', ConteudoDaFolha::BASE_LEGAL);
    }

    #[TestDox('A linha do feriado sem batida (12/10): "FERIADO" em vermelho negrito na semana e no lugar das batidas, e o nome do feriado nas observações')]
    public function testLinhaDoFeriado(): void
    {
        $pagina = $this->gerador()->desenhar(ConteudoDaFolhaDeExemplo::outubro(), self::QR, self::PAGINA, 0.5);
        $linha = $this->textosDaLinha($pagina, '12');

        $feriados = array_values(array_filter($linha, static fn (array $t): bool => $t['texto'] === 'FERIADO'));
        self::assertCount(2, $feriados, 'na semana e no meio das batidas');
        self::assertSame([['negrito', '#a3232b'], ['negrito', '#a3232b']], array_map(static fn (array $t): array => [$t['fonte'], $t['cor']], $feriados));
        self::assertNotEmpty(array_filter($linha, static fn (array $t): bool => str_starts_with($t['texto'], 'Feriado: Nossa Senhora')), 'o nome do feriado nas observações');
        self::assertEmpty(array_filter($linha, static fn (array $t): bool => preg_match('/^\d{2}:\d{2}$/', $t['texto']) === 1), 'sem batida no feriado');
    }

    #[TestDox('A marca d\'água e o microtexto, com e sem o código')]
    public function testMarcaDaguaEMicrotexto(): void
    {
        self::assertSame(str_repeat('BLUEJUS · BJF-X · ANA · ', 5), PadroesDaFolha::linhaDaMarcaDagua('BJF-X', 'Ana'));
        self::assertSame(str_repeat('BLUEJUS · ANA · ', 5), PadroesDaFolha::linhaDaMarcaDagua(null, 'Ana'));
        self::assertSame(str_repeat('BLUEJUS PONTO ELETRONICO FOLHA AUTENTICA BJF-X ', 9), PadroesDaFolha::textoDoMicrotexto('BJF-X'));
        self::assertStringNotContainsString('AUTENTICA', PadroesDaFolha::textoDoMicrotexto(null));
    }

    private function gerador(): ImagemDaFolha
    {
        return new ImagemDaFolha(dirname(__DIR__, 3) . '/vendor/dompdf/dompdf/lib/fonts');
    }

    /** @return list<array{texto: string, fonte: string, tamanho: float, x: float, y: float, cor: string}> */
    private function comecandoCom(PaginaDaFolha $pagina, string $inicio): array
    {
        return array_values(array_filter($pagina->textos(), static fn (array $t): bool => str_starts_with($t['texto'], $inicio)));
    }

    private function yDe(PaginaDaFolha $pagina, string $texto): float
    {
        $achados = array_values(array_filter($pagina->textos(), static fn (array $t): bool => $t['texto'] === $texto));
        self::assertCount(1, $achados, $texto);

        return $achados[0]['y'];
    }

    /**
     * A cor de cada texto da linha do dia na tabela, pelo texto. A linha se acha pelo número do dia, o primeiro texto
     * dela (x de 46 a 80, abaixo do topo da tabela).
     *
     * @return array<string, string>
     */
    private function coresDaLinha(PaginaDaFolha $pagina, string $dia): array
    {
        $achados = array_values(array_filter($pagina->textos(), static fn (array $t): bool => $t['texto'] === $dia && $t['x'] < 80 && $t['y'] > 308));
        self::assertCount(1, $achados, 'o dia ' . $dia);
        $y = $achados[0]['y'];

        return array_column(array_filter($pagina->textos(), static fn (array $t): bool => abs($t['y'] - $y) < .01), 'cor', 'texto');
    }

    /**
     * Os textos da linha do dia na tabela, na ordem em que foram desenhados. A linha se acha pelo número do dia, como em
     * {@see self::coresDaLinha()}.
     *
     * @return list<array{texto: string, fonte: string, tamanho: float, x: float, y: float, cor: string}>
     */
    private function textosDaLinha(PaginaDaFolha $pagina, string $dia): array
    {
        $achados = array_values(array_filter($pagina->textos(), static fn (array $t): bool => $t['texto'] === $dia && $t['x'] < 80 && $t['y'] > 308));
        self::assertCount(1, $achados, 'o dia ' . $dia);
        $y = $achados[0]['y'];

        return array_values(array_filter($pagina->textos(), static fn (array $t): bool => abs($t['y'] - $y) < .01));
    }

    /**
     * As frases do quadro "OBSERVAÇÕES GERAIS" remontadas das linhas desenhadas: cada uma começa no marcador "• " e
     * continua nas linhas recuadas em dois espaços que vêm logo depois (o corpo em 10, sem outro texto no meio).
     *
     * @return list<string>
     */
    private function frasesDasObservacoes(PaginaDaFolha $pagina): array
    {
        $frases = [];
        foreach ($pagina->textos() as $t) {
            if ($t['fonte'] !== 'normal' || $t['tamanho'] !== 10.0) {
                continue;
            }
            if (str_starts_with($t['texto'], '• ')) {
                $frases[] = substr($t['texto'], strlen('• '));
                continue;
            }
            if ($frases !== [] && str_starts_with($t['texto'], '  ') && !str_starts_with($t['texto'], '   ')) {
                $frases[array_key_last($frases)] .= ' ' . $t['texto'];
            }
        }

        return array_map(static fn (string $frase): string => trim((string) preg_replace('/\s+/', ' ', $frase)), $frases);
    }

    /** As letras do código em mono negrito e vermelho, na ordem em que foram desenhadas. */
    private function codigoDesenhado(PaginaDaFolha $pagina): string
    {
        $letras = array_filter($pagina->textos(), static fn (array $t): bool => $t['fonte'] === 'mono-negrito' && $t['cor'] === '#a3232b');

        return implode('', array_column($letras, 'texto'));
    }
}
