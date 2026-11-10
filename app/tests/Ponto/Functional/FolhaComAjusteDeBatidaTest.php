<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\Service\DadosDaFolhaExportada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\FolhaPontoXlsxExporter;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\InicioContagemResolver;
use App\Ponto\Service\LinhasDaFolhaImpressa;
use App\Ponto\Service\TrilhaDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\DesenhoDaFolhaEspiao;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A folha conta a batida EFETIVA (E10, plano-e10-ponto §1, §4 e §7, bloco A): a desconsiderada sai da conta, a
 * incluída entra e a alterada vale pela hora nova, com a original guardada no banco. O XLSX imprime a hora ajustada
 * com a marca "aj" e, no mês com ajuste, a legenda dela nas observações (decisão 5). A folha em PDF (desenhada em
 * imagem na E16) imprime a hora efetiva sem marca e, nas observações do dia, "<Batida> ajustada em dd/mm/aaaa" de
 * cada batida ajustada (desenho L622; decisão 20 da E16), lida no conteúdo que chegou ao desenho
 * ({@see DesenhoDaFolhaEspiao}).
 *
 * O dia (sempre ONTEM, como no `FolhaExportacaoMesmaEscolhaTest`): entrada 08:04 (e uma 07:11 desconsiderada),
 * repouso 12:02, retorno 13:03 incluído pelo gestor e a saída 17:05 alterada para 18:37. Conta: 3:58 + 5:34 = 9:32
 * (572 min). O cenário de controle grava as mesmas quatro horas como batidas, sem ajuste nenhum: a mesma conta, sem
 * marca, sem legenda e sem "ajustada em". As horas não se repetem em outro lugar da folha (o contratual padrão é 09:00).
 */
#[CoversClass(PontoController::class)]
#[CoversClass(FolhaPontoBuilder::class)]
#[CoversClass(FolhaPontoXlsxExporter::class)]
#[CoversClass(DadosDaFolhaExportada::class)]
#[CoversClass(LinhasDaFolhaImpressa::class)]
final class FolhaComAjusteDeBatidaTest extends JusPrimeWebTestCase
{
    /** @var array{extra: RegistroPonto, saida: RegistroPonto, inclusao: PontoAjuste, alteracao: PontoAjuste}|null */
    private ?array $ajustes = null;

    #[TestDox('A linha da folha conta as efetivas: inclusão pelo alvo a{id}, alteração pela hora nova, desconsiderada fora')]
    public function testLinhaDaFolhaContaAsEfetivas(): void
    {
        static::createClient();
        [$tenant, $colaborador, $dia] = $this->cenario(true);
        $ajustes = $this->ajustes ?? throw new \LogicException('Cenário sem ajustes.');

        $linha = $this->linhaDoDia($this->dadosDaFolha($tenant, $colaborador, $dia), $dia);

        self::assertSame(['08:04:00', '12:02:00', '13:03:00', '18:37:00'], [$linha['entrada'], $linha['repouso'], $linha['retorno'], $linha['saida']]);
        self::assertSame('9:32', $linha['horasTrabalhadas']);
        self::assertSame(
            [null, 'a' . $ajustes['inclusao']->getId(), $ajustes['inclusao']->getId()],
            [$linha['retornoId'], $linha['retornoAlvo'], $linha['retornoCabeca']],
            'a inclusão não tem registro: vale pelo alvo a{id}',
        );
        self::assertSame(
            [$ajustes['saida']->getId(), 'b' . $ajustes['saida']->getId(), $ajustes['alteracao']->getId()],
            [$linha['saidaId'], $linha['saidaAlvo'], $linha['saidaCabeca']],
        );
        self::assertSame(['tipo' => 'inclusao', 'por' => 'Gestora do Ajuste'], array_intersect_key((array) $linha['retornoAjuste'], ['tipo' => 1, 'por' => 1]));
        self::assertSame('alteracao', ((array) $linha['saidaAjuste'])['tipo'] ?? null);
        self::assertSame([null, null], [$linha['entradaAjuste'], $linha['repousoAjuste']]);
        self::assertSame([], $linha['batidasDesconsideradas'], 'a 07:11 desconsiderada não é batida fora da conta: nem entra');

        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(
            ['07:11:00', '17:05:00'],
            array_map(
                static fn (mixed $hora): string => substr((string) $hora, 11),
                $conexao->fetchFirstColumn('SELECT data_hora FROM registro_ponto WHERE id IN (?, ?) ORDER BY data_hora', [$ajustes['extra']->getId(), $ajustes['saida']->getId()]),
            ),
            'as batidas originais continuam gravadas como foram batidas',
        );
    }

