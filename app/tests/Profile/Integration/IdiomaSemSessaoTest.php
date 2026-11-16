<?php

declare(strict_types=1);

namespace App\Tests\Profile\Integration;

use App\Entity\Auth\User;
use App\Profile\Enum\Idioma;
use App\Profile\EventListener\IdiomaDaRequisicaoListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * O idioma da requisição não toca a sessão quando a requisição é sem sessão (`_stateless`), como o `/mcp` do conector
 * de I.A. (spec mcp-remoto-oauth 12.18): ler ou gravar nela abriria a sessão e mandaria `Set-Cookie`. Pessoa logada,
 * com sessão disponível: com `_stateless`, a sessão nem começa e o idioma é o português; sem ele, o listener segue
 * guardando o idioma na sessão, como antes.
 */
#[CoversClass(IdiomaDaRequisicaoListener::class)]
final class IdiomaSemSessaoTest extends KernelTestCase
{
    private IdiomaDaRequisicaoListener $listener;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $pessoa = (new User())->setEmail(uniqid('idioma_sem_sessao_') . '@test.com')->setFullName('Ana Souza')->setRoles(['ROLE_USER'])->setIsActive(true);
        $pessoa->setPassword('x');
        $em->persist($pessoa);
        $em->flush();

        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($pessoa, 'conector_mcp', $pessoa->getRoles()));
        $this->listener = $container->get(IdiomaDaRequisicaoListener::class);
    }

    #[TestDox('requisição sem sessão (_stateless): o idioma é o português e a sessão nem começa')]
    public function testSemSessaoNaoTocaASessao(): void
    {
        $sessao = new Session(new MockArraySessionStorage());
        $requisicao = $this->requisicao($sessao, semSessao: true);

        ($this->listener)(new RequestEvent(self::$kernel, $requisicao, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($sessao->isStarted(), 'o listener não pode abrir a sessão numa requisição sem sessão');
        self::assertSame(Idioma::PADRAO->locale(), $requisicao->getLocale());
    }

    #[TestDox('requisição com sessão: o listener guarda o idioma da pessoa na sessão, como antes')]
    public function testComSessaoGuardaOIdioma(): void
    {
        $sessao = new Session(new MockArraySessionStorage());
        $requisicao = $this->requisicao($sessao, semSessao: false);

        ($this->listener)(new RequestEvent(self::$kernel, $requisicao, HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue($sessao->isStarted());
        self::assertIsArray($sessao->get(IdiomaDaRequisicaoListener::CHAVE_DA_SESSAO));
        self::assertSame(Idioma::PADRAO->locale(), $requisicao->getLocale());
    }

    private function requisicao(Session $sessao, bool $semSessao): Request
    {
        $requisicao = Request::create('/mcp', 'POST');
        $requisicao->setSession($sessao);
        if ($semSessao) {
            $requisicao->attributes->set('_stateless', true);
        }

        return $requisicao;
    }
}
