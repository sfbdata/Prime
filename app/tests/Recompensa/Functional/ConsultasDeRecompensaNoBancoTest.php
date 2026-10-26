<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\Entity\AprovacaoDeContaDeRecompensa;
use App\Recompensa\Service\SuspeitaDeConta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As duas consultas agregadas das recompensas contra o banco (DAMA desfaz no fim de cada teste): indicadores e contas
 * criadas por indicação, com a conta contada uma vez e a pasta do escritório da conta.
 */
#[CoversClass(IndicacaoRepository::class)]
final class ConsultasDeRecompensaNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IndicacaoRepository $repo;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(IndicacaoRepository::class);
        $this->agora = new \DateTimeImmutable('2026-10-26 12:00:00');
    }

    private function usuario(string $nome, string $quando = '-1 year', string $dominio = 'exemplo.com'): User
    {
        $user = new User();
        $user->setEmail(strtolower(uniqid('rc_')) . '@' . $dominio);
        $user->setFullName($nome);
        $user->setPassword('x');
        $user->setCreatedAt($this->agora->modify($quando));
        $this->em->persist($user);

        return $user;
    }

    /** Escritório da conta nova, com o usuário vinculado e as pastas pedidas (excluídas ou não). */
    private function escritorioDe(User $conta, int $vivas, int $excluidas = 0): void
    {
        $tenant = (new Tenant())->setName('Escritório da conta ' . uniqid());
        $this->em->persist($tenant);
        $this->em->persist(new UserTenant($conta, $tenant));
        $this->em->flush();
        $con = $this->em->getConnection();
        for ($i = 0; $i < $vivas + $excluidas; ++$i) {
            $con->executeStatement(
                'INSERT INTO pasta (nup, data_abertura, created_at, tenant_id, excluida_em) VALUES (?, now(), now(), ?, ?)',
                ['NUP-' . uniqid(), $tenant->getId(), $i < $vivas ? null : '2026-10-01 10:00:00'],
            );
        }
    }

    /** @return array<string, array{indicador_id: int, conta_criada_em: string, tem_pasta: bool}> pelo e-mail da conta, para achar no teste */
    private function contasDe(User ...$indicadores): array
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $indicadores);

        return array_values(array_filter($this->repo->contasParaRecompensas(), static fn (array $c): bool => in_array($c['indicador_id'], $ids, true)));
    }

    #[TestDox('contas: cada conta uma vez (para quem indicou primeiro), com a data de criação e se o escritório tem pasta não excluída')]
    public function testContasUmaVezEComPasta(): void
    {
        $ana = $this->usuario('Ana Indicadora');
        $bia = $this->usuario('Bia Indicadora');

        $c1 = $this->usuario('Conta Um', '-50 days');
        $this->em->persist(Indicacao::porEmail($ana, null, $c1->getEmail(), null, $this->agora->modify('-60 days')));
        $this->escritorioDe($c1, 2);

        $c2 = $this->usuario('Conta Dois', '-5 days');
        $this->escritorioDe($c2, 0);

        $c3 = $this->usuario('Conta Tres', '-39 days');
        $this->em->persist(Indicacao::porEmail($ana, null, strtoupper($c3->getEmail()), null, $this->agora->modify('-40 days')));
        $this->escritorioDe($c3, 0, 3);

        $c4 = $this->usuario('Conta Quatro', '-20 days');
        $this->escritorioDe($c4, 1);
        $this->em->flush();
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($ana, null, $c2, $this->agora->modify('-5 days'))));
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($bia, null, $c3, $this->agora->modify('-39 days'))));
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($bia, null, $c4, $this->agora->modify('-20 days'))));

        $da = $this->contasDe($ana);
        $db = $this->contasDe($bia);

        self::assertCount(3, $da, 'c1 (e-mail), c2 (link) e c3 (e-mail, antes do link da Bia)');
        self::assertCount(1, $db, 'c3 já é da Ana; a Bia fica só com c4');
        $porData = [];
        foreach ([...$da, ...$db] as $c) {
            $porData[substr($c['conta_criada_em'], 0, 10)] = [$c['indicador_id'], $c['tem_pasta']];
        }
        self::assertSame([$ana->getId(), true], $porData[$this->agora->modify('-50 days')->format('Y-m-d')], 'c1: pasta viva');
        self::assertSame([$ana->getId(), false], $porData[$this->agora->modify('-5 days')->format('Y-m-d')], 'c2: sem pasta');
        self::assertSame([$ana->getId(), false], $porData[$this->agora->modify('-39 days')->format('Y-m-d')], 'c3: só pasta excluída, e é da Ana');
        self::assertSame([$bia->getId(), true], $porData[$this->agora->modify('-20 days')->format('Y-m-d')], 'c4');
    }

    #[TestDox('indicadores: quem tem ao menos uma indicação (mesmo só a atribuição pelo link), com envios só dos envios')]
    public function testIndicadores(): void
    {
        $ana = $this->usuario('Ana Indicadora');
        $bia = $this->usuario('Bia Atribuida');
        $sem = $this->usuario('Sem Indicacao');
        $this->em->persist(Indicacao::porCanal($ana, null, CanalDeIndicacao::Whatsapp, $this->agora->modify('-2 days')));
        $this->em->persist(Indicacao::porCanal($ana, null, CanalDeIndicacao::Link, $this->agora->modify('-1 day')));
        $nova = $this->usuario('Conta Nova', '-3 days');
        $this->em->flush();
        self::assertTrue($this->repo->gravarAtribuicao(Indicacao::porCadastroDoLink($bia, null, $nova, $this->agora->modify('-3 days'))));

        $linhas = [];
        foreach ($this->repo->indicadoresParaRecompensas() as $l) {
            $linhas[$l['user_id']] = $l;
        }

        self::assertSame(2, $linhas[$ana->getId()]['envios']);
        self::assertSame('Ana Indicadora', $linhas[$ana->getId()]['nome']);
        self::assertSame(0, $linhas[$bia->getId()]['envios'], 'a atribuição pelo link não é envio');
        self::assertArrayNotHasKey($sem->getId(), $linhas);
        self::assertSame(['user_id', 'nome', 'email', 'escritorio', 'envios'], array_keys($linhas[$ana->getId()]), 'nada do indicado');
    }

    /** A linha de UMA conta nova do indicador, com os sinais do antifraude. */
    private function sinaisDaConta(User $indicador, User $conta): array
    {
        $this->em->flush();
        foreach ($this->repo->contasParaRecompensas() as $c) {
            if ($c['conta_id'] === $conta->getId() && $c['indicador_id'] === $indicador->getId()) {
                return $c;
            }
        }
        self::fail('conta fora do resultado');
    }

    private function indicar(User $indicador, User $conta): void
    {
        $this->em->persist(Indicacao::porEmail($indicador, null, $conta->getEmail(), null, $this->agora->modify('-60 days')));
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $this->em->persist(new UserTenant($user, $tenant));
    }

    private function escritorio(?string $cnpj): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório ' . uniqid())->setCnpj($cnpj);
        $this->em->persist($tenant);

        return $tenant;
    }

    #[TestDox('antifraude: conta no MESMO escritório de quem indicou traz o escritório em comum; em escritório próprio não')]
    public function testMesmoEscritorio(): void
    {
        $ana = $this->usuario('Ana', dominio: 'ana.example');
        $mesma = $this->usuario('Mesma Casa', '-50 days', 'mesma.example');
        $outra = $this->usuario('Outra Casa', '-50 days', 'outra.example');
        $casa = $this->escritorio(null);
        $this->vincular($ana, $casa);
        $this->vincular($mesma, $casa);
        $this->vincular($outra, $this->escritorio(null));
        $this->indicar($ana, $mesma);
        $this->indicar($ana, $outra);

        $daMesma = $this->sinaisDaConta($ana, $mesma);
        $daOutra = $this->sinaisDaConta($ana, $outra);

        self::assertSame([$casa->getId()], array_intersect($daMesma['escritorios_conta'], $daMesma['escritorios_indicador']));
        self::assertSame([SuspeitaDeConta::ESCRITORIO], SuspeitaDeConta::daLinha($daMesma));
        self::assertSame([], SuspeitaDeConta::daLinha($daOutra));
    }

    #[TestDox('antifraude: mesmo CNPJ em escritórios diferentes é suspeita; CNPJ nulo nos dois lados não casa')]
    public function testMesmoCnpj(): void
    {
        $ana = $this->usuario('Ana', dominio: 'ana.example');
        $igual = $this->usuario('Cnpj Igual', '-50 days', 'igual.example');
        $nulo = $this->usuario('Cnpj Nulo', '-50 days', 'nulo.example');
        $this->vincular($ana, $this->escritorio('12345678000199'));
        $this->vincular($ana, $this->escritorio(null));
        $this->vincular($igual, $this->escritorio('12345678000199'));
        $this->vincular($nulo, $this->escritorio(null));
        $this->indicar($ana, $igual);
        $this->indicar($ana, $nulo);

        $daIgual = $this->sinaisDaConta($ana, $igual);
        $daNula = $this->sinaisDaConta($ana, $nulo);

        self::assertSame(['12345678000199'], $daIgual['cnpjs_conta']);
        self::assertSame([SuspeitaDeConta::CNPJ], SuspeitaDeConta::daLinha($daIgual), 'escritórios diferentes, mesmo CNPJ');
        self::assertSame([], $daNula['cnpjs_conta']);
        self::assertSame([], SuspeitaDeConta::daLinha($daNula), 'nulo não casa com nulo');
    }

    #[TestDox('antifraude: mesmo domínio corporativo é suspeita; domínio de provedor público e domínios diferentes não')]
    public function testMesmoDominio(): void
    {
        $ana = $this->usuario('Ana', dominio: 'banca.adv.br');
        $colega = $this->usuario('Colega', '-50 days', 'Banca.ADV.br');
        $publica = $this->usuario('Publica', '-50 days', 'gmail.com');
        $outra = $this->usuario('Outra', '-50 days', 'outra.adv.br');
        $anaGmail = $this->usuario('Ana Gmail', dominio: 'gmail.com');
        $amiga = $this->usuario('Amiga Gmail', '-50 days', 'gmail.com');
        foreach ([$colega, $publica, $outra] as $conta) {
            $this->indicar($ana, $conta);
        }
        $this->indicar($anaGmail, $amiga);

        self::assertSame([SuspeitaDeConta::DOMINIO], SuspeitaDeConta::daLinha($this->sinaisDaConta($ana, $colega)), 'o domínio vem em minúsculas do banco');
        self::assertSame([], SuspeitaDeConta::daLinha($this->sinaisDaConta($ana, $publica)));
        self::assertSame([], SuspeitaDeConta::daLinha($this->sinaisDaConta($ana, $outra)));
        self::assertSame([], SuspeitaDeConta::daLinha($this->sinaisDaConta($anaGmail, $amiga)), 'gmail com gmail não é suspeita');
    }

    #[TestDox('antifraude: aprovar a conta (linha em aprovacao_de_conta_de_recompensa) marca aprovada para aquele indicador e só para ele')]
    public function testAprovada(): void
    {
        $ana = $this->usuario('Ana', dominio: 'banca.adv.br');
        $bia = $this->usuario('Bia', dominio: 'outra.adv.br');
        $conta = $this->usuario('Conta', '-50 days', 'banca.adv.br');
        $this->indicar($ana, $conta);
        $this->em->flush();
        self::assertFalse($this->sinaisDaConta($ana, $conta)['aprovada']);

        $this->em->persist(new AprovacaoDeContaDeRecompensa($ana, $conta, null, $this->agora));
        $this->em->flush();

        self::assertTrue($this->sinaisDaConta($ana, $conta)['aprovada']);
        self::assertNotSame($bia->getId(), $ana->getId());
    }

    #[TestDox('antifraude: a linha da conta só traz sinais agregados (ids e domínios), nunca nome nem e-mail')]
    public function testSemNomeNemEmail(): void
    {
        $ana = $this->usuario('Ana', dominio: 'banca.adv.br');
        $conta = $this->usuario('Conta Secreta', '-50 days', 'banca.adv.br');
        $this->indicar($ana, $conta);

        $linha = $this->sinaisDaConta($ana, $conta);

        self::assertSame(['indicador_id', 'conta_id', 'conta_criada_em', 'tem_pasta', 'aprovada', 'escritorios_conta', 'escritorios_indicador', 'cnpjs_conta', 'cnpjs_indicador', 'dominio_conta', 'dominio_indicador'], array_keys($linha));
        self::assertStringNotContainsString('Secreta', (string) json_encode($linha));
        self::assertStringNotContainsString($conta->getEmail(), (string) json_encode($linha));
    }

    #[TestDox('antifraude: vínculo inativo e escritório excluído não geram sinal de escritório nem de CNPJ')]
    public function testVinculoMortoEEscritorioExcluidoNaoGeramSinal(): void
    {
        $ana = $this->usuario('Ana', dominio: 'ana.example');
        $inativa = $this->usuario('Vinculo Inativo', '-50 days', 'inativa.example');
        $excluida = $this->usuario('Escritorio Excluido', '-50 days', 'excluida.example');
        $casaA = $this->escritorio('12345678000199');
        $casaB = $this->escritorio('99999999000191');
        $this->vincular($ana, $casaA);
        $this->vincular($ana, $casaB);
        $this->vincular($inativa, $casaA);
        $this->vincular($excluida, $casaB);
        $this->indicar($ana, $inativa);
        $this->indicar($ana, $excluida);
        $this->em->flush();
        $con = $this->em->getConnection();
        $con->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$inativa->getId(), $casaA->getId()]);
        $con->executeStatement('UPDATE tenant SET excluido_em = now() WHERE id = ?', [$casaB->getId()]);

        self::assertSame([], SuspeitaDeConta::daLinha($this->sinaisDaConta($ana, $inativa)), 'o vínculo inativo da conta não conta');
        self::assertSame([], SuspeitaDeConta::daLinha($this->sinaisDaConta($ana, $excluida)), 'o escritório excluído não conta');
    }
}
