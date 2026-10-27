<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Exception\ConfiguracaoRecusada;
use App\Master\Service\CatalogoDeConfiguracoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogoDeConfiguracoes::class)]
final class CatalogoDeConfiguracoesTest extends TestCase
{
    #[TestDox('os padrões são os do desenho (DEF, 05 VS 25.10.26 L955; o remetente é o do MAILER_FROM) e o catálogo tem os 15 campos')]
    public function testPadroesDoDesenho(): void
    {
        self::assertSame([
            'nome' => 'BlueJus', 'remetente' => 'nao-responda@bluejus.com.br', 'suporte' => 'bluejus.86@gmail.com',
            'cadastro' => 'on', 'freeGb' => '10', 'aviso' => '90',
            'sessao' => '480', 'tentativas' => '5', 'dois' => 'master', 'retencao' => '365', 'avisoLoginNovo' => 'on',
            'idioma' => 'pt-BR', 'fuso' => 'America/Sao_Paulo', 'manut' => '', 'manutAte' => '',
        ], CatalogoDeConfiguracoes::padroes());
        self::assertSame(['identidade', 'contas', 'seguranca', 'regiao', 'manutencao'], array_keys(CatalogoDeConfiguracoes::grupos()));
        self::assertNotContains('freeGb', CatalogoDeConfiguracoes::chavesGravadas());
        self::assertNotContains('aviso', CatalogoDeConfiguracoes::chavesGravadas());
        self::assertCount(13, CatalogoDeConfiguracoes::chavesGravadas());
    }

    #[TestDox('pedido vazio vale os padrões; texto é aparado e número vira inteiro')]
    public function testNormaliza(): void
    {
        self::assertSame(CatalogoDeConfiguracoes::padroes(), CatalogoDeConfiguracoes::normalizar([]));

        $v = CatalogoDeConfiguracoes::normalizar(['nome' => '  Meu Sistema ', 'sessao' => '0600', 'tentativas' => 7, 'manutAte' => '2026-11-01T22:30']);
        self::assertSame('Meu Sistema', $v['nome']);
        self::assertSame('600', $v['sessao']);
        self::assertSame('7', $v['tentativas']);
        self::assertSame('2026-11-01T22:30', $v['manutAte']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidos(): iterable
    {
        yield 'chave desconhecida' => [['xyz' => '1']];
        yield 'nome vazio' => [['nome' => '  ']];
        yield 'nome comprido' => [['nome' => str_repeat('a', 61)]];
        yield 'e-mail ruim' => [['remetente' => 'sem-arroba']];
        yield 'opção fora da lista' => [['cadastro' => 'aberto']];
        yield 'fuso desconhecido' => [['fuso' => 'Europe/Paris']];
        yield 'sessão curta' => [['sessao' => '1']];
        yield 'tentativas texto' => [['tentativas' => 'cinco']];
        yield 'retenção fração' => [['retencao' => '365.5']];
        yield 'aviso acima de 100' => [['aviso' => '101']];
        yield 'data impossível' => [['manutAte' => '2026-02-30T10:00']];
        yield 'data sem hora' => [['manutAte' => '2026-11-01']];
        yield 'manutenção comprida' => [['manut' => str_repeat('a', 301)]];
        yield 'valor array' => [['nome' => ['x']]];
        yield 'nome com colchetes' => [['nome' => 'Acme [Jur]']];
        yield 'nome com aspas' => [['nome' => 'A"B']];
        yield 'nome com quebra de linha' => [['nome' => "Acme\nBcc: x@y.com"]];
    }

    /** @param array<string, mixed> $entrada */
    #[DataProvider('invalidos')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusa(array $entrada): void
    {
        $this->expectException(ConfiguracaoRecusada::class);

        CatalogoDeConfiguracoes::normalizar($entrada);
    }

    #[TestDox('O padrão do ambiente troca `sessao` em padroes(), grupos() e normalizar()')]
    public function testSobreposicaoDoPadraoDaSessao(): void
    {
        $sobrepor = CatalogoDeConfiguracoes::sobreposicoes(24);

        self::assertSame('24', CatalogoDeConfiguracoes::padroes($sobrepor)['sessao']);
        self::assertSame('24', CatalogoDeConfiguracoes::grupos($sobrepor)['seguranca']['sessao']['padrao']);
        self::assertSame('24', CatalogoDeConfiguracoes::normalizar([], $sobrepor)['sessao']);
        self::assertSame('480', CatalogoDeConfiguracoes::normalizar([])['sessao'], 'sem sobreposição, o estático');
    }

    #[TestDox('O padrão do ambiente troca `remetente` pelo endereço do MAILER_FROM (sem o nome); inválido ou ausente, o estático')]
    public function testSobreposicaoDoRemetente(): void
    {
        $sobrepor = CatalogoDeConfiguracoes::sobreposicoes(24, 'Equipe <envio@exemplo.test>');

        self::assertSame('envio@exemplo.test', $sobrepor['remetente']);
        self::assertSame('envio@exemplo.test', CatalogoDeConfiguracoes::padroes($sobrepor)['remetente']);
        self::assertSame('envio@exemplo.test', CatalogoDeConfiguracoes::normalizar([], $sobrepor)['remetente']);
        self::assertArrayNotHasKey('remetente', CatalogoDeConfiguracoes::sobreposicoes(24));
        self::assertArrayNotHasKey('remetente', CatalogoDeConfiguracoes::sobreposicoes(24, 'lixo'));
        self::assertSame('nao-responda@bluejus.com.br', CatalogoDeConfiguracoes::padroes()['remetente']);
    }
}
