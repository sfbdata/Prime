<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\EquipeDoPontoController;
use App\Ponto\UseCase\ConsultarEquipeDoPontoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Custo do painel da aba "Equipe" (spec `docs/specs/ponto-desenho-0811.md`, E5, "Custo do painel"): o número de
 * consultas por pessoa da equipe. Trava o teto medido em 09/11/2026 para o painel não ficar mais caro sem ninguém ver.
 *
 * A pessoa do teste é o caso caro: jornada pessoal e batida desde junho do ano passado, então o banco
 * (`BancoDeHorasPorLotes`, E17: desde o início da contagem, sem zerar em janeiro) percorre todos os meses desde
 * junho do ano passado até hoje. O banco lê os meses todos de uma vez: o teto por pessoa não depende da data em que
 * o teste roda.
 */
#[CoversClass(EquipeDoPontoController::class)]
#[CoversClass(ConsultarEquipeDoPontoUseCase::class)]
#[Group('ponto')]
final class EquipeDoPontoConsultasTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    /** 40h: segunda a sexta 8h. */
    private const QUARENTA = [[[1, 2, 3, 4, 5], '08:00', '12:00', '13:00', '17:00']];

    /**
     * Consultas por pessoa. Medido em 09/11/2026: 31 com o banco do ano (9 fixas mais 2 por mês); 12 com o banco por
     * lotes (E17), que lê as batidas e as justificativas de todos os meses de uma vez e não cresce com o número de
     * meses. Subir este número pede rever antes a seção "Custo do painel" da E5 na spec.
     */
    private const TETO_POR_PESSOA = 12;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[TestDox('O painel da equipe não passa do teto de consultas por pessoa (1 pessoa contra 3)')]
    public function testConsultasPorPessoa(): void
    {
        // Dois escritórios iguais, um com 1 pessoa e outro com 3: a diferença, dividida por 2, é o custo de uma pessoa.
        [$escritorioDeUma, $gestoraDeUma] = $this->escritorioComEquipe(['Ana Lima']);
        [$escritorioDeTres, $gestoraDeTres] = $this->escritorioComEquipe(['Ana Lima', 'Bruno Reis', 'Carla Dias']);

        $comUma = $this->contarConsultas($gestoraDeUma, $escritorioDeUma, 1);
        $comTres = $this->contarConsultas($gestoraDeTres, $escritorioDeTres, 3);

        $porPessoa = intdiv($comTres - $comUma, 2);
        self::assertLessThanOrEqual(
            self::TETO_POR_PESSOA,
            $porPessoa,
            sprintf(
                'O painel da Equipe passou a fazer %d consultas por pessoa (teto: %d). Antes de subir o teto, reveja a '
                . 'seção "Custo do painel" da E5 em docs/specs/ponto-desenho-0811.md.',
                $porPessoa,
                self::TETO_POR_PESSOA,
            ),
        );
    }

    /**
     * Um escritório com a jornada padrão, uma gestora que não registra ponto (a equipe do painel é só quem o teste põe)
     * e as pessoas no caso caro: jornada pessoal, batida no ano passado (o banco atravessa a virada do ano), um dia batido há
     * 3 dias (as pendências dos últimos 30 dias leem os feriados e os esquecimentos, em vez de sair cedo) e o dia de
     * hoje aberto. A batida antiga é de junho do ano passado: em qualquer data, fica antes de 1º de janeiro.
     *
     * @param list<string> $nomes
     *
     * @return array{Tenant, User}
     */
    private function escritorioComEquipe(array $nomes): array
    {
        $escritorio = $this->escritorio('Escritório das consultas');
        $this->jornadaPadrao($escritorio, self::QUARENTA);
        $gestora = $this->pessoa('Gestora');
        $this->vincular($gestora, $escritorio, [], true, false);
        $diaDoAnoPassado = new \DateTimeImmutable(sprintf('%d-06-15', (int) date('Y') - 1));
        foreach ($nomes as $nome) {
            $pessoa = $this->pessoa($nome);
            $this->vincular($pessoa, $escritorio);
            $this->jornadaPessoal($pessoa, $escritorio, self::QUARENTA);
            $this->registrarBatida($pessoa, $escritorio, 'entrada', $diaDoAnoPassado->setTime(8, 0));
            $this->registrarBatida($pessoa, $escritorio, 'saida', $diaDoAnoPassado->setTime(17, 0));
            $this->registrarBatida($pessoa, $escritorio, 'entrada', new \DateTimeImmutable('-3 days 08:00'));
            $this->registrarBatida($pessoa, $escritorio, 'saida', new \DateTimeImmutable('-3 days 17:00'));
            $this->registrarBatida($pessoa, $escritorio, 'entrada', new \DateTimeImmutable('today 08:00'));
        }

        return [$escritorio, $gestora];
    }

    /** Consultas do painel, depois de uma chamada de aquecimento (a primeira guarda na sessão o que é de uma vez só). */
    private function contarConsultas(User $gestora, Tenant $escritorio, int $pessoas): int
    {
        $this->logarComTenant($this->client, $gestora, $escritorio);
        $this->client->request('GET', '/ponto/equipe');
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $this->client->enableProfiler();
        $painel = $this->client->request('GET', '/ponto/equipe');
        self::assertResponseIsSuccessful();
        self::assertCount($pessoas, $painel->filter('tr.pe-eq-lin'));

        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil);
        $db = $perfil->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $db);

        return $db->getQueryCount();
    }
}
