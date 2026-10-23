<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Entity\Auth\User;
use App\Shared\Scheduler\Entity\FalhaAgendada;
use App\Shared\Scheduler\QuemVeAvisoDoAgendador;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use App\Shared\Scheduler\Twig\AvisoDeFalhaAgendadaExtension;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Para onde o "Dispensar" do aviso de falha volta (spec scheduler-tarefas-agendadas, "Aviso de falha"): a
 * própria página só quando ela veio de um GET; numa página montada por POST, o início.
 */
#[CoversClass(AvisoDeFalhaAgendadaExtension::class)]
final class AvisoDeFalhaAgendadaExtensionTest extends TestCase
{
    public function testPaginaDeGetVoltaParaElaMesma(): void
    {
        self::assertSame('/pastas?pagina=2', $this->aviso(Request::create('/pastas?pagina=2', 'GET'))['voltar'] ?? null);
    }

    public function testPaginaDePostVoltaParaOInicio(): void
    {
        self::assertSame('/', $this->aviso(Request::create('/clientes/novo', 'POST'))['voltar'] ?? null);
    }

    public function testQuemNaoVeNaoRecebeAviso(): void
    {
        self::assertNull($this->aviso(Request::create('/', 'GET'), ['ROLE_USER']));
    }

    /**
     * @param list<string> $papeis
     *
     * @return array<string, mixed>|null
     */
    private function aviso(Request $request, array $papeis = ['ROLE_SUPER_ADMIN']): ?array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn((new User())->setEmail('quem@exemplo.com')->setRoles($papeis));
        $falhas = $this->createStub(FalhaAgendadaRepository::class);
        $falhas->method('pendentesRecentes')->willReturn([new FalhaAgendada('app:djen:sincronizar', new \DateTimeImmutable('2026-10-23 03:00'), 'Erro', 1)]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/');
        $pilha = new RequestStack();
        $pilha->push($request);

        return (new AvisoDeFalhaAgendadaExtension($security, new QuemVeAvisoDoAgendador(''), $falhas, new LoggerEmMemoria(), $pilha, $urls))
            ->avisoDeFalhaAgendada();
    }
}
