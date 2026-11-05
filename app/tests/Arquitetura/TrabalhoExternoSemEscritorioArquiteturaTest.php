<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Entity\Auth\User;
use App\EventListener\TenantContextValidatorListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Auditoria 1.09: as rotas que passam SEM escritório na sessão (o Convidado do Trabalho Externo) não têm o filtro de
 * tenant ligado. A liberação era pelo prefixo do NOME da rota (`trabalho_externo_*`), e a guarda de cada ação dependia
 * só de convenção. Agora:
 *
 *  - a liberação é explícita: a rota leva o default `_sem_escritorio` (posto no `#[Route]` da classe do controller);
 *    um nome `trabalho_externo_*` sem ele vai à seleção de escritório como qualquer rota;
 *  - toda rota liberada resolve o contexto pela `FabricaDeContexto` (o `contextoOuNulo()` do `RespondeJsonTrait`,
 *    direto ou por um helper da própria classe). Sem escritório, a fábrica só devolve contexto ao Master ou a quem
 *    tem convite vigente;
 *  - as rotas `trabalho_externo_*` que não resolvem o contexto (o convite, por token, e as vagas públicas) estão em
 *    `ROTAS_IGNORADAS` do listener, com o motivo escrito lá.
 *
 * O teste percorre o router inteiro. Rota nova que não se encaixar quebra aqui.
 */
#[CoversClass(TenantContextValidatorListener::class)]
final class TrabalhoExternoSemEscritorioArquiteturaTest extends KernelTestCase
{
    /** A chamada que resolve o contexto (`RespondeJsonTrait::contextoOuNulo`, que chama a `FabricaDeContexto`). */
    private const RESOLVE_O_CONTEXTO = 'contextoOuNulo';

    #[TestDox('Toda rota trabalho_externo_* é liberada explicitamente (_sem_escritorio) ou está nas ROTAS_IGNORADAS')]
    public function testTodaRotaDoTeTemDestinoConhecido(): void
    {
        $ignoradas = self::rotasIgnoradas();
        $soltas = [];
        foreach (self::rotas() as $nome => $rota) {
            if (str_starts_with($nome, 'trabalho_externo_') && !self::liberada($rota) && !in_array($nome, $ignoradas, true)) {
                $soltas[] = $nome;
            }
        }

        self::assertSame([], $soltas, 'rota do Trabalho Externo sem _sem_escritorio nem lugar em ROTAS_IGNORADAS');
    }

    #[TestDox('Toda rota liberada sem escritório resolve o contexto pela FabricaDeContexto na própria ação')]
    public function testRotaLiberadaResolveOContexto(): void
    {
        $semGuarda = [];
        $liberadas = 0;
        foreach (self::rotas() as $nome => $rota) {
            if (!self::liberada($rota)) {
                continue;
            }
            ++$liberadas;
            [$classe, $metodo] = explode('::', (string) $rota->getDefault('_controller')) + [1 => '__invoke'];
            if (!in_array($metodo, self::metodosQueResolvem($classe), true)) {
                $semGuarda[] = $nome . ' (' . $classe . '::' . $metodo . ')';
            }
        }

        self::assertGreaterThan(50, $liberadas, 'as rotas do Trabalho Externo levam o _sem_escritorio');
        self::assertSame([], $semGuarda, 'rota liberada sem escritório que não chama ' . self::RESOLVE_O_CONTEXTO . '()');
    }

    #[TestDox('Nome trabalho_externo_* sem _sem_escritorio, logado sem escritório: vai à seleção de escritório')]
    public function testPrefixoSozinhoNaoLibera(): void
    {
        $resposta = $this->rodarOListener(['_route' => 'trabalho_externo_inventada']);

        self::assertInstanceOf(RedirectResponse::class, $resposta);
        self::assertStringEndsWith('/escritorio/selecionar', $resposta->getTargetUrl());
    }

    #[TestDox('Rota com _sem_escritorio, logado sem escritório: o listener deixa passar (a guarda é a da ação)')]
    public function testLiberacaoExplicitaPassa(): void
    {
        self::assertNull($this->rodarOListener(['_route' => 'qualquer_nome', '_sem_escritorio' => true]));
    }

    /** @param array<string, mixed> $atributos */
    private function rodarOListener(array $atributos): ?\Symfony\Component\HttpFoundation\Response
    {
        self::bootKernel();
        $container = static::getContainer();

        $request = Request::create('/qualquer');
        $request->attributes->add($atributos);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);

        $user = new User();
        $user->setEmail('sem_escritorio_' . uniqid() . '@test.com');
        $user->setRoles(['ROLE_USER']);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $evento = new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $container->get(TenantContextValidatorListener::class)($evento);

        return $evento->getResponse();
    }

    /** @return array<string, Route> */
    private static function rotas(): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->getRouteCollection()->all();
    }

    private static function liberada(Route $rota): bool
    {
        return $rota->getDefault(TenantContextValidatorListener::ATRIBUTO_SEM_ESCRITORIO) === true;
    }

    /** @return list<string> */
    private static function rotasIgnoradas(): array
    {
        $valor = (new \ReflectionClassConstant(TenantContextValidatorListener::class, 'ROTAS_IGNORADAS'))->getValue();

        return is_array($valor) ? array_values(array_map('strval', $valor)) : [];
    }

    /**
     * Métodos da classe (com os dos traits) que chamam `contextoOuNulo()`, direto ou por outro método dela
     * (`$this->ler(...)`, `$this->escrever(...)`): fecho transitivo sobre as chamadas `$this->x(`.
     *
     * @param class-string $classe
     *
     * @return list<string>
     */
    private static function metodosQueResolvem(string $classe): array
    {
        $corpos = [];
        foreach ((new \ReflectionClass($classe))->getMethods() as $metodo) {
            $corpos[$metodo->getName()] = self::corpo($metodo);
        }

        $resolvem = [self::RESOLVE_O_CONTEXTO => true];
        do {
            $antes = count($resolvem);
            foreach ($corpos as $nome => $corpo) {
                if (isset($resolvem[$nome]) || $nome === self::RESOLVE_O_CONTEXTO) {
                    continue;
                }
                preg_match_all('/\$this->(\w+)\(/', $corpo, $chamadas);
                if (array_intersect($chamadas[1], array_keys($resolvem)) !== []) {
                    $resolvem[$nome] = true;
                }
            }
        } while (count($resolvem) > $antes);

        return array_keys($resolvem);
    }

    private static function corpo(\ReflectionMethod $metodo): string
    {
        $arquivo = $metodo->getFileName();
        if ($arquivo === false) {
            return '';
        }
        $linhas = file($arquivo) ?: [];

        return implode('', array_slice($linhas, (int) $metodo->getStartLine() - 1, (int) $metodo->getEndLine() - (int) $metodo->getStartLine() + 1));
    }
}
