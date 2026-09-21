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
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Twig\Environment;

/**
 * O PDF e o XLSX imprimem a MESMA batida e os MESMOS minutos que a folha da tela — e não anunciam
 * a marca "a conferir", que é só da tela (decisão do dono, `docs/specs/ponto-folha-uma-batida-por-tipo.md`
 * §9.9). O dia de teste tem dois repousos distintos: a conta usa o primeiro (09:00), 360 min.
 */
#[CoversClass(PontoController::class)]
final class FolhaExportacaoMesmaEscolhaTest extends JusPrimeWebTestCase
{
    #[TestDox('o XLSX traz o repouso e as horas que a folha conta, sem a marca a conferir')]
    public function testXlsxTrazAMesmaEscolhaDaFolha(): void
    {
        $client = static::createClient();
        [$tenant, $colaborador, $dia] = $this->cenario();

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', sprintf('/ponto/exportar-folha-xlsx?mes=%d&ano=%d', (int) $dia->format('n'), (int) $dia->format('Y')));

        self::assertResponseIsSuccessful();
        $arquivo = tempnam(sys_get_temp_dir(), 'folha_xlsx_');
        file_put_contents($arquivo, (string) $client->getInternalResponse()->getContent());
        $planilha = IOFactory::load($arquivo)->getActiveSheet();
        unlink($arquivo);

        $linhaDoDia = null;
        foreach ($planilha->getRowIterator() as $linha) {
            $numero = $linha->getRowIndex();
            if ((string) $planilha->getCell("A{$numero}")->getValue() === $dia->format('d')
                && (string) $planilha->getCell("C{$numero}")->getValue() !== '') {
                $linhaDoDia = $numero;
                break;
            }
        }
        self::assertNotNull($linhaDoDia, 'a linha do dia deveria estar na planilha');

        self::assertSame('09:00', $planilha->getCell("D{$linhaDoDia}")->getValue(), 'o repouso que a folha conta');
        self::assertSame('6:00', $planilha->getCell("G{$linhaDoDia}")->getValue(), 'as horas que a folha conta');

        $texto = '';
        foreach ($planilha->toArray() as $linha) {
            $texto .= implode(' ', array_map('strval', $linha)) . "\n";
        }
        self::assertStringNotContainsStringIgnoringCase('conferir', $texto, 'a planilha assinada não anuncia a marca');
    }

    #[TestDox('o PDF recebe o repouso e as horas que a folha conta, sem a marca a conferir')]
    public function testPdfRecebeAMesmaEscolhaDaFolha(): void
    {
        $client = static::createClient();
        [$tenant, $colaborador, $dia] = $this->cenario();

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', sprintf('/ponto/exportar-folha-pdf?mes=%d&ano=%d', (int) $dia->format('n'), (int) $dia->format('Y')));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        // O Dompdf comprime o texto; o que dá para ler é o HTML que ele recebe, montado pelo mesmo
        // `montarDadosFolha` da rota (o mesmo acesso por reflexão do HorasPagasTotalAssinadoTest).
        $container = static::getContainer();
        $builder = $container->get(FolhaPontoBuilder::class);
        $ano = (int) $dia->format('Y');
        $mes = (int) $dia->format('n');
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $rows = $builder->buildRows(
            $inicio,
            $inicio->modify('last day of this month'),
            $container->get(RegistroPontoRepository::class)->findByUserAndCompetencia($colaborador, $ano, $mes),
            true,
            false,
            $colaborador->getJornadaColaborador(),
            [],
            [],
            null,
            $inicio,
        );
        $controller = $container->get(PontoController::class);
        $dados = (new \ReflectionMethod($controller, 'montarDadosFolha'))
            ->invoke($controller, $colaborador, $tenant, $ano, $mes, $rows, [], $colaborador->getJornadaColaborador(), null, $builder, $inicio);

        $linha = null;
        foreach ($dados['folhaRows'] as $row) {
            if ($row['chaveDia'] === $dia->format('Y-m-d')) {
                $linha = $row;
            }
        }
        self::assertNotNull($linha);
        self::assertSame('09:00:00', $linha['repouso']);
        self::assertSame('6:00', $linha['horasTrabalhadas']);

        $html = $container->get(Environment::class)->render('ponto/folha_pdf.html.twig', $dados);
        self::assertStringContainsString('09:00', $html);
        self::assertStringNotContainsStringIgnoringCase('a conferir', $html, 'o PDF assinado não anuncia a marca');
    }

    /** @return array{0: Tenant, 1: User, 2: \DateTimeImmutable} */
    private function cenario(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant EXPORTACAO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        $colaborador = $this->criarColaboradorComum($tenant);
        $jornada = new JornadaColaborador();
        $jornada->setUser($colaborador);
        $jornada->setDiasSemana([]);
        $em->persist($jornada);
        $colaborador->setJornadaColaborador($jornada);

        $hoje = new \DateTimeImmutable('today');
        $dia = (int) $hoje->format('d') === 1 ? $hoje->modify('+1 day') : $hoje->modify('-1 day');
        foreach ([['entrada', '08:00:00'], ['repouso', '09:00:00'], ['repouso', '12:00:00'], ['retorno', '13:00:00'], ['saida', '18:00:00']] as [$tipo, $hora]) {
            $registro = new RegistroPonto();
            $registro->setUser($colaborador);
            $registro->setTenant($tenant);
            $registro->setTipo($tipo);
            $registro->setDataHora(new \DateTime($dia->format('Y-m-d') . ' ' . $hora));
            $registro->setSedeNomeSnapshot('Teste');
            $em->persist($registro);
        }
        $em->flush();

        return [$tenant, $colaborador, $dia];
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
        $user->setEmail('colab_exportacao_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Exportação');
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
