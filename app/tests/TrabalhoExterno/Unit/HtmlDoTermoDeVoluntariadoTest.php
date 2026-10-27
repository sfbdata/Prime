<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDoTermo;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\HtmlDoTermoDeVoluntariado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O HTML do termo (`Component.termoHTML`, desenho 06 de 25/10, l.943 a 970) montado no servidor, e o DTO dos
 * campos do modal. O texto jurídico é o do desenho: aqui se prova o que muda conforme o dado.
 */
#[CoversClass(HtmlDoTermoDeVoluntariado::class)]
#[CoversClass(DadosDoTermo::class)]
final class HtmlDoTermoDeVoluntariadoTest extends TestCase
{
    private const LINHA = 'border-bottom:1px solid #444;';

    /** @return array<string, string> */
    private static function campos(array $extra = []): array
    {
        return $extra + [
            'eNome' => 'Souza & Lima Advogados', 'eDoc' => '12.345.678/0001-90', 'eEnd' => 'SQS 100, Brasília/DF', 'eEmail' => 'contato@sl.test',
            'eTel' => '(61) 3000-0000', 'resp' => 'Dra. Marta', 'respOab' => '1234', 'respOabUf' => 'DF', 'orient' => '',
            'vNome' => 'Ana Souza', 'vNac' => 'brasileiro(a)', 'vRg' => '1.234.567 SSP/DF', 'vCpf' => '111.222.333-44', 'vOab' => '', 'vOabUf' => '',
            'vSit' => 'est', 'vInst' => 'UnB', 'vSem' => '7º semestre', 'vEnd' => 'Brasília/DF', 'vEmail' => 'ana@exemplo.test',
            'ativ' => 'pesquisa jurídica', 'horas' => '20', 'modal' => 'regime híbrido', 'inicio' => '2026-10-27', 'meses' => '6', 'aviso' => '10',
            'ress' => 'nao', 'ressLim' => '', 'foro' => 'Brasília', 'cidade' => 'Brasília/DF', 'data' => '2026-10-27',
        ];
    }

    private function html(array $extra = [], string $aceito = '', string $codigo = ''): string
    {
        return (new HtmlDoTermoDeVoluntariado())->montar(self::campos($extra), $aceito, $codigo);
    }

    #[TestDox('o dado vem em negrito e o campo em branco vira a linha para preencher')]
    public function testEmBrancoViraLinha(): void
    {
        $cheio = $this->html();
        self::assertStringContainsString('<b>Ana Souza</b>', $cheio);
        self::assertStringContainsString('inscrito(a) no CPF sob o nº <b>111.222.333-44</b>', $cheio);

        $vazio = $this->html(['vCpf' => '', 'eTel' => '']);
        self::assertStringContainsString('inscrito(a) no CPF sob o nº <span style="display:inline-block; min-width:120px; ' . self::LINHA . '">&nbsp;</span>', $vazio);
        self::assertStringContainsString('telefone <span style="display:inline-block; min-width:120px;', $vazio);
        self::assertStringNotContainsString('111.222.333-44', $vazio);
    }

