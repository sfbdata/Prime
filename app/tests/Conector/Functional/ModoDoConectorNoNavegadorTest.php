<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Service\ModoDoConector;
use App\Service\PermissionChecker;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O modo do conector não vaza para o navegador (spec mcp-remoto-oauth 6.3, item 2; 12.19, último item): ligado numa
 * requisição, a requisição SEGUINTE do mesmo processo volta a ter os passes.
 *
 * `disableReboot()` é o que faz o teste provar algo (revisão de 16/11/2026, achado 6): sem ele o KernelBrowser monta um
 * container novo a cada requisição e o modo some por isso, não pelo `reset()`. Com ele, o mesmo container atende as
 * duas requisições e quem zera o modo é o `services_resetter` (tag `kernel.reset`), como no worker e no FPM com
 * reaproveitamento de processo.
 */
#[CoversClass(ModoDoConector::class)]
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
}
