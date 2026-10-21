<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Integration;

use App\Agenda\Entity\AjusteDeData;
use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Service\DataParaOGoogle;
use App\Agenda\Service\DatasDaAgendaParaOGoogle;
use App\Agenda\Service\LevarDatasAoGoogle;
use App\Agenda\UseCase\SincronizarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Entity\UserProfile;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Datas do sistema na agenda do escritório no Google (spec agenda-google §9), de ponta a ponta contra o Google falso:
 * tudo o que a Agenda mostra sem ninguém cadastrar, com os ajustes do escritório, dia inteiro e sem ocupar horário.
 */
#[CoversClass(LevarDatasAoGoogle::class)]
#[CoversClass(DatasDaAgendaParaOGoogle::class)]
#[CoversClass(DataParaOGoogle::class)]
final class SincronizacaoDeDatasComGoogleTest extends KernelTestCase
{
    private GoogleFalso $google;

    protected function setUp(): void
    {
        $this->google = new GoogleFalso();
        static::getContainer()->set(GoogleAgendaApi::class, $this->google->api());
    }

    #[TestDox('Vai tudo: feriado nacional, recesso, comemorativa e o aniversário da equipe, como dia inteiro sem ocupar horário, só leitura; nunca vira compromisso')]
    public function testVaiTudo(): void
    {
        [$tenant, $eu, $conexao] = $this->cenario();
        $natal = $this->proximo('12-25');
        $this->aniversario($eu, $this->proximo('03-15'));

        $this->sincronizar($conexao);

        $porTitulo = $this->porTitulo($conexao);
        self::assertArrayHasKey('Natal', $porTitulo);
        self::assertSame(['date' => $natal], $porTitulo['Natal']['start']);
        self::assertSame('transparent', $porTitulo['Natal']['transparency']);
        self::assertStringContainsString('Para mudar, ajuste na Agenda do BlueJus', $porTitulo['Natal']['description']);
        self::assertNotEmpty(array_filter(array_keys($porTitulo), static fn (string $t): bool => str_contains($t, 'Recesso')), 'o recesso vai');
        self::assertNotEmpty(array_filter(array_keys($porTitulo), static fn (string $t): bool => str_starts_with($t, '🎂 Aniversário de')), 'o aniversário da equipe vai');
        self::assertGreaterThan(20, count($porTitulo), 'comemorativas, tribunais e eleições vão também');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id = :t', ['t' => $tenant->getId()]));
        self::assertSame(0, $this->sincronizar($conexao)['enviados'], 'sem mudança, nada vai de novo (eco)');
    }

