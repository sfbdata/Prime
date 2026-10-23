<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Comercial\Envio\CarteiroInterface;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Service\DiaUtilDoEscritorio;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Repository\ResumoDiarioEnvioRepository;
use App\Profile\Service\ColetorDoResumoDiario;
use App\Profile\UseCase\EnviarResumoDiarioDoEscritorioUseCase;
use App\Repository\UserTenantRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Resumo diário por e-mail (spec docs/specs/resumo-diario-por-email.md): o que entra, uma vez por dia, quem desligou,
 * dia sem nada, fim de semana, a simulação e o cadeado.
 */
#[CoversClass(EnviarResumoDiarioDoEscritorioUseCase::class)]
#[CoversClass(ColetorDoResumoDiario::class)]
#[CoversClass(ResumoDiarioEnvioRepository::class)]
final class ResumoDiarioPorEmailTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const QUARTA = '2026-10-21 08:00:00';

    private Tenant $tenant;
    private User $primario;
    private User $ana;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->ana = $this->criarColaborador($this->tenant, 'Ana Resumo');
        $this->pasta = $this->criarPasta($this->tenant);
        $this->pasta->setResponsavel($this->ana);
        $prazo = new PastaPrazo($this->tenant, $this->pasta, $this->primario);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'Contestação do réu', new \DateTimeImmutable('2026-10-21'), '17:00', 'Ana');
        $this->em()->persist($prazo);
        $meta = new Tarefa();
        $meta->setTitulo('Revisar a petição inicial');
        $meta->setDescricao('x');
        $meta->setPasta($this->pasta);
        $meta->setTenant($this->tenant);
        $meta->setCriadoPor($this->primario);
        $meta->setPrazo(new \DateTimeImmutable('2026-10-21 18:00'));
        $meta->addResponsavel($this->ana);
        $this->em()->persist($meta);
        $audiencia = (new Evento())->setTitulo('Audiência de conciliação')->setDataInicio(new \DateTimeImmutable('2026-10-21 14:30'))
            ->setDataFim(new \DateTimeImmutable('2026-10-21 15:30'))->setStatus(Evento::STATUS_AGENDADO)->setCriador($this->primario)->setTenant($this->tenant);
        $audiencia->setTipo(TipoDeCompromisso::Audiencia);
        $audiencia->addParticipante($this->ana);
        $reuniao = (new Evento())->setTitulo('Reunião de equipe')->setDataInicio(new \DateTimeImmutable('2026-10-21 10:00'))
            ->setDataFim(new \DateTimeImmutable('2026-10-21 11:00'))->setStatus(Evento::STATUS_AGENDADO)->setCriador($this->primario)->setTenant($this->tenant);
        $reuniao->setTipo(TipoDeCompromisso::Reuniao);
        $reuniao->addParticipante($this->ana);
        $this->em()->persist($audiencia);
        $this->em()->persist($reuniao);
        $this->em()->flush();
    }

    #[TestDox('envia uma vez por pessoa e dia: o prazo da pasta dela, a meta de hoje e a audiência da agenda (de quem criou e de quem participa)')]
    public function testEnviaUmaVez(): void
    {
        $r = $this->executar(self::QUARTA);

        self::assertSame([2, 0, 0, 0], [$r->enviados, $r->semNada, $r->desligados, $r->jaEnviados]);
        $envio = $this->envioPara($this->ana);
        self::assertSame([$this->ana->getEmail(), 'resumo_diario', $this->tenant->getId(), 'Seus prazos e metas de hoje, 21/10'], [$envio['destinatario'], $envio['lista'], $envio['tenant_id'], $envio['assunto']]);
        self::assertStringContainsString('17:00 · Prazo: Contestação do réu', $envio['corpo_html']);
        self::assertStringContainsString('Revisar a petição inicial', $envio['corpo_html']);
        self::assertStringContainsString('14:30 · Audiência: Audiência de conciliação', $envio['corpo_html'], 'a audiência da agenda dela entra');
        self::assertStringNotContainsString('Reunião de equipe', $envio['corpo_html'], 'reunião não é prazo nem audiência');
        self::assertStringContainsString('Bom dia, Ana.', $envio['corpo_html']);

        $daPrimaria = (string) $this->envioPara($this->primario)['corpo_html'];
        self::assertStringContainsString('Audiência de conciliação', $daPrimaria, 'quem criou a audiência também recebe');
        self::assertStringNotContainsString('Contestação do réu', $daPrimaria, 'o prazo é da pasta de que a Ana é responsável');

        $outra = $this->executar(self::QUARTA);
        self::assertSame([0, 2], [$outra->enviados, $outra->jaEnviados]);
        self::assertSame(2, $this->enviosDoEscritorio());
    }

    #[TestDox('quem desligou a chave não recebe; a simulação conta sem gravar nem enviar')]
    public function testDesligadoESimulacao(): void
    {
        $simulado = $this->executar(self::QUARTA, true);
        self::assertSame(2, $simulado->enviados);
        self::assertSame(0, $this->enviosDoEscritorio());

        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->gravar($this->tenant, $this->ana, CatalogoDePreferenciasDoPerfil::RESUMO_EMAIL, false);
        $r = $this->executar(self::QUARTA);
        self::assertSame([1, 1], [$r->enviados, $r->desligados]);
        self::assertFalse($this->envioPara($this->ana), 'a Ana desligou');
    }

    #[TestDox('sábado não envia')]
    public function testFimDeSemana(): void
    {
        $r = $this->executar('2026-10-24 08:00:00');

        self::assertSame('Hoje não é dia útil no escritório.', $r->motivo);
        self::assertSame(0, $this->enviosDoEscritorio());
    }

    #[TestDox('cadeado: o prazo e a meta da pasta oculta para a pessoa somem do resumo')]
    public function testCadeado(): void
    {
        $this->trancar($this->pasta, $this->primario);

        $r = $this->executar(self::QUARTA);

        // A audiência sem pasta continua: o cadeado só tira o que é da pasta oculta.
        self::assertSame([2, 0], [$r->enviados, $r->semNada]);
        $corpo = (string) $this->envioPara($this->ana)['corpo_html'];
        self::assertStringNotContainsString('Contestação do réu', $corpo);
        self::assertStringNotContainsString('Revisar a petição inicial', $corpo);
        self::assertStringContainsString('Audiência de conciliação', $corpo);
    }

    private function executar(string $agora, bool $simular = false, ?CarteiroInterface $carteiro = null): \App\Profile\DTO\ResultadoDoResumoDiario
    {
        $c = static::getContainer();
        $sut = new EnviarResumoDiarioDoEscritorioUseCase(
            $c->get(UserTenantRepository::class),
            $c->get(PreferenciaDoUsuarioRepository::class),
            $c->get(ColetorDoResumoDiario::class),
            $c->get(ResumoDiarioEnvioRepository::class),
            $c->get(DiaUtilDoEscritorio::class),
            $carteiro ?? $c->get(CarteiroInterface::class),
            $c->get(Environment::class),
            $c->get(UrlGeneratorInterface::class),
            new MockClock(new \DateTimeImmutable($agora)),
            new NullLogger(),
        );

        return $sut->executar($this->tenant, $simular);
    }

    #[TestDox('a audiência ligada a pasta oculta pelo cadeado também some; conta inativa e e-mail por confirmar não recebem')]
    public function testCadeadoNaAgendaEContasForaDoEnvio(): void
    {
        $this->em()->createQuery('UPDATE ' . Evento::class . ' e SET e.pasta = :p WHERE e.titulo = :t')
            ->setParameter('p', $this->pasta)->setParameter('t', 'Audiência de conciliação')->execute();
        $this->trancar($this->pasta, $this->primario);
        $r = $this->executar(self::QUARTA);
        self::assertFalse($this->envioPara($this->ana), 'tudo da Ana era da pasta oculta');
        self::assertSame(1, $r->semNada);

        $this->primario->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->em()->flush();
        $outra = $this->executar(self::QUARTA);
        self::assertSame([0, 0, 1], [$outra->enviados, $outra->jaEnviados, $outra->semNada], 'a primária, com o e-mail por confirmar, fica de fora (nem conta como já enviada)');
    }

    #[TestDox('falha do Carteiro: cada pessoa falha sozinha e o registro é desfeito; a rodada seguinte envia, e o descadastrado conta à parte')]
    public function testFalhaEDescadastro(): void
    {
        $quebrado = $this->createStub(CarteiroInterface::class);
        $quebrado->method('enviar')->willThrowException(new \RuntimeException('fila fora do ar'));
        $falhou = $this->executar(self::QUARTA, false, $quebrado);
        self::assertSame([2, 0], [$falhou->falhas, $falhou->enviados], 'ninguém derruba os outros');
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM resumo_diario_envio WHERE tenant_id = ?', [$this->tenant->getId()]), 'registro desfeito');

        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar($this->tenant, (string) $this->ana->getEmail(), EnviarResumoDiarioDoEscritorioUseCase::LISTA, 'teste');
        $r = $this->executar(self::QUARTA);
        self::assertSame([1, 1, 0], [$r->enviados, $r->descadastrados, $r->falhas], 'a rodada seguinte envia de novo; a Ana descadastrou pelo rodapé');
    }

    /** @return array<string, mixed>|false */
    private function envioPara(User $pessoa): array|false
    {
        return $this->conexao()->fetchAssociative("SELECT e.destinatario, e.lista, e.tenant_id, e.assunto, c.corpo_html FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id WHERE e.origem = 'resumo_diario' AND e.tenant_id = ? AND e.destinatario = ?", [$this->tenant->getId(), $pessoa->getEmail()]);
    }

    private function enviosDoEscritorio(): int
    {
        return (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem = 'resumo_diario' AND tenant_id = ?", [$this->tenant->getId()]);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
