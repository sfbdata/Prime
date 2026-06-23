<?php

declare(strict_types=1);

namespace App\Tests\Termo\Functional;

use App\EventListener\TermoAceiteListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guard de regressão para o bug de ordem de listener: o gate de aceite PRECISA rodar
 * depois do firewall do Symfony (senão getUser() é null numa request real de sessão) e
 * antes do gate de tenant. Os testes que usam loginUser() não pegam isso, porque injetam
 * o token antes da request — por isso este teste inspeciona a ordem real no dispatcher.
 */
#[CoversClass(TermoAceiteListener::class)]
final class TermoAceiteListenerOrdemTest extends KernelTestCase
{
    /** @return list<string> classes dos listeners de kernel.request, em ordem de execução */
    private function ordemListeners(): array
    {
        self::bootKernel();
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);

        $classes = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $alvo = is_array($listener) ? $listener[0] : $listener;
            $classes[] = $alvo::class;
        }

        return $classes;
    }

    #[TestDox('O gate de aceite roda depois do firewall e antes do gate de tenant')]
    public function testOrdemDoGate(): void
    {
        $ordem = $this->ordemListeners();

        $idxTermo = $this->indicePorFragmento($ordem, 'TermoAceiteListener');
        $idxFirewall = $this->indicePorFragmento($ordem, 'Firewall');
        $idxTenant = $this->indicePorFragmento($ordem, 'TenantContextValidatorListener');

        self::assertNotNull($idxFirewall, 'Firewall do Symfony não encontrado em kernel.request.');
        self::assertNotNull($idxTermo, 'TermoAceiteListener não registrado em kernel.request.');
        self::assertNotNull($idxTenant, 'TenantContextValidatorListener não encontrado.');

        self::assertGreaterThan(
            $idxFirewall,
            $idxTermo,
            'O gate de aceite precisa rodar DEPOIS do firewall, senão getUser() é null na request real.',
        );
        self::assertLessThan(
            $idxTenant,
            $idxTermo,
            'O gate de aceite precisa rodar ANTES do gate de tenant (aceite antes da seleção de escritório).',
        );
    }

    /** @param list<string> $classes */
    private function indicePorFragmento(array $classes, string $fragmento): ?int
    {
        foreach ($classes as $i => $classe) {
            if (str_contains($classe, $fragmento)) {
                return $i;
            }
        }

        return null;
    }
}
