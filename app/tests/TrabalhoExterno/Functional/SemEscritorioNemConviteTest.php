<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\EventListener\TenantContextValidatorListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Auditoria 1.09: as rotas do Trabalho Externo passam sem escritório na sessão (por causa do Convidado). Quem está
 * logado, não é Master, não tem escritório e não tem convite vigente (conta recém-criada, ex-colaborador sem
 * vínculo) recebe 403 (ou 404, quando a entidade do caminho nem existe) em TODAS elas: a guarda é a
 * `FabricaDeContexto` de cada ação, não o filtro de tenant.
 */
#[CoversClass(TenantContextValidatorListener::class)]
final class SemEscritorioNemConviteTest extends JusPrimeWebTestCase
{
    #[TestDox('Logado sem escritório e sem convite: 403 ou 404, nunca dado, em toda rota do Trabalho Externo liberada sem escritório')]
    public function testTodaRotaLiberadaRecusa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('sem_escritorio_' . uniqid() . '@test.com');
        $user->setFullName('Sem Escritório');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $abertas = [];
        $testadas = 0;
        foreach ($router->getRouteCollection()->all() as $nome => $rota) {
            if ($rota->getDefault(TenantContextValidatorListener::ATRIBUTO_SEM_ESCRITORIO) !== true) {
                continue;
            }
            ++$testadas;
            $client->request($rota->getMethods()[0] ?? 'GET', self::caminho($rota), [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $status = $client->getResponse()->getStatusCode();
            // 404: a entidade do caminho (id 1) não existe neste banco e o MapEntity recusa antes da ação; nada sai.
            // O que importa é nunca 2xx/3xx. Que a ação resolve o contexto, o teste de arquitetura garante.
            if (!in_array($status, [403, 404], true)) {
                $abertas[] = $nome . ' => ' . $status;
            }
        }

        self::assertGreaterThan(50, $testadas);
        self::assertSame([], $abertas, 'rota do Trabalho Externo que não recusou quem não tem escritório nem convite');
    }

    /** O caminho com cada variável preenchida por um valor que casa com o requisito dela. */
    private static function caminho(Route $rota): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($rota): string {
            $requisito = (string) $rota->getRequirement($m[1]);

            return match (true) {
                str_contains($requisito, 'TE-') => 'TE-1',
                str_contains($requisito, 'a-f0-9') => str_repeat('a', 64),
                $requisito === '' || str_contains($requisito, '\d') => '1',
                default => 'x',
            };
        }, $rota->getPath());
    }
}
