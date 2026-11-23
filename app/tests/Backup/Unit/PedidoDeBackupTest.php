<?php

declare(strict_types=1);

namespace App\Tests\Backup\Unit;

use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PedidoDeBackup::class)]
final class PedidoDeBackupTest extends TestCase
{
    private const SHA = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-11-23 12:00:00');
    }

    private static function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private static function usuario(int $id): User
    {
        $usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }

    private function pedido(?Tenant $tenant = null, ?User $usuario = null): PedidoDeBackup
    {
        return new PedidoDeBackup($tenant ?? self::tenant(1), $usuario ?? self::usuario(10), $this->agora);
    }

    private function resultado(): ResultadoDoBackup
    {
        return new ResultadoDoBackup('abc.zip', 2048, self::SHA, 5, 2);
    }

    private function emPreparo(): PedidoDeBackup
    {
        $pedido = $this->pedido();
        $pedido->iniciarMontagem($this->agora);

        return $pedido;
    }

    private function pronto(?\DateTimeImmutable $expiraEm = null): PedidoDeBackup
    {
        $pedido = $this->emPreparo();
        $pedido->concluir($this->resultado(), $this->agora, $expiraEm ?? $this->agora->modify('+7 days'));

        return $pedido;
    }

    #[TestDox('nasce na situação pedido, sem arquivo')]
    public function testNasceComoPedido(): void
    {
        $pedido = $this->pedido();

        self::assertSame(SituacaoDoBackup::Pedido, $pedido->getSituacao());
        self::assertNull($pedido->getChaveArquivo());
        self::assertNull($pedido->getExpiraEm());
        self::assertSame(0, $pedido->getArquivosIncluidos());
    }

    #[TestDox('pedido, montando, pronto e vencido: ciclo completo')]
    public function testCicloCompleto(): void
    {
        $pedido = $this->pedido();

        $pedido->iniciarMontagem($this->agora);
        self::assertSame(SituacaoDoBackup::Montando, $pedido->getSituacao());
        self::assertEquals($this->agora, $pedido->getIniciadoEm());

        $expira = $this->agora->modify('+7 days');
        $pedido->concluir($this->resultado(), $this->agora, $expira);
        self::assertSame(SituacaoDoBackup::Pronto, $pedido->getSituacao());
        self::assertSame('abc.zip', $pedido->getChaveArquivo());
        self::assertSame(2048, $pedido->getTamanhoBytes());
        self::assertSame(self::SHA, $pedido->getSha256());
        self::assertSame(5, $pedido->getArquivosIncluidos());
        self::assertSame(2, $pedido->getArquivosFora());
        self::assertEquals($this->agora, $pedido->getConcluidoEm());
        self::assertEquals($expira, $pedido->getExpiraEm());

        self::assertSame('abc.zip', $pedido->vencer($this->depoisDaValidade()));
        self::assertSame(SituacaoDoBackup::Vencido, $pedido->getSituacao());
        self::assertNull($pedido->getChaveArquivo(), 'vencido não aponta mais para o ZIP');
    }

    #[TestDox('pedido na fila pode falhar')]
    public function testFalharDaFila(): void
    {
        $pedido = $this->pedido();

        $pedido->falhar('sem espaço', $this->agora);

        self::assertSame(SituacaoDoBackup::Falhou, $pedido->getSituacao());
        self::assertSame('sem espaço', $pedido->getErro());
        self::assertEquals($this->agora, $pedido->getConcluidoEm());
    }

    #[TestDox('pedido em preparo pode falhar')]
    public function testFalharMontando(): void
    {
        $pedido = $this->emPreparo();

        $pedido->falhar('  erro ao gravar  ', $this->agora);

        self::assertSame(SituacaoDoBackup::Falhou, $pedido->getSituacao());
        self::assertSame('erro ao gravar', $pedido->getErro(), 'o motivo vai sem espaços nas pontas');
    }

    #[TestDox('o motivo da falha é cortado em 255 caracteres')]
    public function testErroTruncadoEm255(): void
    {
        $pedido = $this->emPreparo();

        $pedido->falhar(str_repeat('é', 400), $this->agora);

        self::assertSame(PedidoDeBackup::TAMANHO_DO_ERRO, 255);
        self::assertSame(255, mb_strlen((string) $pedido->getErro()));
    }

    #[TestDox('concluir sem montar lança DomainException')]
    public function testConcluirSemMontar(): void
    {
        $this->expectException(\DomainException::class);

        $this->pedido()->concluir($this->resultado(), $this->agora, $this->agora->modify('+7 days'));
    }

    #[TestDox('concluir duas vezes lança DomainException')]
    public function testConcluirDuasVezes(): void
    {
        $pedido = $this->pronto();

        $this->expectException(\DomainException::class);
        $pedido->concluir($this->resultado(), $this->agora, $this->agora->modify('+7 days'));
    }

    #[TestDox('validade igual ou anterior à conclusão lança, e o pedido continua em preparo')]
    public function testValidadeAntesDaConclusao(): void
    {
        $pedido = $this->emPreparo();

        foreach ([$this->agora, $this->agora->modify('-1 second')] as $expira) {
            try {
                $pedido->concluir($this->resultado(), $this->agora, $expira);
                self::fail('devia recusar a validade');
            } catch (\DomainException) {
                self::assertSame(SituacaoDoBackup::Montando, $pedido->getSituacao());
                self::assertNull($pedido->getChaveArquivo());
            }
        }
    }

    #[TestDox('vencer sem estar pronto lança DomainException')]
    public function testVencerSemPronto(): void
    {
        foreach ([$this->pedido(), $this->emPreparo()] as $pedido) {
            try {
                $pedido->vencer($this->depoisDaValidade());
                self::fail('devia recusar vencer');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('vencer duas vezes lança DomainException')]
    public function testVencerDuasVezes(): void
    {
        $pedido = $this->pronto();
        $pedido->vencer($this->depoisDaValidade());

        $this->expectException(\DomainException::class);
        $pedido->vencer($this->depoisDaValidade());
    }

    #[TestDox('falhar depois de pronto lança DomainException')]
    public function testFalharDepoisDePronto(): void
    {
        $pedido = $this->pronto();

        $this->expectException(\DomainException::class);
        $pedido->falhar('tarde demais', $this->agora);
    }

    #[TestDox('falhar duas vezes lança DomainException')]
    public function testFalharDuasVezes(): void
    {
        $pedido = $this->pedido();
        $pedido->falhar('um', $this->agora);

        $this->expectException(\DomainException::class);
        $pedido->falhar('dois', $this->agora);
    }

    #[TestDox('iniciar a montagem duas vezes lança DomainException')]
    public function testIniciarDuasVezes(): void
    {
        $pedido = $this->emPreparo();

        $this->expectException(\DomainException::class);
        $pedido->iniciarMontagem($this->agora);
    }

    #[TestDox('iniciar a montagem de pedido que falhou lança DomainException')]
    public function testIniciarDepoisDeFalhar(): void
    {
        $pedido = $this->pedido();
        $pedido->falhar('x', $this->agora);

        $this->expectException(\DomainException::class);
        $pedido->iniciarMontagem($this->agora);
    }

    #[TestDox('venceuEm só vale para pronto, e a partir da hora da validade')]
    public function testVenceuEm(): void
    {
        $expira = $this->agora->modify('+7 days');
        $pronto = $this->pronto($expira);

        self::assertFalse($pronto->venceuEm($expira->modify('-1 second')));
        self::assertTrue($pronto->venceuEm($expira));
        self::assertTrue($pronto->venceuEm($expira->modify('+1 day')));

        self::assertFalse($this->pedido()->venceuEm($expira->modify('+1 year')), 'na fila não vence');
        self::assertFalse($this->emPreparo()->venceuEm($expira->modify('+1 year')), 'em preparo não vence');

        $vencido = $this->pronto($expira);
        $vencido->vencer($this->depoisDaValidade());
        self::assertFalse($vencido->venceuEm($expira->modify('+1 year')), 'já vencido não conta de novo');
    }

    #[TestDox('podeSerBaixadoPor: o dono, no mesmo escritório, com o backup pronto e na validade')]
    public function testPodeSerBaixadoPeloDono(): void
    {
        $pedido = $this->pronto();

        self::assertTrue($pedido->podeSerBaixadoPor(self::usuario(10), self::tenant(1), $this->agora->modify('+1 day')));
    }

    #[TestDox('podeSerBaixadoPor: outro usuário do mesmo escritório não baixa')]
    public function testOutroUsuarioNaoBaixa(): void
    {
        $pedido = $this->pronto();

        self::assertFalse($pedido->podeSerBaixadoPor(self::usuario(11), self::tenant(1), $this->agora));
    }

    #[TestDox('podeSerBaixadoPor: o mesmo usuário em outro escritório não baixa')]
    public function testOutroTenantNaoBaixa(): void
    {
        $pedido = $this->pronto();

        self::assertFalse($pedido->podeSerBaixadoPor(self::usuario(10), self::tenant(2), $this->agora));
    }

    #[TestDox('podeSerBaixadoPor: sem id de usuário ou de escritório não baixa')]
    public function testSemIdNaoBaixa(): void
    {
        $pedido = $this->pronto();

        self::assertFalse($pedido->podeSerBaixadoPor(new User(), self::tenant(1), $this->agora));
        self::assertFalse($pedido->podeSerBaixadoPor(self::usuario(10), new Tenant(), $this->agora));

        $semIds = new PedidoDeBackup(new Tenant(), new User(), $this->agora);
        $semIds->iniciarMontagem($this->agora);
        $semIds->concluir($this->resultado(), $this->agora, $this->agora->modify('+7 days'));
        self::assertFalse($semIds->podeSerBaixadoPor(new User(), new Tenant(), $this->agora), 'dois ids nulos não são iguais');
    }

    #[TestDox('podeSerBaixadoPor: backup vencido não baixa, nem pela hora nem pela situação')]
    public function testVencidoNaoBaixa(): void
    {
        $expira = $this->agora->modify('+7 days');
        $pedido = $this->pronto($expira);

        self::assertFalse($pedido->podeSerBaixadoPor(self::usuario(10), self::tenant(1), $expira), 'passou da validade');

        $pedido->vencer($this->depoisDaValidade());
        self::assertFalse($pedido->podeSerBaixadoPor(self::usuario(10), self::tenant(1), $this->agora), 'situação vencido');
    }

    #[TestDox('podeSerBaixadoPor: pedido que não está pronto não baixa')]
    public function testNaoProntoNaoBaixa(): void
    {
        $falhou = $this->pedido();
        $falhou->falhar('x', $this->agora);

        foreach ([$this->pedido(), $this->emPreparo(), $falhou] as $pedido) {
            self::assertFalse($pedido->podeSerBaixadoPor(self::usuario(10), self::tenant(1), $this->agora));
        }
    }

    #[TestDox('vencer antes da validade lança DomainException')]
    public function testVencerAntesDaValidade(): void
    {
        $expira = $this->agora->modify('+7 days');
        $pedido = $this->pronto($expira);

        $this->expectException(\DomainException::class);
        $pedido->vencer($expira->modify('-1 second'));
    }

    private function depoisDaValidade(): \DateTimeImmutable
    {
        return $this->agora->modify('+10 years');
    }
}
