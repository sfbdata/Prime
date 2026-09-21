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
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\InicioContagemResolver;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Twig\Environment;

/**
 * O PDF e o XLSX imprimem a MESMA batida e os MESMOS minutos que a folha da tela — e não anunciam
 * a marca "a conferir", que é só da tela (decisão do dono, `docs/specs/ponto-folha-uma-batida-por-tipo.md`
 * §9.9). O dia de teste tem dois repousos distintos: a conta usa o primeiro (09:07), 367 min.
 *
 * Os horários são escolhidos para não aparecerem em nenhum outro lugar da folha (o horário contratual
 * padrão é 09:00, por exemplo): "09:07" no documento só pode ser o repouso escolhido, e "12:13", o que
 * ficou fora da conta.
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

        self::assertSame('09:07', $planilha->getCell("D{$linhaDoDia}")->getValue(), 'o repouso que a folha conta');
        self::assertSame('6:07', $planilha->getCell("G{$linhaDoDia}")->getValue(), 'as horas que a folha conta');
        self::assertSame('6:07', $planilha->getCell("H{$linhaDoDia}")->getValue(), 'horas extras do dia (meta 0: o dia inteiro)');
        self::assertSame('+6:07', $planilha->getCell("I{$linhaDoDia}")->getValue(), 'banco do dia');

        $texto = '';
        foreach ($planilha->toArray() as $linha) {
            $texto .= implode(' ', array_map('strval', $linha)) . "\n";
        }
        self::assertStringContainsString('Saldo do Banco de Horas Atual:  +6:07', $texto, 'saldo do banco no resumo assinado');
        self::assertStringNotContainsString('12:13', $texto, 'a batida fora da conta não entra na planilha');
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

        // O Dompdf comprime o texto e codifica por glifo; o que dá para ler é o HTML que ele recebe.
        // Ele é montado aqui com EXATAMENTE os passos de `PontoController::exportarFolhaPdf` (mesmos
        // repositórios, resolvedor do início da contagem, jornada do escritório, feriados e
        // justificativas) e o mesmo `montarDadosFolha` (por reflexão, como no HorasPagasTotalAssinadoTest).
        $container = static::getContainer();
        $builder = $container->get(FolhaPontoBuilder::class);
        $ano = (int) $dia->format('Y');
        $mes = (int) $dia->format('n');
        $jornada = $colaborador->getJornadaColaborador();
        $feriados = $container->get(FeriadoRepository::class)->findByTenant($tenant);
        $justificativas = $container->get(JustificativaPontoRepository::class)->findByUserAndCompetenciaIndexed($colaborador, $ano, $mes);
        $jornadaTenant = $tenant->getJornadaTenant();
        $inicioContagem = $container->get(InicioContagemResolver::class)->resolver($colaborador, $tenant);
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $rows = $builder->buildRows(
            $inicio,
            $inicio->modify('last day of this month')->setTime(23, 59, 59),
            $container->get(RegistroPontoRepository::class)->findByUserAndCompetencia($colaborador, $ano, $mes),
            true,
            false,
            $jornada,
            $feriados,
            $justificativas,
            $jornadaTenant,
            $inicioContagem,
        );
        $controller = $container->get(PontoController::class);
        $dados = (new \ReflectionMethod($controller, 'montarDadosFolha'))
            ->invoke($controller, $colaborador, $tenant, $ano, $mes, $rows, $feriados, $jornada, $jornadaTenant, $builder, $inicioContagem);

        $linha = null;
        foreach ($dados['folhaRows'] as $row) {
            if ($row['chaveDia'] === $dia->format('Y-m-d')) {
                $linha = $row;
            }
        }
        self::assertNotNull($linha);
        self::assertSame('09:07:00', $linha['repouso']);
        self::assertSame('6:07', $linha['horasTrabalhadas']);

        $html = $container->get(Environment::class)->render('ponto/folha_pdf.html.twig', $dados);
        self::assertStringContainsString('<td>09:07</td>', $html, 'o PDF imprime o repouso escolhido');
        self::assertStringNotContainsString('12:13', $html, 'e não imprime o que ficou fora da conta');
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

        // Sempre ONTEM, mesmo no dia 1º: a exportação é pedida pelo mês do próprio dia, e dia futuro
        // não é apurado — "amanhã" deixaria as horas em branco e o teste cairia um dia por mês.
        $dia = (new \DateTimeImmutable('today'))->modify('-1 day');
        foreach ([['entrada', '08:00:00'], ['repouso', '09:07:00'], ['repouso', '12:13:00'], ['retorno', '13:00:00'], ['saida', '18:00:00']] as [$tipo, $hora]) {
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
