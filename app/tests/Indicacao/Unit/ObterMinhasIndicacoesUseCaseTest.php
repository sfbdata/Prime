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

    #[TestDox('cadastro completo: nome, link curto, mensagem sugerida do desenho, total e as 8 mais recentes')]
    public function testJanelaCompleta(): void
    {
        $this->indicacoes->method('contarDoUsuario')->willReturn(12);
        $this->indicacoes->method('ultimasDoUsuario')->willReturnCallback(static function (int $id, int $limite): array {
            self::assertSame(8, $limite);

            return [
                ['nome' => 'Bia', 'email' => 'bia@exemplo.com', 'canal' => 'email', 'criada_em' => '2026-10-18 10:00:00', 'conta_criada' => true],
                ['nome' => null, 'email' => null, 'canal' => 'whatsapp', 'criada_em' => '2026-10-17 09:00:00', 'conta_criada' => false],
            ];
        });

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
        $json = $saida->paraJson();
        self::assertSame(['nome' => 'Bia', 'email' => 'bia@exemplo.com', 'canal' => 'email', 'em' => '18/10/2026', 'contaCriada' => true], $json['ultimas'][0]);
        self::assertFalse($json['ultimas'][1]['contaCriada']);
    }

    #[TestDox('cadastro incompleto: lista o que falta e não calcula trava (a caixa do cadastro vem antes)')]
    public function testCadastroIncompleto(): void
    {
        $this->pausado = true;
        $this->indicacoes->method('ultimasDoUsuario')->willReturn([]);

        $saida = $this->useCase()->executar(Pessoas::usuario(7, 'Ana', null));

        self::assertSame(['nome completo', 'CPF'], $saida->pendencias);
        self::assertSame('Por segurança, o convite sempre leva o seu nome, então precisamos do seu nome completo, CPF.', $saida->textoDasPendencias);
        self::assertNull($saida->trava);
        self::assertStringStartsWith('Olá! Aqui é Ana. ', $saida->mensagem);
    }

    #[TestDox('com cadastro completo e pausa, a janela traz a trava')]
    public function testTrava(): void
    {
        $this->pausado = true;
        $this->indicacoes->method('ultimasDoUsuario')->willReturn([]);

        self::assertSame('Seus envios de convite foram pausados pela administração do BlueJus.', $this->useCase()->executar(Pessoas::usuario(7))->trava);
    }

    #[TestDox('sem nome cadastrado a mensagem diz "um colega"')]
    public function testMensagemSemNome(): void
    {
        self::assertStringStartsWith('Olá! Aqui é um colega. ', ObterMinhasIndicacoesUseCase::mensagemSugerida('', 'https://x/i/A'));
    }
}
