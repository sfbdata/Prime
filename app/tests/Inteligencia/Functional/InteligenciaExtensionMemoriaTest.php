<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Inteligencia\Twig\InteligenciaExtension;
use App\Inteligencia\UseCase\ObterAnalisesRecolhidasUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * `ia_disponibilidade()` responde da memória dentro da requisição: a página a chama em mais de um lugar e cada
 * chamada eram até 5 consultas. A memória é por usuário e escritório, e o `reset()` (kernel.reset) a esvazia.
 * O contador é o provedor: é a primeira coisa que `DisponibilidadeDeInteligencia::para()` pergunta.
 */
#[CoversClass(InteligenciaExtension::class)]
final class InteligenciaExtensionMemoriaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private int $perguntas = 0;

    #[TestDox('Três chamadas na mesma requisição: uma pergunta; reset() pergunta de novo')]
    public function testUmaPerguntaPorRequisicao(): void
    {
        self::bootKernel();
        [$primario, $tenant] = $this->criarEscritorio();
        $this->autenticar($primario, $tenant);
        $ext = $this->extensao();

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $ext->iaDisponibilidade());
        }
        self::assertSame(1, $this->perguntas);

        $ext->reset();
        $ext->iaDisponibilidade();
        self::assertSame(2, $this->perguntas);
    }

    #[TestDox('Outra pessoa ou outro escritório no mesmo serviço pergunta de novo')]
    public function testOutraPessoaOuEscritorioNaoHerda(): void
    {
        self::bootKernel();
        [$primario, $tenant] = $this->criarEscritorio();
        $colaborador = $this->criarColaborador($tenant);
        [$outroPrimario, $outro] = $this->criarEscritorio();
        $ext = $this->extensao();

        $this->autenticar($primario, $tenant);
        $ext->iaDisponibilidade();
        $this->autenticar($colaborador, $tenant);
        $ext->iaDisponibilidade();
        $this->autenticar($outroPrimario, $outro);
        $ext->iaDisponibilidade();

        self::assertSame(3, $this->perguntas);
    }

    #[TestDox('Sem escritório na sessão: SemPermissao, sem perguntar')]
    public function testSemEscritorio(): void
    {
        self::bootKernel();
        [$primario] = $this->criarEscritorio();
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($primario, 'main', $primario->getRoles()));
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);

        self::assertSame(Disponibilidade::SemPermissao, $this->extensao()->iaDisponibilidade());
        self::assertSame(0, $this->perguntas);
    }

    private function autenticar(User $user, Tenant $tenant): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $session = new Session(new MockArraySessionStorage());
        $session->set('current_tenant_id', $tenant->getId());
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
    }

    /** A extensão com o Security e o TenantContext do container e uma disponibilidade que conta as perguntas. */
    private function extensao(): InteligenciaExtension
    {
        $container = static::getContainer();
        $provedor = $this->createStub(ProvedorDeLinguagem::class);
        $provedor->method('estaConfigurado')->willReturnCallback(function (): bool {
            ++$this->perguntas;

            return false;
        });
        $disponibilidade = new DisponibilidadeDeInteligencia(
            $provedor,
            $container->get(ConfiguracaoDeInteligenciaRepository::class),
            $container->get(AnaliseDeInteligenciaRepository::class),
            $container->get(PermissionChecker::class),
            true,
            $container->get(ConfiguracaoDaPlataformaDeIaRepository::class),
            new CustoDaIa('global'),
        );

        return new InteligenciaExtension(
            $disponibilidade,
            $container->get(Security::class),
            $container->get(TenantContext::class),
            $container->get(ObterAnalisesRecolhidasUseCase::class),
            $container->get(VisibilidadeDaAnaliseDaPasta::class),
        );
    }
}
