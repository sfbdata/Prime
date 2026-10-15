<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\Entity\Conversa;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Aviso de menção sem duplicata na corrida (`docs/specs/central-comunicacao-a2.md` §2): a trava de cada
 * destinatário vem ANTES da checagem "já há aviso não lido?", em ordem de id. Quem prova que a trava exclui de
 * verdade entre sessões é `TravaPostgresDosAvisosDeMencaoTest`.
 */
#[CoversClass(AvisoDeMencaoNaCentral::class)]
final class AvisoDeMencaoNaCentralTest extends TestCase
{
    private function pessoa(int $id): User
    {
        $u = new User();
        $u->setFullName('Pessoa ' . $id);
        DefineId::em($u, $id);

        return $u;
    }

    #[TestDox('Cada destinatário é travado antes de se olhar se já tem aviso — e em ordem de id, para não haver espera cruzada')]
    public function testTravaAntesDaChecagemEmOrdem(): void
    {
        $tenant = new Tenant();
        $conversa = Conversa::individual($tenant, $this->pessoa(1), $this->pessoa(9), new \DateTimeImmutable());
        DefineId::em($conversa, 77);
        $passos = [];

        $trava = $this->createMock(TravaDosAvisosDeMencao::class);
        $trava->expects(self::exactly(2))->method('travar')->willReturnCallback(function (User $u) use (&$passos): void {
            $passos[] = 'trava ' . $u->getId();
        });
        $repositorio = $this->createMock(NotificacaoRepository::class);
        $repositorio->method('findOneBy')->willReturnCallback(function (array $criterio) use (&$passos): ?Notificacao {
            $passos[] = 'confere ' . $criterio['usuario']->getId();

            return null;
        });
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::exactly(2))->method('criar')->willReturnCallback(function (User $u) use (&$passos): Notificacao {
            $passos[] = 'grava ' . $u->getId();

            return new Notificacao();
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/central?conversa=77');

        (new AvisoDeMencaoNaCentral($notificacoes, $repositorio, $urls, $trava))
            ->avisar($conversa, $this->pessoa(1), $tenant, [$this->pessoa(9), $this->pessoa(4)]);

        self::assertSame(['trava 4', 'confere 4', 'grava 4', 'trava 9', 'confere 9', 'grava 9'], $passos);
    }

    #[TestDox('Já há aviso não lido: trava, confere e não grava outro')]
    public function testComAvisoPendenteNaoGrava(): void
    {
        $tenant = new Tenant();
        $conversa = Conversa::individual($tenant, $this->pessoa(1), $this->pessoa(2), new \DateTimeImmutable());
        DefineId::em($conversa, 5);
        $trava = $this->createMock(TravaDosAvisosDeMencao::class);
        $trava->expects(self::once())->method('travar');
        $repositorio = $this->createStub(NotificacaoRepository::class);
        $repositorio->method('findOneBy')->willReturn(new Notificacao());
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::never())->method('criar');

        (new AvisoDeMencaoNaCentral($notificacoes, $repositorio, $this->createStub(UrlGeneratorInterface::class), $trava))
            ->avisar($conversa, $this->pessoa(1), $tenant, [$this->pessoa(2)]);
    }

    #[TestDox('Menções por ver: o id sai do link exato (12 não é 123, parâmetro extra não atrapalha) e só de conversa ATIVA minha')]
    public function testConversasComMencaoNaoLida(): void
    {
        $repositorio = $this->createStub(NotificacaoRepository::class);
        $repositorio->method('urlsNaoLidasDoTipo')->willReturn([
            '/central?conversa=12',
            '/central?conversa=123&x=1',
            '/central?x=1&conversa=7',
            '/central?conversa=99',        // não é conversa ativa minha (saí ou nunca participei)
            '/central?conversa=12',        // repetida: conta uma vez
            '/central?conversaX=12',
            '/outra?conversa=abc',
        ]);
        $aviso = new AvisoDeMencaoNaCentral(
            $this->createStub(NotificacaoService::class), $repositorio,
            $this->createStub(UrlGeneratorInterface::class), $this->createStub(TravaDosAvisosDeMencao::class),
        );

        $ids = $aviso->conversasComMencaoNaoLida($this->pessoa(1), new Tenant(), [12 => true, 123 => true, 7 => true]);

        self::assertSame([12, 123, 7], $ids);
    }
}
