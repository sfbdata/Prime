<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Quem exporta a folha de outra pessoa em XLSX pela rota `ponto_exportar_xlsx` (spec ponto-desenho-0811, E16, P88 e
 * P89; decisão 25): a mesma regra do PDF. A pessoa sem vínculo ativo neste escritório responde 404, como o `userId` que
 * não existe (recurso de outro escritório é 404, nunca 403); a pessoa daqui só sai para quem tem `admin.users.manage`
 * (sem a permissão, 403); o Master exporta a de qualquer um, como antes. A competência fora do calendário responde 400,
 * como no PDF.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
final class FolhaXlsxDeOutraPessoaTest extends JusPrimeWebTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    private const OUTUBRO = '/ponto/exportar-folha-xlsx?mes=10&ano=2026';
    private const PLANILHA = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $maria;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        $this->escritorio = $this->escritorio('Escritório da folha em XLSX');
        $this->maria = $this->pessoaComOutubro($this->escritorio, 'Maria da Silva');
    }

    /**
     * @param 'gestora'|'colaborador'|'master'           $quem
     * @param 'daqui'|'de outro escritório'|'que não existe' $alvo
     */
    #[DataProvider('pedidos')]
    #[TestDox('$_dataName')]
    public function testQuemExportaAFolhaDeOutraPessoa(string $quem, string $alvo, int $status): void
    {
        $alvoId = match ($alvo) {
            'daqui'               => (int) $this->pessoaComOutubro($this->escritorio, 'João Souza')->getId(),
            'de outro escritório' => (int) $this->pessoaComOutubro($this->escritorio('Outro escritório'), 'João Souza')->getId(),
            'que não existe'      => 999999999,
        };

        $this->pedir($this->quem($quem), self::OUTUBRO . '&userId=' . $alvoId);

        self::assertResponseStatusCodeSame($status);
        if ($status === 200) {
            self::assertResponseHeaderSame('Content-Type', self::PLANILHA);
        }
    }

    /** @return iterable<string, array{'gestora'|'colaborador'|'master', 'daqui'|'de outro escritório'|'que não existe', int}> */
    public static function pedidos(): iterable
    {
        yield 'Gestor com admin.users.manage, pessoa de outro escritório: 404' => ['gestora', 'de outro escritório', 404];
        yield 'Gestor com admin.users.manage, userId que não existe: 404' => ['gestora', 'que não existe', 404];
        yield 'Colaborador sem a permissão, pessoa de outro escritório: 404 (nunca 403)' => ['colaborador', 'de outro escritório', 404];
        yield 'Colaborador sem a permissão, pessoa deste escritório: 403' => ['colaborador', 'daqui', 403];
        yield 'Gestor com admin.users.manage, pessoa deste escritório: 200' => ['gestora', 'daqui', 200];
        yield 'Master (ROLE_SUPER_ADMIN), pessoa de outro escritório: 200, como antes' => ['master', 'de outro escritório', 200];
    }

    #[TestDox('Competência fora do calendário (ano com cinco dígitos): 400 "Competência inválida.", como o PDF, nunca o erro do servidor')]
    public function testCompetenciaInvalida(): void
    {
        $this->pedir($this->maria, '/ponto/exportar-folha-xlsx?mes=10&ano=10000');

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Competência inválida.', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertResponseHeaderNotSame('Content-Type', self::PLANILHA);
    }

    /** @param 'gestora'|'colaborador'|'master' $quem */
    private function quem(string $quem): User
    {
        if ($quem === 'colaborador') {
            return $this->maria;
        }
        $pessoa = $quem === 'master' ? $this->pessoa('Master do BlueJus', ['ROLE_SUPER_ADMIN']) : $this->pessoa('Gestora do Ponto');
        $this->vincular($pessoa, $this->escritorio, ['modules.ponto.view', 'admin.users.manage']);

        return $pessoa;
    }

    /** Entra como a pessoa no escritório e pede o XLSX, com o EntityManager limpo (como uma requisição de produção). */
    private function pedir(User $quem, string $endereco): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->em()->clear();
        $this->client->request('GET', $endereco);
    }
}
