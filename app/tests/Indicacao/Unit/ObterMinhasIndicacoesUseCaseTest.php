<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\UseCase\ObterMinhasIndicacoesUseCase;
use App\Tests\Indicacao\Support\CenarioDeIndicacao;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A janela "Indicar o BlueJus" (bj-contas.js L98-133). */
#[CoversClass(ObterMinhasIndicacoesUseCase::class)]
#[CoversClass(CodigoDeIndicacao::class)]
final class ObterMinhasIndicacoesUseCaseTest extends TestCase
{
    use CenarioDeIndicacao;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    private function useCase(): ObterMinhasIndicacoesUseCase
    {
        return new ObterMinhasIndicacoesUseCase($this->indicacoes, $this->portao(), $this->codigo());
    }

    #[TestDox('cadastro completo: nome, link curto, mensagem sugerida do desenho, total e quantas viraram conta')]
    public function testJanelaCompleta(): void
    {
        $this->indicacoes->method('contarDoUsuario')->willReturn(12);
        $this->indicacoes->method('contarContasCriadasDoUsuario')->with(7)->willReturn(3);

        $saida = $this->useCase()->executar(Pessoas::usuario(7, 'Ana Souza Lima'));

        self::assertSame('Ana Souza Lima', $saida->nome);
        self::assertSame('https://bluejus.test/i/ABCD2345', $saida->linkCurto);
        self::assertSame(
            'Olá! Aqui é Ana Souza Lima. Uso o BlueJus no meu escritório para controlar prazos, processos e documentos em um só lugar, e é grátis para começar. Crie a sua conta pelo meu convite: https://bluejus.test/i/ABCD2345',
            $saida->mensagem,
        );
        self::assertSame([], $saida->pendencias);
        self::assertNull($saida->trava);
        self::assertSame(12, $saida->total);
        self::assertSame(3, $saida->contasCriadas);
        $json = $saida->paraJson();
        self::assertSame([12, 3], [$json['total'], $json['contasCriadas']]);
        self::assertArrayNotHasKey('ultimas', $json, 'a janela não lista mais os convites');
    }

    #[TestDox('cadastro incompleto: lista o que falta e não calcula trava (a caixa do cadastro vem antes)')]
    public function testCadastroIncompleto(): void
    {
        $this->pausado = true;

        $saida = $this->useCase()->executar(Pessoas::usuario(7, 'Ana', null));

        self::assertSame(['nome completo', 'CPF'], $saida->pendencias);
        self::assertSame('Por segurança, o convite sempre leva o seu nome, então precisamos do seu nome completo e CPF.', $saida->textoDasPendencias);
        self::assertNull($saida->trava);
        self::assertStringStartsWith('Olá! Aqui é Ana. ', $saida->mensagem);
    }

    #[TestDox('com cadastro completo e pausa, a janela traz a trava')]
    public function testTrava(): void
    {
        $this->pausado = true;

        self::assertSame('Seus envios de convite foram pausados pela administração do BlueJus.', $this->useCase()->executar(Pessoas::usuario(7))->trava);
    }

    #[TestDox('sem nome cadastrado a mensagem diz "um colega"')]
    public function testMensagemSemNome(): void
    {
        self::assertStringStartsWith('Olá! Aqui é um colega. ', ObterMinhasIndicacoesUseCase::mensagemSugerida('', 'https://x/i/A'));
    }
}
