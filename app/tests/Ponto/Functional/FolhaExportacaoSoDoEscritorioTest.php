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
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * O espelho exportado (PDF e XLSX) de quem bate ponto em dois escritórios traz só as batidas do escritório da sessão
 * (spec `ponto-escritorio-explicito-na-folha.md`, auditoria 1.13). Dois cenários: o de hoje, com o filtro `tenant`
 * ligado no escritório da sessão, e o do filtro inerte (desligado antes do controller, como num chamador fora de
 * requisição): nos dois, o escritório explícito das consultas da folha é o que segura as batidas do outro.
 *
 * Os horários de B (19:43 e 21:58) não aparecem em nenhum outro lugar do espelho: achá-los no documento só pode ser
 * a batida do outro escritório.
 */
#[CoversClass(PontoController::class)]
final class FolhaExportacaoSoDoEscritorioTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function exportacoes(): iterable
    {
        yield 'XLSX, filtro do escritório ligado' => ['xlsx', true];
        yield 'XLSX, filtro desligado' => ['xlsx', false];
        yield 'PDF, filtro do escritório ligado' => ['pdf', true];
        yield 'PDF, filtro desligado' => ['pdf', false];
    }

    #[DataProvider('exportacoes')]
    #[TestDox('O espelho exportado traz só as batidas do escritório da sessão: $_dataName')]
    public function testEspelhoSoDoEscritorioDaSessao(string $formato, bool $filtroLigado): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$a, $b] = [$this->tenant('A'), $this->tenant('B')];
        $colaborador = $this->colaborador([$a, $b]);

        $jornada = new JornadaColaborador();
        $jornada->setUser($colaborador);
        $jornada->setTenant($a);
        $jornada->setDiasSemana([]);
        $em->persist($jornada);
        $this->batida($colaborador, $a, RegistroPonto::TIPO_ENTRADA, '2026-03-10 08:01:00');
        $this->batida($colaborador, $a, RegistroPonto::TIPO_SAIDA, '2026-03-10 11:02:00');
        $this->batida($colaborador, $b, RegistroPonto::TIPO_ENTRADA, '2026-03-11 19:43:00');
        $this->batida($colaborador, $b, RegistroPonto::TIPO_SAIDA, '2026-03-11 21:58:00');
        $em->clear();

        $desligou = false;
        if (!$filtroLigado) {
            // O filtro sai depois do TenantFilterListener (kernel.request) e antes do controller.
            static::getContainer()->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER, static function () use ($em, &$desligou): void {
                if ($em->getFilters()->isEnabled('tenant')) {
                    $em->getFilters()->disable('tenant');
                    $desligou = true;
                }
            });
        }

        $this->logarComTenant($client, $colaborador, $a);
        $client->request('GET', sprintf('/ponto/exportar-folha-%s?mes=3&ano=2026', $formato));
        self::assertResponseIsSuccessful();
        self::assertSame(!$filtroLigado, $desligou, 'o cenário do filtro desligado precisa chegar ao controller com o filtro desligado');

        $conteudo = (string) $client->getInternalResponse()->getContent();
        $texto = $formato === 'xlsx' ? self::textoDaPlanilha($conteudo) : self::textoDoPdf($conteudo);

        self::assertStringContainsString('08:01', $texto, 'a entrada do escritório da sessão está no espelho');
        self::assertStringContainsString('11:02', $texto, 'a saída do escritório da sessão está no espelho');
        self::assertStringNotContainsString('19:43', $texto, 'a entrada do outro escritório vazou para o espelho');
        self::assertStringNotContainsString('21:58', $texto, 'a saída do outro escritório vazou para o espelho');
    }

    private static function textoDaPlanilha(string $conteudo): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'folha_escritorio_xlsx_');
        file_put_contents($arquivo, $conteudo);
        $planilha = IOFactory::load($arquivo)->getActiveSheet();
        unlink($arquivo);

        $texto = '';
        foreach ($planilha->toArray() as $linha) {
            $texto .= implode(' ', array_map('strval', $linha)) . "\n";
        }

        return $texto;
    }

    /**
     * O Dompdf grava o texto em fluxos comprimidos (FlateDecode). Com a fonte do espelho (Arial, que vira a Helvetica
     * padrão do PDF) o texto descomprimido sai legível, em literais como `[(08:01)] TJ`.
     */
    private static function textoDoPdf(string $conteudo): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $conteudo, $fluxos);
        $texto = '';
        foreach ($fluxos[1] as $fluxo) {
            $descomprimido = @gzuncompress($fluxo);
            $texto .= $descomprimido === false ? '' : $descomprimido;
        }
        self::assertNotSame('', $texto, 'o PDF deveria ter fluxos de texto legíveis');

        return $texto;
    }

    private function tenant(string $nome): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant espelho ' . $nome . ' ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Colaborador comum (só `modules.ponto.view`) com vínculo ativo em cada escritório.
     *
     * @param list<Tenant> $tenants
     */
    private function colaborador(array $tenants): User
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

        $user = new User();
        $user->setEmail('colab_espelho_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Dois Escritorios');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        foreach ($tenants as $tenant) {
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

            $userTenant = new UserTenant($user, $tenant);
            $userTenant->setTenantRole($role);
            $em->persist($userTenant);
        }
        $em->flush();

        return $user;
    }

    private function batida(User $user, Tenant $tenant, string $tipo, string $dataHora): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setSedeNomeSnapshot('Teste');
        $em->persist($registro);
        $em->flush();
    }
}
