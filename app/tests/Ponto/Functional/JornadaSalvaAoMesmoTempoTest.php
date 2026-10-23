<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Controller\JornadaTenantController;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Dois salvamentos da mesma jornada ao mesmo tempo (revisão da spec seguranca-b-jornada): o segundo não acha a jornada
 * que o primeiro acabou de criar, tenta criar outra e bate no índice único. A resposta é 409 com a mensagem, não 500.
 * O teste simula a corrida com o repositório devolvendo "nenhuma jornada" quando ela já existe no banco.
 */
#[CoversClass(JornadaColaboradorController::class)]
#[CoversClass(JornadaTenantController::class)]
final class JornadaSalvaAoMesmoTempoTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const MANHA = [[[1, 2, 3, 4, 5], '08:00', null, null, '12:00']];

    private KernelBrowser $client;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->escritorio = $this->escritorio('Escritório');
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $this->escritorio, [], true);
        $this->logarComTenant($this->client, $admin, $this->escritorio);
    }

    #[TestDox('Jornada pessoal criada ao mesmo tempo por outro salvamento: 409 com a mensagem')]
    public function testJornadaPessoal(): void
    {
        $pessoa = $this->pessoa('Pessoa');
        $this->vincular($pessoa, $this->escritorio);
        $this->jornadaPessoal($pessoa, $this->escritorio, self::MANHA);
        static::getContainer()->set(JornadaColaboradorRepository::class, $this->createStub(JornadaColaboradorRepository::class));

        $url = sprintf('/tenant/%d/user/%d/jornada-colaborador', $this->escritorio->getId(), $pessoa->getId());
        $resposta = $this->chamarJson($this->client, 'POST', $url, $this->corpoDaJornada(self::MANHA));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => JornadaColaboradorController::JORNADA_ALTERADA_AO_MESMO_TEMPO], $resposta);
    }

    #[TestDox('Jornada padrão criada ao mesmo tempo por outro salvamento: 409 com a mensagem')]
    public function testJornadaPadrao(): void
    {
        $this->jornadaPadrao($this->escritorio, self::MANHA);
        static::getContainer()->set(JornadaTenantRepository::class, $this->createStub(JornadaTenantRepository::class));

        $resposta = $this->chamarJson($this->client, 'POST', '/tenant/' . $this->escritorio->getId() . '/jornada', $this->corpoDaJornada(self::MANHA) + [
            'validacaoRepousoHabilitada' => true,
            'minimoMinutosRepouso' => 60,
            'validacaoInterjornadaHabilitada' => true,
            'minimoMinutosInterjornada' => 660,
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => JornadaTenantController::JORNADA_ALTERADA_AO_MESMO_TEMPO], $resposta);
    }
}
