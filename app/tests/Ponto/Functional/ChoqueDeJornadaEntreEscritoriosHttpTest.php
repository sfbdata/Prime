<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Controller\JornadaTenantController;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\UseCase\AlterarRegistroDePontoUseCase;
use App\Service\TenantBootstrapService;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Sem choque de horário entre escritórios, pela tela (spec `docs/specs/seguranca-b-jornada.md` §4; achado de auditoria
 * interna F8/F11). A pessoa já registra ponto em B de segunda a sexta das 08:00 às 12:00; A é o escritório que grava.
 */
#[CoversClass(JornadaColaboradorController::class)]
#[CoversClass(JornadaTenantController::class)]
#[CoversClass(AlterarRegistroDePontoUseCase::class)]
#[CoversClass(ChoqueDeJornadaEntreEscritorios::class)]
final class ChoqueDeJornadaEntreEscritoriosHttpTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const MANHA_EM_B = [[1, 2, 3, 4, 5], '08:00', null, null, '12:00'];

    private KernelBrowser $client;
    private Tenant $a;
    private Tenant $b;
    private User $pessoa;
    private UserTenant $vinculoEmA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->a = $this->escritorio('Escritório A');
        $this->b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa nos dois');
        $this->vinculoEmA = $this->vincular($this->pessoa, $this->a);
        $this->vincular($this->pessoa, $this->b);
        $this->jornadaPessoal($this->pessoa, $this->b, [self::MANHA_EM_B]);
    }

    #[TestDox('Jornada pessoal que bate com B é recusada com a mensagem, sem o nome de B; a que não bate é aceita')]
    public function testJornadaPessoal(): void
    {
        $this->entrarComoAdminDe($this->a);

        $recusa = $this->chamarJson($this->client, 'POST', $this->urlDaJornada($this->a), $this->corpoDaJornada([[[1], '10:00', null, null, '14:00']]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $this->mensagem('08:00', '12:00')], $recusa);
        self::assertStringNotContainsString($this->b->getName(), (string) $this->client->getResponse()->getContent());
        self::assertNull($this->jornadaEm($this->a), 'nada foi gravado');

        $this->chamarJson($this->client, 'POST', $this->urlDaJornada($this->a), $this->corpoDaJornada([[[1], '13:00', null, null, '17:00']]));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Quem marcou primeiro fica: B não amplia a manhã para dentro da tarde que A já marcou')]
    public function testAmpliarParaDentroDoOutroEhRecusado(): void
    {
        $this->jornadaPessoal($this->pessoa, $this->a, [[[1], '13:00', null, null, '17:00']]);
        $this->entrarComoAdminDe($this->b);

        $recusa = $this->chamarJson($this->client, 'POST', $this->urlDaJornada($this->b), $this->corpoDaJornada([[[1, 2, 3, 4, 5], '08:00', null, null, '14:00']]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $this->mensagem('13:00', '17:00')], $recusa);
        self::assertSame('12:00', $this->jornadaEm($this->b)?->getBlocos()->first()->getSaida(), 'B continua como estava');
    }

    #[TestDox('Ligar a chave em A quando a padrão de A bate com B é recusado; sem choque, liga')]
    public function testLigarAChave(): void
    {
        $this->vinculoEmA->alterarRegistroDePonto(false);
        $this->em()->flush();
        $this->jornadaPadrao($this->a, [[[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00']]);
        $this->entrarComoAdminDe($this->a);

        $recusa = $this->chamarJson($this->client, 'POST', $this->urlDaChave($this->a), ['registraPonto' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $this->mensagem('08:00', '12:00')], $recusa);
        self::assertFalse($this->chaveEm($this->a));

        $this->jornadaPessoal($this->pessoa, $this->a, [[[1, 2, 3, 4, 5], '13:00', null, null, '17:00']]);
        self::assertSame(['ok' => true, 'registraPonto' => true], $this->chamarJson($this->client, 'POST', $this->urlDaChave($this->a), ['registraPonto' => true]));
        self::assertTrue($this->chaveEm($this->a));
    }

    #[TestDox('Jornada padrão que bate com B é recusada com a lista das pessoas deste escritório afetadas')]
    public function testJornadaPadraoListaAsPessoas(): void
    {
        $semChoque = $this->pessoa('Colega só de A');
        $this->vincular($semChoque, $this->a);
        $this->entrarComoAdminDe($this->a);

        $recusa = $this->chamarJson($this->client, 'POST', '/tenant/' . $this->a->getId() . '/jornada', $this->corpoDaPadrao());

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['nome' => 'Pessoa nos dois', 'mensagem' => $this->mensagem('08:00', '12:00')]], $recusa['pessoas']);
        self::assertSame(ChoqueDeJornadaEntreEscritorios::MENSAGEM_PADRAO . "\nPessoa nos dois: " . $this->mensagem('08:00', '12:00'), $recusa['erro']);
        self::assertStringNotContainsString($this->b->getName(), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Apagar a pessoal de A faz valer a padrão de A; quando ela bate com B, apagar é recusado')]
    public function testApagarAPessoalQuandoAPadraoBate(): void
    {
        $this->jornadaPadrao($this->a, [[[1, 2, 3, 4, 5], '07:00', null, null, '11:00']]);
        $this->jornadaPessoal($this->pessoa, $this->a, [[[1, 2, 3, 4, 5], '13:00', null, null, '17:00']]);
        $this->entrarComoAdminDe($this->a);

        $recusa = $this->chamarJson($this->client, 'DELETE', $this->urlDaJornada($this->a));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $this->mensagem('08:00', '12:00')], $recusa);
        self::assertCount(1, $this->jornadaEm($this->a)?->getBlocos() ?? [], 'a pessoal de A continua');
    }

    #[TestDox('F8: quem cria o escritório C não grava jornada que bate com a de A; a que não bate entra e a folha de A não muda')]
    public function testF8EscritorioCriadoPeloFuncionario(): void
    {
        $funcionario = $this->pessoa('Funcionário de A');
        $this->vincular($funcionario, $this->a);
        $this->jornadaPessoal($funcionario, $this->a, [[[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00']]);
        $folhaDeAntes = $this->saldoEmA($funcionario);
        $c = $this->escritorio('Escritório C');
        static::getContainer()->get(TenantBootstrapService::class)->bootstrap($c, $funcionario);
        $this->logarComTenant($this->client, $funcionario, $c);
        $urlEmC = sprintf('/tenant/%d/user/%d/', $c->getId(), $funcionario->getId());

        $this->chamarJson($this->client, 'POST', $urlEmC . 'registra-ponto', ['registraPonto' => true]);
        self::assertResponseIsSuccessful('ligar o ponto em C, ainda sem jornada, não bate com nada');
        $recusa = $this->chamarJson($this->client, 'POST', $urlEmC . 'jornada-colaborador', $this->corpoDaJornada([[[1], '10:00', null, null, '11:00']]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $this->mensagem('09:00', '12:00')], $recusa);
        self::assertStringNotContainsString($this->a->getName(), (string) $this->client->getResponse()->getContent());

        $this->chamarJson($this->client, 'POST', $urlEmC . 'jornada-colaborador', $this->corpoDaJornada([[[6], '10:00', null, null, '11:00']]));
        self::assertResponseIsSuccessful();
        self::assertSame($folhaDeAntes, $this->saldoEmA($funcionario));
        self::assertSame('09:00', $this->repositorio()->findByUserETenant($funcionario, $this->a)?->getBlocos()->first()->getEntrada());
    }

    private function entrarComoAdminDe(Tenant $tenant): void
    {
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $tenant, [], true);
        $this->logarComTenant($this->client, $admin, $tenant);
    }

    private function mensagem(string $inicio, string $fim): string
    {
        return sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE, $inicio, $fim);
    }

    /** @return array<string, mixed> padrão de A de segunda a sexta, 09:00 às 18:00 com almoço */
    private function corpoDaPadrao(): array
    {
        return $this->corpoDaJornada([[[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00']]) + [
            'validacaoRepousoHabilitada' => true,
            'minimoMinutosRepouso' => 60,
            'validacaoInterjornadaHabilitada' => true,
            'minimoMinutosInterjornada' => 660,
        ];
    }

    private function urlDaJornada(Tenant $tenant): string
    {
        return sprintf('/tenant/%d/user/%d/jornada-colaborador', $tenant->getId(), $this->pessoa->getId());
    }

    private function urlDaChave(Tenant $tenant): string
    {
        return sprintf('/tenant/%d/user/%d/registra-ponto', $tenant->getId(), $this->pessoa->getId());
    }

    private function jornadaEm(Tenant $tenant): ?\App\Ponto\Entity\JornadaColaborador
    {
        $this->em()->clear();

        return $this->repositorio()->findByUserETenant(
            $this->em()->find(User::class, $this->pessoa->getId()),
            $this->em()->find(Tenant::class, $tenant->getId()),
        );
    }

    private function chaveEm(Tenant $tenant): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne(
            'SELECT registra_ponto FROM user_tenant WHERE user_id = ? AND tenant_id = ?',
            [$this->pessoa->getId(), $tenant->getId()],
        );
    }

    private function saldoEmA(User $user): int
    {
        return static::getContainer()->get(FolhaPontoBuilder::class)
            ->calcularSaldoAteMes($user, 2025, 1, [], null, new \DateTimeImmutable('2025-01-01'), $this->a);
    }

    private function repositorio(): JornadaColaboradorRepository
    {
        return static::getContainer()->get(JornadaColaboradorRepository::class);
    }
}