    #[TestDox('O banco de horas por lotes soma as efetivas do dia (572 min)')]
    public function testBancoDeHorasSomaAsEfetivas(): void
    {
        static::createClient();
        [$tenant, $colaborador] = $this->cenario(true);

        $banco = static::getContainer()->get(BancoDeHorasPorLotes::class)->calcular($colaborador, $tenant, new \DateTimeImmutable('today'));

        self::assertSame(572, $banco->saldoAtualMinutos);
    }

    #[TestDox('O XLSX imprime a hora ajustada com "aj", as horas das efetivas e a legenda nas observações')]
    public function testXlsxMarcaOAjusteEPoeALegenda(): void
    {
        $client = static::createClient();
        [$tenant, $colaborador, $dia] = $this->cenario(true);

        [$planilha, $linha] = $this->planilhaDoDia($client, $tenant, $colaborador, $dia);

        self::assertSame(
            ['08:04', '12:02', '13:03 aj', '18:37 aj', '9:32'],
            [$planilha->getCell("C{$linha}")->getValue(), $planilha->getCell("D{$linha}")->getValue(), $planilha->getCell("E{$linha}")->getValue(), $planilha->getCell("F{$linha}")->getValue(), $planilha->getCell("G{$linha}")->getValue()],
        );
        $texto = self::textoDa($planilha);
        self::assertStringContainsString('• ' . FolhaPontoXlsxExporter::LEGENDA_DO_AJUSTE, $texto);
        self::assertStringNotContainsString('07:11', $texto, 'a desconsiderada não sai na planilha');
        self::assertStringNotContainsString('17:05', $texto, 'a hora original da alterada não sai na planilha');
    }

    #[TestDox('O PDF imprime as horas efetivas sem marca e, nas observações do dia, "<Batida> ajustada em dd/mm/aaaa" de cada batida ajustada (decisão 20 da E16)')]
    public function testPdfMostraOAjusteNasObservacoesDoDia(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        [$tenant, $colaborador, $dia] = $this->cenario(true);
        $ajustes = $this->ajustes ?? throw new \LogicException('Cenário sem ajustes.');

        $linha = $this->linhaDoPdf($client, $desenho, $tenant, $colaborador, $dia);

        self::assertSame(['08:04', '12:02', '13:03', '18:37'], $linha->batidas, 'as efetivas: a inclusão e a hora nova da alterada, sem marca');
        self::assertSame('9:32', $linha->trabalhadas);
        // A entrada só teve a 07:11 desconsiderada: a efetiva (08:04) não foi ajustada. O feriado, se o dia tiver, vem depois.
        self::assertStringStartsWith(
            sprintf('Retorno ajustada em %s · Saída ajustada em %s', $ajustes['inclusao']->getCriadoEm()->format('d/m/Y'), $ajustes['alteracao']->getCriadoEm()->format('d/m/Y')),
            $linha->observacao,
        );
        self::assertStringNotContainsString('Entrada ajustada', $linha->observacao);
        self::assertStringNotContainsString('07:11', $desenho->textoDoUltimo(), 'a desconsiderada não sai na folha');
        self::assertStringNotContainsString('17:05', $desenho->textoDoUltimo(), 'a hora original da alterada não sai na folha');
    }

    #[TestDox('Sem ajuste no mês, a mesma conta sai sem "ajustada em" no PDF e sem a marca "aj" e a legenda no XLSX')]
    public function testSemAjusteNaoHaMarcaNemLegenda(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        [$tenant, $colaborador, $dia] = $this->cenario(false);

        self::assertSame('9:32', $this->linhaDoDia($this->dadosDaFolha($tenant, $colaborador, $dia), $dia)['horasTrabalhadas']);
        $linhaDoPdf = $this->linhaDoPdf($client, $desenho, $tenant, $colaborador, $dia);
        self::assertSame(['08:04', '12:02', '13:03', '18:37'], $linhaDoPdf->batidas);
        self::assertSame('9:32', $linhaDoPdf->trabalhadas);
        self::assertStringNotContainsString('ajustada em', $desenho->textoDoUltimo());

        [$planilha, $linha] = $this->planilhaDoDia($client, $tenant, $colaborador, $dia);
        self::assertSame(['13:03', '18:37', '9:32'], [$planilha->getCell("E{$linha}")->getValue(), $planilha->getCell("F{$linha}")->getValue(), $planilha->getCell("G{$linha}")->getValue()]);
        self::assertStringNotContainsString(FolhaPontoXlsxExporter::LEGENDA_DO_AJUSTE, self::textoDa($planilha));
    }

