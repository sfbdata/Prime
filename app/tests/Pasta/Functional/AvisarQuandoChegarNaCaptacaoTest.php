<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\ChaveDoAvisoDoPush;
use App\Pasta\Service\AvisoDoPushMailer;
use App\Pasta\Service\AvisoDoPushNaCaptacao;
use App\Pasta\UseCase\AvisarQuandoChegarNoPushUseCase;
use App\Processo\Entity\Processo;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * O efeito do "Avisar quando chegar" (spec push-avisar-quando-chegar §3), pela captação real
 * (`sincronizarNumeros`, a do "Buscar agora") com o DJEN dublado: sino e e-mail ao responsável,
 * agrupados por pasta; e-mail descadastrado não recebe; responsável inativo, sem vínculo e pasta
 * excluída não geram aviso; a IA só é pedida com a chave ligada; falha de e-mail não derruba nada.
 */
#[CoversClass(AvisarQuandoChegarNoPushUseCase::class)]
#[CoversClass(AvisoDoPushMailer::class)]
#[CoversClass(AvisoDoPushNaCaptacao::class)]
#[Group('pasta')]
final class AvisarQuandoChegarNaCaptacaoTest extends KernelTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';
    private const TEOR = 'Fica a parte autora intimada para apresentar réplica à contestação no prazo de 15 dias. ';

    protected function setUp(): void
    {
        self::bootKernel();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }

    /** @param list<int> $ids djenId das intimações que o DJEN devolve para o número */
    private function dublarDjen(array $ids): void
    {
        $itens = array_map(static fn (int $id): array => [
            'id' => $id,
            'numero_processo' => self::NUMERO,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => 'Intimação',
            'nomeOrgao' => '1ª Vara Cível de Brasília',
            'texto' => '<p>' . str_repeat(self::TEOR, 8) . 'FIM-DO-TEOR</p>',
            'data_disponibilizacao' => '2026-10-02',
        ], $ids);
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(
            static fn (ConsultaComunicacoesQuery $q): RespostaComunicacoes => $q->numeroProcesso === self::NUMERO
                ? new RespostaComunicacoes(\count($itens), $itens)
                : new RespostaComunicacoes(0, []),
        );
        static::getContainer()->set(DjenClient::class, $mock);
    }

    private function captar(Tenant $tenant): void
    {
        static::getContainer()->get(SincronizarPublicacoesDjenUseCase::class)
            ->sincronizarNumeros($tenant, PeriodoConsulta::ultimosDias(30, new \DateTimeImmutable('2026-10-19')), [self::NUMERO]);
    }

    /** @return array{0: User, 1: Tenant, 2: Pasta, 3: Processo} */
    private function cenario(): array
    {
        [$responsavel, $tenant] = $this->criarAdmin();
        $tenant->setName('Silva & Souza Advogados');
        $pasta = $this->criarPasta($tenant);
        $pasta->setResponsavel($responsavel);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        return [$responsavel, $tenant, $pasta, $processo];
    }

    /** @return list<Notificacao> */
    private function avisosDoPush(Tenant $tenant): array
    {
        return $this->em()->getRepository(Notificacao::class)->findBy(['tenant' => $tenant, 'tipo' => Notificacao::TIPO_PUSH_PUBLICACAO_PASTA]);
    }

    private function contarAnalises(Pasta $pasta): int
    {
        return \count($this->em()->getRepository(AnaliseDeInteligencia::class)->findBy(['alvoTipo' => AnaliseDeInteligencia::ALVO_PASTA, 'alvoId' => $pasta->getId()]));
    }

    #[TestDox('Uma publicação nova: aviso no sino ao responsável e e-mail com o resumo (nunca o teor inteiro), "Abrir a pasta" e o descadastro; o aviso geral do módulo continua; IA não (padrão desligado)')]
    public function testSinoEEmail(): void
    {
        [$responsavel, $tenant, $pasta] = $this->cenario();
        $this->dublarDjen([880001]);

        $this->captar($tenant);

        $avisos = $this->avisosDoPush($tenant);
        self::assertCount(1, $avisos);
        $esperado = 'Nova movimentação na Pasta ' . $pasta->getNup() . ': Intimação de 02/10/2026';
        self::assertSame($esperado, $avisos[0]->getMensagem());
        self::assertSame($responsavel->getId(), $avisos[0]->getUsuario()?->getId());
        self::assertSame('/pasta/' . $pasta->getId() . '#push', $avisos[0]->getUrl());
        self::assertNotEmpty($this->em()->getRepository(Notificacao::class)->findBy(['tenant' => $tenant, 'tipo' => Notificacao::TIPO_DJEN_PUBLICACAO]), 'o aviso geral continua');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', (string) $responsavel->getEmail());
        self::assertSame('[BlueJus] ' . $esperado, $email->getSubject());
        self::assertSame('Silva & Souza Advogados', $email->getFrom()[0]->getName());
        self::assertEmailHtmlBodyContains($email, 'Abrir a pasta');
        self::assertEmailHtmlBodyContains($email, '/pasta/' . $pasta->getId() . '#push');
        self::assertEmailHtmlBodyContains($email, 'Não quero mais receber estes avisos');
        self::assertEmailHtmlBodyContains($email, '1ª Vara Cível de Brasília');
        self::assertEmailHtmlBodyContains($email, 'Fica a parte autora intimada');
        self::assertEmailHtmlBodyNotContains($email, 'FIM-DO-TEOR');
        self::assertEmailHtmlBodyNotContains($email, '—');
        self::assertStringContainsString('/email/descadastro?', (string) $email->getHeaders()->getHeaderBody('List-Unsubscribe'));
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        self::assertSame(0, $this->contarAnalises($pasta));
    }

    #[TestDox('Duas publicações na mesma pasta: um aviso só ("2 novas movimentações na Pasta …") e um e-mail só')]
    public function testAgrupa(): void
    {
        [, $tenant, $pasta] = $this->cenario();
        $this->dublarDjen([880011, 880012]);

        $this->captar($tenant);

        $avisos = $this->avisosDoPush($tenant);
        self::assertCount(1, $avisos);
        self::assertSame('2 novas movimentações na Pasta ' . $pasta->getNup(), $avisos[0]->getMensagem());
        self::assertEmailCount(1);
    }

    #[TestDox('E-mail descadastrado: só o sino; e a captação repetida (nada novo) não avisa de novo')]
    public function testDescadastrado(): void
    {
        [$responsavel, $tenant] = $this->cenario();
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar($tenant, (string) $responsavel->getEmail(), AvisoDoPushMailer::ASSUNTO_DO_DESCADASTRO, 'link_do_email');
        $this->dublarDjen([880021]);

        $this->captar($tenant);
        $this->captar($tenant);

        self::assertCount(1, $this->avisosDoPush($tenant));
        self::assertEmailCount(0);
    }

    #[TestDox('Responsável inativo, responsável sem vínculo com o escritório e pasta excluída: nenhum aviso')]
    public function testSemDestinatario(): void
    {
        [$responsavel, $tenant, $pasta, $processo] = $this->cenario();
        $responsavel->setIsActive(false);
        [$deFora] = $this->criarAdmin();
        $outraPasta = $this->criarPasta($tenant);
        $outraPasta->setResponsavel($deFora);
        $this->vincular($outraPasta, $processo);
        $excluida = $this->criarPasta($tenant);
        [$ativo] = $this->criarAdmin();
        $excluida->setResponsavel($ativo);
        $excluida->marcarExcluida($ativo, new \DateTimeImmutable());
        $this->vincular($excluida, $processo);
        $this->em()->flush();
        $this->dublarDjen([880031]);

        $this->captar($tenant);

        self::assertSame([], $this->avisosDoPush($tenant));
        self::assertEmailCount(0);
        self::assertSame(0, $this->contarAnalises($pasta));
    }

    #[TestDox('"Análise da BlueJus IA" ligada (IA ligada no escritório): pede o resumo do Push da pasta, uma vez; desligada, não pede')]
    public function testIa(): void
    {
        [$responsavel, $tenant, $pasta] = $this->cenario();
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->atualizar(true, 50, 500, true, $responsavel);
        $this->em()->persist($configuracao);
        $outraPasta = $this->criarPasta($tenant);
        $outraPasta->setResponsavel($responsavel);
        $this->vincular($outraPasta, $this->criarProcesso($tenant, '07099999999999999999'));
        $linha = new AvisoDoPushDaPasta($tenant, $pasta, $responsavel, new \DateTimeImmutable());
        $linha->alternar(ChaveDoAvisoDoPush::Ia, $responsavel, new \DateTimeImmutable());
        $this->em()->persist($linha);
        $this->em()->flush();
        $this->dublarDjen([880041, 880042]);

        $this->captar($tenant);

        self::assertSame(1, $this->contarAnalises($pasta), 'um pedido por pasta por captação');
        self::assertSame(0, $this->contarAnalises($outraPasta));
    }

    #[TestDox('Falha do e-mail: registra e segue; a captação grava a publicação e o aviso no sino sai')]
    public function testFalhaDeEmail(): void
    {
        [, $tenant] = $this->cenario();
        static::getContainer()->set('mailer.mailer', new class () implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP fora do ar');
            }
        });
        $this->dublarDjen([880051]);

        $this->captar($tenant);

        self::assertCount(1, $this->avisosDoPush($tenant));
    }
}
