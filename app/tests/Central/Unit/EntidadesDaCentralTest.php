<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As invariantes das entidades da Central A1: par ordenado, sem conversa consigo, texto válido, cursor que não volta. */
#[CoversClass(Conversa::class)]
#[CoversClass(Mensagem::class)]
#[CoversClass(Participante::class)]
final class EntidadesDaCentralTest extends TestCase
{
    private function usuario(int $id): User
    {
        $u = new User();
        DefineId::em($u, $id);

        return $u;
    }

    #[TestDox('O par é guardado em ordem, quem quer que abra; o outro é sempre a outra pessoa')]
    public function testParOrdenado(): void
    {
        $tenant = new Tenant();
        [$sete, $tres] = [$this->usuario(7), $this->usuario(3)];
        $conversa = Conversa::individual($tenant, $sete, $tres, new \DateTimeImmutable());

        self::assertSame($tres, $conversa->getUsuarioMenor());
        self::assertSame($sete, $conversa->getUsuarioMaior());
        self::assertSame($sete, $conversa->getCriadaPor());
        self::assertSame($tres, $conversa->outroAlemDe($sete));
        self::assertSame($sete, $conversa->outroAlemDe($tres));
    }

    #[TestDox('Não existe conversa consigo mesmo')]
    public function testConsigoMesmo(): void
    {
        $this->expectException(\DomainException::class);

        Conversa::individual(new Tenant(), $this->usuario(5), $this->usuario(5), new \DateTimeImmutable());
    }

    #[TestDox('A data da última mensagem só anda para frente')]
    public function testUltimaMensagemSoAvanca(): void
    {
        $conversa = Conversa::individual(new Tenant(), $this->usuario(1), $this->usuario(2), new \DateTimeImmutable('2026-10-01'));
        $conversa->registrarMensagem(new \DateTimeImmutable('2026-10-10'));
        $conversa->registrarMensagem(new \DateTimeImmutable('2026-10-05'));

        self::assertEquals(new \DateTimeImmutable('2026-10-10'), $conversa->getUltimaMensagemEm());
    }

    #[TestDox('Mensagem é aparada; vazia ou acima de 4.000 caracteres é recusada')]
    public function testMensagem(): void
    {
        $tenant = new Tenant();
        $conversa = Conversa::individual($tenant, $this->usuario(1), $this->usuario(2), new \DateTimeImmutable());
        $autor = $conversa->getUsuarioMenor();

        self::assertSame('oi', (new Mensagem($tenant, $conversa, $autor, "  oi\n ", new \DateTimeImmutable()))->getConteudo());
        self::assertSame(4000, mb_strlen((new Mensagem($tenant, $conversa, $autor, str_repeat('é', 4000), new \DateTimeImmutable()))->getConteudo()));

        foreach (["  \n ", str_repeat('a', 4001)] as $invalido) {
            try {
                new Mensagem($tenant, $conversa, $autor, $invalido, new \DateTimeImmutable());
                self::fail('Deveria recusar.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('O cursor de leitura não volta')]
    public function testCursor(): void
    {
        $tenant = new Tenant();
        $conversa = Conversa::individual($tenant, $this->usuario(1), $this->usuario(2), new \DateTimeImmutable());
        $participante = new Participante($tenant, $conversa, $conversa->getUsuarioMenor(), new \DateTimeImmutable());

        $participante->marcarLidaAte(10);
        $participante->marcarLidaAte(4);

        self::assertSame(10, $participante->getUltimaLidaId());
    }

    #[TestDox('O teto de 4.000 conta o texto VISÍVEL: a menção vale "@Nome", não os bytes do token')]
    public function testTetoContaOTextoVisivel(): void
    {
        $tenant = new Tenant();
        [$um, $dois] = [$this->usuario(1), $this->usuario(2)];
        $conversa = Conversa::individual($tenant, $um, $dois, new \DateTimeImmutable());
        $token = '@[Ana](user:2)';                       // visível: "@Ana" (4 caracteres), gravado: 14
        $cheia = str_repeat('é', Mensagem::TAMANHO_MAXIMO - 4) . $token;

        $mensagem = new Mensagem($tenant, $conversa, $um, $cheia, new \DateTimeImmutable());
        self::assertSame(Mensagem::TAMANHO_MAXIMO, mb_strlen(Mensagem::textoVisivel($mensagem->getConteudo())));
        self::assertGreaterThan(Mensagem::TAMANHO_MAXIMO, mb_strlen($mensagem->getConteudo()), 'o gravado passa de 4.000 e tudo bem');

        $this->expectException(\DomainException::class);
        new Mensagem($tenant, $conversa, $um, 'x' . $cheia, new \DateTimeImmutable());
    }

    #[TestDox('Resposta só a mensagem da MESMA conversa (A2)')]
    public function testRespostaSoNaMesmaConversa(): void
    {
        $tenant = new Tenant();
        [$um, $dois, $tres] = [$this->usuario(1), $this->usuario(2), $this->usuario(3)];
        $esta = Conversa::individual($tenant, $um, $dois, new \DateTimeImmutable());
        $outra = Conversa::individual($tenant, $um, $tres, new \DateTimeImmutable());
        $original = new Mensagem($tenant, $esta, $dois, 'pergunta', new \DateTimeImmutable());
        $alheia = new Mensagem($tenant, $outra, $tres, 'de outra conversa', new \DateTimeImmutable());

        self::assertSame($original, (new Mensagem($tenant, $esta, $um, 'resposta', new \DateTimeImmutable(), $original))->getRespostaA());

        $this->expectException(\DomainException::class);
        new Mensagem($tenant, $esta, $um, 'resposta', new \DateTimeImmutable(), $alheia);
    }
}