    #[TestDox('Ajustes do escritório: a oculta sai do Google; a renomeada vai com o nome novo')]
    public function testAjustes(): void
    {
        [$tenant, $eu, $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $chave = 'fer-' . $this->proximo('12-25') . '-natal';
        $chaveTiradentes = 'fer-' . $this->proximo('04-21') . '-tiradentes';
        $this->em()->persist(AjusteDeData::ocultar($this->em()->find(Tenant::class, $tenant->getId()), $chave, $this->em()->find(User::class, $eu->getId())));
        $this->em()->persist(AjusteDeData::renomear($this->em()->find(Tenant::class, $tenant->getId()), $chaveTiradentes, 'Tiradentes (expediente normal)', $this->em()->find(User::class, $eu->getId())));
        $this->em()->flush();

        $this->sincronizar($conexao);

        $porTitulo = $this->porTitulo($conexao);
        self::assertArrayNotHasKey('Natal', $porTitulo);
        self::assertArrayHasKey('Tiradentes (expediente normal)', $porTitulo);
        self::assertArrayNotHasKey('Tiradentes', $porTitulo);
    }

    #[TestDox('Só leitura: mexida no Google volta como aqui; apagada lá fica apagada até mudar aqui')]
    public function testSoLeitura(): void
    {
        [$tenant, , $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $agenda = $this->agendaDe($conexao);
        $id = (new DataParaOGoogle())->idPara((int) $tenant->getId(), 'fer-' . $this->proximo('12-25') . '-natal');

        $this->google->paraTrazer[$agenda] = [['summary' => 'Mexi aqui'] + $this->google->eventos[$agenda][$id]];
        $this->sincronizar($conexao);
        $this->sincronizar($conexao);
        self::assertSame('Natal', $this->google->eventos[$agenda][$id]['summary']);

        unset($this->google->eventos[$agenda][$id]);
        $this->google->paraTrazer[$agenda] = [['id' => $id, 'status' => 'cancelled']];
        $this->sincronizar($conexao);
        self::assertSame(0, $this->sincronizar($conexao)['enviados'], 'apagada lá, não volta sem mudança');
        self::assertArrayNotHasKey($id, $this->google->eventos[$agenda]);
    }

    #[TestDox('A agenda do escritório apagada no Google é recriada e as datas vão de novo')]
    public function testAgendaNova(): void
    {
        [, , $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $antes = count($this->google->eventos[$this->agendaDe($conexao)]);
        $this->google->apagarAgendaNoGoogle($this->agendaDe($conexao));

        $this->sincronizar($conexao);
        $this->sincronizar($conexao);

        self::assertCount($antes, $this->google->eventos[$this->agendaDe($conexao)]);
    }

    /**
     * Só a parte da lotação: o código soma a UF do escritório à da lotação (como a grade), e a spec §9 diz "lotação
     * dela; senão o estado do escritório". A divergência está com o orquestrador; o feriado de SP vai hoje.
     */
    #[TestDox('UF da lotação da pessoa: o feriado estadual da lotação vai, mesmo com o escritório em outra UF')]
    public function testUfDaLotacao(): void
    {
        [$tenant, $eu, $conexao] = $this->cenario();
        $escritorio = $this->em()->find(Tenant::class, $tenant->getId())->setEstado('SP');
        $lotacao = (new Lotacao())->setNome('Porto Alegre/RS')->setTenant($escritorio);
        $this->em()->persist($lotacao);
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $eu->getId(), 'tenant' => $tenant->getId()])?->setLotacao($lotacao);
        $this->em()->flush();

        $this->sincronizar($conexao);

        $titulos = array_keys($this->porTitulo($conexao));
        $doRs = array_filter($titulos, static fn (string $t): bool => str_starts_with($t, 'Revolução Farroupilha'));
        self::assertNotEmpty($doRs, 'o feriado da UF da lotação (RS, ' . $this->proximo('09-20') . ') vai');
    }

    #[TestDox('O id de data que vem do Google nunca vira compromisso, com linha ou sem linha aqui')]
    public function testIdDeDataNuncaViraCompromisso(): void
    {
        [$tenant, , $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $agenda = $this->agendaDe($conexao);
        $traducao = new DataParaOGoogle();
        $comLinha = $traducao->idPara((int) $tenant->getId(), 'fer-' . $this->proximo('12-25') . '-natal');
        $semLinha = $traducao->idPara((int) $tenant->getId(), 'chave-inexistente');
        $natal = $this->google->eventos[$agenda][$comLinha];

        $this->google->paraTrazer[$agenda] = [
            ['summary' => 'Mexi aqui'] + $natal,
            ['id' => $semLinha, 'summary' => 'Inventada no Google'] + $natal,
        ];
        $this->sincronizar($conexao);
        $this->sincronizar($conexao);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id = :t', ['t' => $tenant->getId()]));
        self::assertSame('Natal', $this->google->eventos[$agenda][$comLinha]['summary']);
    }

    #[TestDox('Aniversário oculto pelo escritório não vai ao Google')]
    public function testAniversarioOcultoNaoVai(): void
    {
        [$tenant, $eu, $conexao] = $this->cenario();
        $dia = $this->proximo('03-15');
        $this->aniversario($eu, $dia);
        $this->em()->persist(AjusteDeData::ocultar($this->em()->find(Tenant::class, $tenant->getId()), 'aniv-' . $eu->getId() . '-' . $dia, $this->em()->find(User::class, $eu->getId())));
        $this->em()->flush();

        $this->sincronizar($conexao);

        $titulos = array_keys($this->porTitulo($conexao));
        self::assertArrayHasKey('Natal', $this->porTitulo($conexao), 'as outras datas vão');
        self::assertSame([], array_values(array_filter($titulos, static fn (string $t): bool => str_starts_with($t, '🎂 Aniversário de'))));
    }

    #[TestDox('Apagada no Google volta quando a data muda aqui, com o nome novo')]
    public function testApagadaLaVoltaQuandoMudaAqui(): void
    {
        [$tenant, $eu, $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $agenda = $this->agendaDe($conexao);
        $chave = 'fer-' . $this->proximo('12-25') . '-natal';
        $id = (new DataParaOGoogle())->idPara((int) $tenant->getId(), $chave);
        unset($this->google->eventos[$agenda][$id]);
        $this->google->paraTrazer[$agenda] = [['id' => $id, 'status' => 'cancelled']];
        $this->sincronizar($conexao);
        self::assertArrayNotHasKey($id, $this->google->eventos[$agenda], 'apagada lá, fica apagada');

        $this->em()->persist(AjusteDeData::renomear($this->em()->find(Tenant::class, $tenant->getId()), $chave, 'Natal (recesso do escritório)', $this->em()->find(User::class, $eu->getId())));
        $this->em()->flush();
        $this->sincronizar($conexao);

        self::assertSame('Natal (recesso do escritório)', $this->google->eventos[$agenda][$id]['summary'] ?? null);
    }

    /** O próximo dia/mês dentro da janela (de 30 dias atrás em diante), em Y-m-d. */
    private function proximo(string $mesDia): string
    {
        $limite = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
        $ano = (int) date('Y');
        $data = $ano . '-' . $mesDia;

        return $data >= $limite ? $data : ($ano + 1) . '-' . $mesDia;
    }

    /** @return array<string, array<string, mixed>> */
    private function porTitulo(ConexaoGoogleAgenda $conexao): array
    {
        $saida = [];
        foreach ($this->google->eventos[$this->agendaDe($conexao)] ?? [] as $evento) {
            $saida[(string) $evento['summary']] = $evento;
        }

        return $saida;
    }

    /** @return array<string, int> */
    private function sincronizar(ConexaoGoogleAgenda $conexao): array
    {
        $r = static::getContainer()->get(SincronizarGoogleAgendaUseCase::class)->executar((int) $conexao->getId(), new \DateTimeImmutable());
        $this->em()->clear();

        return $r ?? ['ocupada' => true];
    }

    private function agendaDe(ConexaoGoogleAgenda $conexao): string
    {
        return (string) $this->em()->find(ConexaoGoogleAgenda::class, $conexao->getId())?->getCalendarioId();
    }

    /** @return array{Tenant, User, ConexaoGoogleAgenda} */
    private function cenario(): array
    {
        $tenant = (new Tenant())->setName('Tenant DATAS GOOGLE ' . uniqid());
        $this->em()->persist($tenant);
        $eu = (new User())->setEmail('dg_' . uniqid() . '@test.com')->setFullName('Pessoa Datas')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($eu);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Gestor ' . uniqid())->setIsSystem(true);
        $this->em()->persist($papel);
        $vinculo = new UserTenant($eu, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $conexao = new ConexaoGoogleAgenda($tenant, $eu, static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH'), 'dg@gmail.com', 'America/Sao_Paulo');
        $this->em()->persist($conexao);
        $this->em()->flush();

        return [$tenant, $eu, $conexao];
    }

    private function aniversario(User $pessoa, string $dia): void
    {
        $perfil = (new UserProfile($this->em()->find(User::class, $pessoa->getId())))->setDataNascimento(new \DateTime('1990-' . substr($dia, 5)));
        $this->em()->persist($perfil);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