    /** A linha do dia na folha em PDF, pela rota: o que chegou ao desenho (a página é imagem, sem texto para ler). */
    private function linhaDoPdf(KernelBrowser $client, DesenhoDaFolhaEspiao $desenho, Tenant $tenant, User $colaborador, \DateTimeImmutable $dia): LinhaDaFolhaImpressa
    {
        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', sprintf('/ponto/exportar-folha-pdf?mes=%d&ano=%d', (int) $dia->format('n'), (int) $dia->format('Y')));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        foreach ($desenho->ultimo()->linhas as $linha) {
            if ($linha->data === $dia->format('Y-m-d')) {
                return $linha;
            }
        }

        self::fail('a linha do dia deveria estar na folha em PDF');
    }

    /** @return array{0: Worksheet, 1: int} a planilha exportada e o número da linha do dia */
    private function planilhaDoDia(KernelBrowser $client, Tenant $tenant, User $colaborador, \DateTimeImmutable $dia): array
    {
        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', sprintf('/ponto/exportar-folha-xlsx?mes=%d&ano=%d', (int) $dia->format('n'), (int) $dia->format('Y')));
        self::assertResponseIsSuccessful();
        $arquivo = tempnam(sys_get_temp_dir(), 'folha_ajuste_xlsx_');
        file_put_contents($arquivo, (string) $client->getInternalResponse()->getContent());
        $planilha = IOFactory::load($arquivo)->getActiveSheet();
        unlink($arquivo);

        foreach ($planilha->getRowIterator() as $linha) {
            $numero = $linha->getRowIndex();
            if ((string) $planilha->getCell("A{$numero}")->getValue() === $dia->format('d')
                && (string) $planilha->getCell("C{$numero}")->getValue() !== '') {
                return [$planilha, $numero];
            }
        }

        self::fail('a linha do dia deveria estar na planilha');
    }

    private static function textoDa(Worksheet $planilha): string
    {
        $texto = '';
        foreach ($planilha->toArray() as $linha) {
            $texto .= implode(' ', array_map('strval', $linha)) . "\n";
        }

        return $texto;
    }

    /**
     * Os dados da folha exportada: as linhas do `FolhaPontoBuilder` do mês e o mesmo `DadosDaFolhaExportada::montar`
     * da rota do XLSX, como no `AlertasAntesDaBatidaControllerTest`.
     *
     * @return array<string, mixed>
     */
    private function dadosDaFolha(Tenant $tenant, User $colaborador, \DateTimeImmutable $dia): array
    {
        $container = static::getContainer();
        $builder = $container->get(FolhaPontoBuilder::class);
        $ano = (int) $dia->format('Y');
        $mes = (int) $dia->format('n');
        $jornada = $container->get(JornadaColaboradorRepository::class)->findByUserETenant($colaborador, $tenant);
        $feriados = $container->get(FeriadoRepository::class)->findByTenant($tenant);
        $justificativas = $container->get(JustificativaPontoRepository::class)->findByUserAndCompetenciaIndexed($colaborador, $tenant, $ano, $mes);
        $jornadaTenant = $tenant->getJornadaTenant();
        $inicioContagem = $container->get(InicioContagemResolver::class)->resolver($colaborador, $tenant);
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $rows = $builder->buildRows(
            $inicio,
            $inicio->modify('last day of this month')->setTime(23, 59, 59),
            $container->get(RegistroPontoRepository::class)->findByUserAndCompetencia($colaborador, $tenant, $ano, $mes),
            true,
            false,
            $jornada,
            $feriados,
            $justificativas,
            $jornadaTenant,
            $inicioContagem,
        );

        return $container->get(DadosDaFolhaExportada::class)
            ->montar($colaborador, $tenant, $ano, $mes, $rows, $feriados, $jornada, $jornadaTenant, $inicioContagem);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function linhaDoDia(array $dados, \DateTimeImmutable $dia): array
    {
        foreach ($dados['folhaRows'] as $row) {
            if ($row['chaveDia'] === $dia->format('Y-m-d')) {
                return $row;
            }
        }

        self::fail('a linha do dia deveria estar na folha');
    }

    /**
     * O dia de ontem. Com ajuste: as batidas 07:11, 08:04, 12:02 e 17:05, a 07:11 desconsiderada, o retorno 13:03
     * incluído e a saída alterada para 18:37 (pela `GravacaoDoAjusteDoPonto`, dentro da transação). Sem ajuste: as
     * batidas 08:04, 12:02, 13:03 e 18:37.
     *
     * @return array{0: Tenant, 1: User, 2: \DateTimeImmutable}
     */
    private function cenario(bool $comAjuste): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant AJUSTE NA FOLHA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        $colaborador = $this->criarColaboradorComum($tenant);
        $jornada = new JornadaColaborador();
        $jornada->setUser($colaborador);
        $jornada->setTenant($tenant);
        $jornada->setDiasSemana([]);
        $jornada->setCobreDiasAnteriores(true);
        $em->persist($jornada);
        $em->flush();

        // Sempre ONTEM, mesmo no dia 1º (dia futuro não é apurado), como no FolhaExportacaoMesmaEscolhaTest.
        $dia = (new \DateTimeImmutable('today'))->modify('-1 day');
        $horas = $comAjuste
            ? ['extra' => ['entrada', '07:11'], 'entrada' => ['entrada', '08:04'], 'repouso' => ['repouso', '12:02'], 'saida' => ['saida', '17:05']]
            : ['entrada' => ['entrada', '08:04'], 'repouso' => ['repouso', '12:02'], 'retorno' => ['retorno', '13:03'], 'saida' => ['saida', '18:37']];
        $batidas = [];
        foreach ($horas as $chave => [$tipo, $hora]) {
            $batidas[$chave] = (new RegistroPonto())->setUser($colaborador)->setTenant($tenant)->setTipo($tipo)
                ->setDataHora(new \DateTime($dia->format('Y-m-d') . ' ' . $hora . ':00'))->setSedeNomeSnapshot('Teste');
            $em->persist($batidas[$chave]);
        }
        $em->flush();

        if ($comAjuste) {
            $this->ajustar($tenant, $colaborador, $dia, $batidas['extra'], $batidas['saida']);
        }

        return [$tenant, $colaborador, $dia];
    }

    private function ajustar(Tenant $tenant, User $colaborador, \DateTimeImmutable $dia, RegistroPonto $extra, RegistroPonto $saida): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $gestora = (new User())->setEmail('gestora_ajuste_' . uniqid() . '@test.com')->setFullName('Gestora do Ajuste');
        $gestora->setRoles(['ROLE_USER']);
        $gestora->setPassword('x');
        $em->persist($gestora);
        $em->flush();

        $trilha = $container->get(TrilhaDoPonto::class);
        $gravacao = new GravacaoDoAjusteDoPonto($em, $trilha, $container->get(HorarioFuturoDaBatida::class), $container->get(ClockInterface::class));
        $registros = $container->get(RegistroPontoRepository::class);
        $alvo = static fn (string $alvo) => $registros->alvoDoAjuste($colaborador, $tenant, $alvo) ?? throw new \LogicException('Alvo não achado: ' . $alvo);
        $quando = static fn (string $hora): \DateTimeImmutable => new \DateTimeImmutable($dia->format('Y-m-d') . ' ' . $hora . ':00');

        $trilha->emTransacao(static fn (): PontoAjuste => $gravacao->desconsiderar($alvo('b' . $extra->getId()), 'Entrada repetida.', $gestora, null, null));
        $inclusao = $trilha->emTransacao(static fn (): PontoAjuste => $gravacao->incluir($tenant, $colaborador, 'retorno', $quando('13:03'), 'Esqueceu o retorno.', $gestora, null));
        $alteracao = $trilha->emTransacao(static fn (): PontoAjuste => $gravacao->alterar($alvo('b' . $saida->getId()), 'saida', $quando('18:37'), 'Saiu mais tarde.', $gestora));

        $this->ajustes = ['extra' => $extra, 'saida' => $saida, 'inclusao' => $inclusao, 'alteracao' => $alteracao];
    }

    private function criarColaboradorComum(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $codigoPermissao = 'modules.ponto.view';
        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => $codigoPermissao]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigoPermissao);
            $permissao->setDescription('Permissão de teste ' . $codigoPermissao);
            $permissao->setGroup(explode('.', $codigoPermissao)[0]);
            $em->persist($permissao);
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($role);
        $vinculo->setPermission($permissao);
        $em->persist($vinculo);
        $role->getTenantRolePermissions()->add($vinculo);

        $user = new User();
        $user->setEmail('colab_ajuste_folha_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador do Ajuste');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }
}