    #[TestDox('cláusula 4.2: sem ressarcimento (padrão) ou com ressarcimento, com e sem limite mensal')]
    public function testClausulaQuatroPontoDois(): void
    {
        self::assertStringContainsString('4.2. Não haverá ressarcimento de despesas, salvo ajuste prévio e por escrito entre as partes, nos termos do art. 3º da Lei nº 9.608/1998.', $this->html());

        $sem = $this->html(['ress' => 'sim']);
        self::assertStringContainsString('4.2. O ESCRITÓRIO poderá ressarcir o(a) VOLUNTÁRIO(A) das despesas que comprovadamente realizar no desempenho das atividades voluntárias, desde que expressamente autorizadas, nos termos do art. 3º', $sem);
        self::assertStringContainsString('O ressarcimento não tem natureza remuneratória.', $sem);
        self::assertStringNotContainsString('Não haverá ressarcimento', $sem);

        self::assertStringContainsString('desde que expressamente autorizadas, até o limite mensal de R$ 300,00, nos termos do art. 3º', $this->html(['ress' => 'sim', 'ressLim' => '300,00']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function extensos(): iterable
    {
        foreach ([['0', 'zero'], ['1', 'um'], ['6', 'seis'], ['10', 'dez'], ['19', 'dezenove'], ['20', 'vinte'], ['21', 'vinte e um'], ['45', 'quarenta e cinco'], ['99', 'noventa e nove'], ['100', '100'], ['120', '120']] as [$n, $texto]) {
            yield $n => [$n, $texto];
        }
    }

    #[DataProvider('extensos')]
    #[TestDox('"extenso" até 99; de 100 em diante, o próprio número')]
    public function testExtenso(string $n, string $esperado): void
    {
        self::assertSame($esperado, HtmlDoTermoDeVoluntariado::extenso($n));
        self::assertStringContainsString('<b>' . $n . ' (' . $esperado . ') meses</b>', $this->html(['meses' => $n]));
    }

    #[TestDox('escapa o HTML de todo dado (nada de tag nem aspas soltas)')]
    public function testEscapa(): void
    {
        $html = $this->html(['vNome' => '<script>alert("x")</script>', 'ativ' => 'a & b', 'vInst' => '"Faculdade" <b>']);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('a &amp; b', $html);
        self::assertStringContainsString('&quot;Faculdade&quot; &lt;b&gt;', $html);
    }

    #[TestDox('datas: início e término (início mais os meses), por extenso no fecho, e a linha sem data')]
    public function testDatas(): void
    {
        $html = $this->html();
        self::assertStringContainsString('com início em 27/10/2026 e término em 27/04/2027', $html);
        self::assertStringContainsString('<b>Brasília/DF</b>, 27 de outubro de 2026.', $html);
        self::assertStringContainsString('<b>6 (seis) meses</b>', $html);
        self::assertStringContainsString('<b>10 (dez) dias</b>', $html);

        $semData = $this->html(['inicio' => '']);
        self::assertStringContainsString('com início em <span style="display:inline-block; min-width:90px;', $semData);
    }

    #[TestDox('qualificação do voluntário: OAB de estagiário, estudante ou bacharel, e o orientador cai no representante')]
    public function testQualificacao(): void
    {
        $estOab = $this->html(['vOab' => '54321', 'vOabUf' => 'DF', 'vSit' => 'estOab']);
        self::assertStringContainsString(', inscrito(a) na OAB/DF como estagiário(a) sob o nº 54321, estudante de Direito da UnB (7º semestre), residente', $estOab);
        self::assertStringContainsString('<b>Dra. Marta</b>, OAB/DF 1234, doravante', $estOab);
        self::assertStringContainsString('sob orientação de <b>Dra. Marta</b>,', $estOab);

        $bach = $this->html(['vSit' => 'bach', 'orient' => 'Dr. Paulo']);
        self::assertStringContainsString('bacharel(a) em Direito pela UnB (7º semestre)', $bach);
        self::assertStringContainsString('sob orientação de <b>Dr. Paulo</b>,', $bach);
    }

    #[TestDox('aceito: "por meio de aceite eletrônico" e o bloco com a data, o código e a versão 1.0; rascunho: "em duas vias"')]
    public function testAceite(): void
    {
        $rascunho = $this->html();
        self::assertStringContainsString('firmam o presente termo em duas vias de igual teor.', $rascunho);
        self::assertStringNotContainsString('Aceite eletrônico', $rascunho);

        $aceito = $this->html([], '27/10/2026 14:03:22', 'TV-0007');
        self::assertStringContainsString('firmam o presente termo por meio de aceite eletrônico registrado no BlueJus.', $aceito);
        self::assertStringContainsString('<b>Aceite eletrônico</b><br>Ana Souza declarou ter lido e aceitado este termo em 27/10/2026 14:03:22. Código do termo: TV-0007 · versão 1.0.', $aceito);
        self::assertStringContainsString('revise com o responsável jurídico do escritório antes de assinar.', $aceito);
    }

    #[TestDox('o texto do modelo é o do desenho e não tem travessão')]
    public function testSemTravessao(): void
    {
        $html = $this->html();
        self::assertStringNotContainsString('—', $html);
        self::assertStringNotContainsString('–', $html);
        self::assertStringContainsString('TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO', $html);
        self::assertStringContainsString('Lei nº 9.608, de 18 de fevereiro de 1998', $html);
    }

    #[TestDox('DTO: só as chaves conhecidas, aparadas e com limite; números só dígitos; data inválida some; ressarcimento sim ou nao')]
    public function testDto(): void
    {
        $d = DadosDoTermo::deArray(['eNome' => '  Escritório  ', 'intruso' => 'x', 'horas' => '2a0h', 'inicio' => '2026-02-31', 'data' => '2026-10-27', 'ress' => 'talvez', 'respOabUf' => 'DFX', 'vNome' => ['array']]);
        self::assertSame('Escritório', $d->campos['eNome']);
        self::assertArrayNotHasKey('intruso', $d->campos);
        self::assertSame(['20', '', '2026-10-27', 'nao', 'DF', ''], [$d->campos['horas'], $d->campos['inicio'], $d->campos['data'], $d->campos['ress'], $d->campos['respOabUf'], $d->campos['vNome']]);
        self::assertSame(array_keys(DadosDoTermo::CAMPOS), array_keys($d->campos));
    }

    #[TestDox('DTO: os 7 obrigatórios, com a mensagem do desenho')]
    public function testObrigatorios(): void
    {
        $d = DadosDoTermo::deArray(['eNome' => 'Escritório', 'vNome' => 'Ana', 'meses' => '6']);
        self::assertSame(['CNPJ/CPF do escritório', 'CPF do voluntário', 'data de início', 'foro'], $d->faltando());
        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage('Preencha antes de enviar: CNPJ/CPF do escritório, CPF do voluntário, data de início, foro.');
        $d->exigirObrigatorios();
    }
}
