<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O NSR (E15, D2) é só da marcação que a própria pessoa faz na /ponto. A batida lançada pelo gestor na ficha e a do
 * abono de esquecimento lançado por ele ficam sem NSR (viram ajuste na E10). E o mesmo envio repetido (E13) não gasta
 * número: nem a nova tentativa que a consulta barra, nem a que perde a corrida para o índice único.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class NsrSoNaMarcacaoDaPessoaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const ID_ENVIO = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório NSR');
        $this->pessoa = $this->pessoa('nsr');
        $this->vincularColaborador($this->pessoa, $this->escritorio);
    }

    #[TestDox('A batida que o gestor lança na ficha da pessoa fica sem NSR e não mexe no contador')]
    public function testLancamentoDoGestorSemNsr(): void
    {
        $this->logarComoGestor();
        $ontem = new \DateTimeImmutable('yesterday');

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/add', $this->escritorio->getId(), $this->pessoa->getId()), [
            'registro_ponto_manual' => ['data' => $ontem->format('Y-m-d'), 'hora' => '08:15:00', 'tipo' => 'entrada'],
            'competencia'           => $ontem->format('Y-m'),
            '_token'                => 'TOKEN_ponto_manual_add',
        ]);

        self::assertResponseRedirects();
        $batida = $this->unicaBatida();
        self::assertSame(RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL, $batida->getSedeNomeSnapshot(), 'é a batida do lançamento');
        self::assertNull($batida->getNsr());
        self::assertFalse($this->ultimoNsr(), 'o contador nem nasceu');
    }

    #[TestDox('A batida do abono de esquecimento lançado pelo gestor fica sem NSR e não mexe no contador')]
    public function testAbonoDeEsquecimentoDoGestorSemNsr(): void
    {
        $this->logarComoGestor();

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->escritorio->getId(), $this->pessoa->getId()), [
            '_token'                => 'TOKEN_admin_nova_justificativa_' . $this->pessoa->getId(),
            'datas'                 => (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
            'tipo'                  => 'esquecimento_registro',
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => '18:00',
        ]);

        self::assertResponseRedirects();
        $batida = $this->unicaBatida();
        self::assertSame(SaidaDeEsquecimento::OBSERVACAO_LANCAMENTO_DO_ADMIN, $batida->getObservacao(), 'é a batida do abono');
        self::assertNull($batida->getNsr());
        self::assertFalse($this->ultimoNsr(), 'o contador nem nasceu');
    }

    #[TestDox('O mesmo envio mandado de novo: "já registrada", uma batida com o NSR 1, e o contador continua em 1')]
    public function testEnvioRepetidoNaoGastaNsr(): void
    {
        $this->homeOffice();

        $primeira = $this->bater();
        $segunda = $this->bater();

        self::assertArrayNotHasKey('jaRegistrada', $primeira['data']);
        self::assertTrue($segunda['data']['jaRegistrada']);
        self::assertSame(1, $this->unicaBatida()->getNsr());
        self::assertSame(1, $this->ultimoNsr());
    }

    #[TestDox('O envio que perde a corrida para o índice único: "já registrada", nada gravado aqui e o NSR reservado volta no rollback')]
    public function testEnvioQuePerdeACorridaNaoGastaNsr(): void
    {
        $this->homeOffice();
        // O envio já entrou em outro escritório da pessoa: a consulta daqui não o vê, como o pedido que perdeu a corrida.
        $outro = $this->escritorio('Outro escritório');
        $jaEntrou = new RegistroPonto();
        $jaEntrou->setUser($this->pessoa);
        $jaEntrou->setTenant($outro);
        $jaEntrou->setTipo(RegistroPonto::TIPO_ENTRADA);
        $jaEntrou->setDataHora(new \DateTime('-2 hours'));
        $jaEntrou->setIdEnvio(self::ID_ENVIO);
        $this->em()->persist($jaEntrou);
        $this->em()->flush();

        $resposta = $this->bater();

        self::assertTrue($resposta['data']['jaRegistrada']);
        $conexao = $this->em()->getConnection();
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE tenant_id = ?', [$this->escritorio->getId()]), 'nada entrou aqui');
        self::assertFalse($this->ultimoNsr(), 'o contador que nasceu na transação saiu com o rollback');
    }

    private function logarComoGestor(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $this->logarComTenant($this->client, $gestor, $this->escritorio);
    }

    /** @return array<string, mixed> */
    private function bater(): array
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $this->client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], (string) json_encode(['tipo' => 'entrada', 'idEnvio' => self::ID_ENVIO]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function unicaBatida(): RegistroPonto
    {
        $batidas = $this->emSemFiltro()->getRepository(RegistroPonto::class)->findBy(['user' => $this->pessoa->getId(), 'tenant' => $this->escritorio->getId()]);
        self::assertCount(1, $batidas);

        return $batidas[0];
    }

    private function ultimoNsr(): int|false
    {
        $ultimo = $this->em()->getConnection()->fetchOne('SELECT ultimo FROM ponto_contador_nsr WHERE tenant_id = ?', [$this->escritorio->getId()]);

        return $ultimo === false ? false : (int) $ultimo;
    }

    private function homeOffice(): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->pessoa);
        $config->setTenant($this->escritorio);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }
}
