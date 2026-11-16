<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Service\ModoDoConector;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O modo do conector e o escritório fixado pelo portão não vazam para o navegador (spec mcp-remoto-oauth 4.1 e 6.3,
 * item 2; 12.19, último item): ligados numa requisição, a requisição SEGUINTE do mesmo processo volta ao normal.
 *
 * `disableReboot()` é o que faz o teste provar algo (revisão de 16/11/2026, achado 6): sem ele o KernelBrowser monta um
 * container novo a cada requisição e o modo some por isso, não pelo `reset()`. Com ele, o mesmo container atende as
 * duas requisições e quem zera o modo é o `services_resetter` (tag `kernel.reset`), como no worker e no FPM com
 * reaproveitamento de processo.
 */
#[CoversClass(ModoDoConector::class)]
#[CoversClass(TenantContext::class)]
final class ModoDoConectorNoNavegadorTest extends JusPrimeWebTestCase
{
    use CenarioDoConectorTrait;

    #[TestDox('o primário com o modo ligado numa requisição tem o passe de volta na requisição seguinte do navegador')]
    public function testRequisicaoSeguinteTemOsPassesDeVolta(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $escritorio = $this->escritorio();
        $primario = $this->pessoa();
        // Perfil de sistema sem `admin.audit.view` ligada: só o passe do primário abre a Auditoria.
        $vinculo = $this->vinculo($primario, $escritorio, sistema: true);
        $this->logarComTenant($client, $primario, $escritorio);

        $client->request('GET', '/auditoria');
        self::assertResponseIsSuccessful('no navegador, o primário abre a Auditoria pelo passe');

        $modo = static::getContainer()->get(ModoDoConector::class);
        $modo->ligar($vinculo);
        self::assertFalse(
            static::getContainer()->get(PermissionChecker::class)->canAdminister($primario, $escritorio, 'admin.audit.view'),
            'o modo está ligado no MESMO container que vai atender a próxima requisição',
        );

        $client->request('GET', '/auditoria');

        self::assertResponseIsSuccessful('a requisição seguinte do navegador tem o passe de volta');
        self::assertFalse($modo->estaLigado());
    }

    #[TestDox('o escritório fixado pelo portão numa requisição não existe na requisição seguinte do mesmo processo')]
    public function testEscritorioFixadoZeraNaRequisicaoSeguinte(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio);
        $contexto = static::getContainer()->get(TenantContext::class);
        // Uma requisição antes, como no teste do modo: o Kernel só zera os serviços a partir da SEGUNDA requisição que
        // trata (antes da primeira não há o que zerar). Na vida real o portão roda dentro de uma requisição do `/mcp`.
        $client->request('GET', '/login');

        $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());
        self::assertTrue($contexto->hasCurrentTenant(), 'o portão fixou o escritório no MESMO container da próxima requisição');

        $client->request('GET', '/login');

        self::assertFalse($contexto->hasCurrentTenant());
        self::assertNull($contexto->getCurrentTenant());
        self::assertNull($contexto->getCurrentUserTenant());
        self::assertFalse($contexto->estaFixadoSemSessao());
    }
}
