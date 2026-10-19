<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Entity\MarcacaoDeMensagem;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\OperacaoDeMensagem;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As marcações pessoais e o "apagar para todos" (spec VS 18.10.26 §2 e §3.4). */
#[CoversClass(MarcacaoDoUsuario::class)]
#[CoversClass(MarcacaoDeMensagem::class)]
#[CoversClass(MensagemDemanda::class)]
final class MarcacoesTest extends TestCase
{
    #[TestDox('lixeira, restaurar e definitivo: o definitivo tira da lixeira e exclui da lista')]
    public function testLixeiraDoItem(): void
    {
        $m = new MarcacaoDoUsuario(Apoio::usuario(1), TipoItemMarcado::Demanda, 7);
        $agora = new \DateTimeImmutable();

        $m->aplicar(OperacaoDeMarcacao::Lixeira, $agora);
        self::assertTrue($m->naLixeira());
        $m->aplicar(OperacaoDeMarcacao::Restaurar, $agora);
        self::assertFalse($m->naLixeira());
        $m->aplicar(OperacaoDeMarcacao::Lixeira, $agora);
        $m->aplicar(OperacaoDeMarcacao::Definitivo, $agora);
        self::assertFalse($m->naLixeira());
        self::assertTrue($m->excluida());
    }

    #[TestDox('estrela, importância manual e não lida ligam e desligam')]
    public function testEstrelaImportanciaENaoLida(): void
    {
        $m = new MarcacaoDoUsuario(Apoio::usuario(1), TipoItemMarcado::Demanda, 7);
        $agora = new \DateTimeImmutable();
        self::assertNull($m->getImportante(), 'sem escolha, a importância é automática');

        $m->aplicar(OperacaoDeMarcacao::Estrela, $agora);
        $m->aplicar(OperacaoDeMarcacao::NaoImportante, $agora);
        $m->aplicar(OperacaoDeMarcacao::NaoLida, $agora);
        self::assertTrue($m->temEstrela());
        self::assertFalse($m->getImportante());
        self::assertTrue($m->isNaoLida());

        $m->aplicar(OperacaoDeMarcacao::SemEstrela, $agora);
        $m->aplicar(OperacaoDeMarcacao::Importante, $agora);
        $m->aplicar(OperacaoDeMarcacao::Lida, $agora);
        self::assertFalse($m->temEstrela());
        self::assertTrue($m->getImportante());
        self::assertFalse($m->isNaoLida());
    }

    #[TestDox('mensagem na lixeira há mais de 30 dias conta como excluída, sem cron')]
    public function testLixeiraDoChatExpiraEm30Dias(): void
    {
        $mm = new MarcacaoDeMensagem($this->mensagem(TipoMensagem::Normal), Apoio::usuario(1));
        $mm->aplicar(OperacaoDeMensagem::Lixeira, new \DateTimeImmutable('2026-09-01'));

        self::assertTrue($mm->naLixeira(new \DateTimeImmutable('2026-09-30')));
        self::assertTrue($mm->excluida(new \DateTimeImmutable('2026-10-02')));
        self::assertFalse($mm->naLixeira(new \DateTimeImmutable('2026-10-02')));
    }

    #[TestDox('favoritar e desfavoritar a mensagem')]
    public function testFavorito(): void
    {
        $mm = new MarcacaoDeMensagem($this->mensagem(TipoMensagem::Normal), Apoio::usuario(1));
        $mm->aplicar(OperacaoDeMensagem::Favoritar, new \DateTimeImmutable());
        self::assertTrue($mm->isFavorita());
        $mm->aplicar(OperacaoDeMensagem::Desfavoritar, new \DateTimeImmutable());
        self::assertFalse($mm->isFavorita());
    }

    #[TestDox('apagar para todos: qualquer tipo menos o registro do sistema, uma vez só, guardando quem apagou')]
    public function testApagarParaTodos(): void
    {
        $andamento = $this->mensagem(TipoMensagem::Andamento);
        $andamento->apagar(Apoio::autor(Perfil::Master), new \DateTimeImmutable());
        self::assertTrue($andamento->foiApagada());
        self::assertSame('Fulano', $andamento->getApagadaPor());
        self::assertSame(Perfil::Master, $andamento->getApagadaPorPerfil());
        self::assertFalse($andamento->podeSerApagada());

        $this->expectException(\DomainException::class);
        $this->mensagem(TipoMensagem::Sistema)->apagar(Apoio::autor(), new \DateTimeImmutable());
    }

    #[TestDox('quem apaga para todos: o Master sempre; o autor só até 60 min; outro perfil não; na prévia ninguém')]
    public function testQuemApagaParaTodos(): void
    {
        $politica = new PoliticaDaDemanda();
        $autor = Apoio::usuario(50);
        $mensagem = $this->mensagem(TipoMensagem::Normal);
        $agora = new \DateTimeImmutable();

        self::assertTrue($politica->podeApagarMensagem(Apoio::contexto(Perfil::Solicitante, usuario: $autor), $mensagem, $agora->modify('+59 minutes')));
        self::assertFalse($politica->podeApagarMensagem(Apoio::contexto(Perfil::Solicitante, usuario: $autor), $mensagem, $agora->modify('+61 minutes')), 'prazo de 60 min encerrado');
        self::assertTrue($politica->podeApagarMensagem(Apoio::contexto(Perfil::Master), $mensagem, $agora->modify('+5 days')));
        self::assertFalse($politica->podeApagarMensagem(Apoio::contexto(Perfil::Equipe), $mensagem, $agora));
        $ctx = Apoio::contexto(Perfil::Master);
        $previa = new Contexto($ctx->usuario, $ctx->tenant, Perfil::Master, $ctx->configuracao, previa: true);
        self::assertFalse($politica->podeApagarMensagem($previa, $mensagem));
    }

    private function mensagem(TipoMensagem $tipo): MensagemDemanda
    {
        return new MensagemDemanda(Apoio::demanda(Situacao::Analise), Apoio::autor(Perfil::Solicitante), $tipo, 'Olá');
    }
}
