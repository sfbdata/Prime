<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\UseCase\AbrirConversaComAIaUseCase;
use App\Entity\Auth\UserTenant;
use Symfony\Component\Clock\NativeClock;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Conversa "BlueJus IA" (F3, PR 1): invisível nas consultas da tela até o PR da tela, uma por (escritório, usuário), e
 * a fala `de_ia` conta como de OUTRA pessoa. Tudo com o `TenantFilter` DESLIGADO, como em
 * `IsolamentoDosRepositoriosDaCentralTest`: a guarda é a explícita da consulta.
 */
#[CoversClass(ConversaRepository::class)]
#[CoversClass(ParticipanteRepository::class)]
#[CoversClass(MensagemRepository::class)]
#[CoversClass(AbrirConversaComAIaUseCase::class)]
final class ConversaComAIaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    /** @return array{0: Tenant, 1: Tenant, 2: User, 3: User} */
    private function cenario(): array
    {
        static::createClient();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($escritorioA, [], 'ana');
        $bia = $this->criarUsuarioComPermissoes($escritorioA, [], 'bia');
        $this->em()->flush();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }

        return [$escritorioA, $escritorioB, $ana, $bia];
    }

    private function iaDe(Tenant $tenant, User $usuario): Conversa
    {
        $agora = new \DateTimeImmutable();
        $ia = Conversa::comAIa($tenant, $usuario, $agora);
        $this->em()->persist($ia);
        $this->em()->persist(new Participante($tenant, $ia, $usuario, $agora));

        return $ia;
    }

    #[TestDox('Nenhuma consulta da tela enxerga a conversa IA nem as mensagens dela; a individual continua aparecendo')]
    public function testConversaIaInvisivel(): void
    {
        [$a, , $ana, $bia] = $this->cenario();
        $agora = new \DateTimeImmutable();
        $ia = $this->iaDe($a, $ana);
        $this->em()->persist(new Mensagem($a, $ia, $ana, 'pergunta', $agora));
        $this->em()->persist(Mensagem::daIA($a, $ia, $ana, 'resposta-da-ia', $agora));
        $individual = Conversa::individual($a, $ana, $bia, $agora);
        $this->em()->persist($individual);
        $this->em()->persist(new Participante($a, $individual, $ana, $agora));
        $this->em()->persist(new Participante($a, $individual, $bia, $agora));
        $this->em()->persist(new Mensagem($a, $individual, $bia, 'oi', $agora));
        $this->em()->flush();

        /** @var ParticipanteRepository $participantes */
        $participantes = $this->em()->getRepository(Participante::class);
        /** @var MensagemRepository $mensagens */
        $mensagens = $this->em()->getRepository(Mensagem::class);
        /** @var ConversaRepository $conversas */
        $conversas = $this->em()->getRepository(Conversa::class);
        $anaId = (int) $ana->getId();

        self::assertNull($participantes->daConversa((int) $ia->getId(), $ana, $a), 'abrir a IA por id é 404');
        self::assertNotNull($participantes->daConversa((int) $individual->getId(), $ana, $a), 'controle');
        self::assertSame([(int) $individual->getId()], array_map(static fn (Participante $p): int => (int) $p->getConversa()->getId(), $participantes->doUsuario($ana, $a)));
        self::assertSame([(int) $individual->getId() => true], $participantes->idsDasConversasAtivas($ana, $a));
        self::assertSame([(int) $individual->getId() => 1], $mensagens->naoLidasPorConversa($ana, $a));
        self::assertSame([$anaId => 1], $mensagens->naoLidasPorUsuario($a, [$anaId]));
        $textos = array_map(static fn (Mensagem $m): string => $m->getConteudo(), $mensagens->novidades($ana, $a, 0, 200));
        self::assertSame(['oi'], $textos);
        $janela = $mensagens->recentesAbaixoDoCursor($ana, $a, PHP_INT_MAX >> 33, new \DateTimeImmutable('-1 day'), 200);
        self::assertSame(['oi'], array_map(static fn (Mensagem $m): string => $m->getConteudo(), $janela));
        self::assertSame($this->idDe($mensagens, 'oi'), $mensagens->ultimoIdVisivelPara($ana, $a));
        // O par individual nunca resolve para a IA (menor = maior = ela).
        self::assertNull($conversas->individualEntre($a, $ana, $ana));
        self::assertSame($ia, $conversas->daIaDe($a, $ana));
    }

    private function idDe(MensagemRepository $mensagens, string $texto): int
    {
        return (int) $mensagens->findOneBy(['conteudo' => $texto])?->getId();
    }

    #[TestDox('Só a conversa IA, sem mais nada: tudo vazio e cursor 0')]
    public function testSoIaFicaVazio(): void
    {
        [$a, , $ana] = $this->cenario();
        $ia = $this->iaDe($a, $ana);
        $this->em()->persist(Mensagem::daIA($a, $ia, $ana, 'so-ia', new \DateTimeImmutable()));
        $this->em()->flush();

        /** @var MensagemRepository $mensagens */
        $mensagens = $this->em()->getRepository(Mensagem::class);
        self::assertSame([], $mensagens->naoLidasPorConversa($ana, $a));
        self::assertSame([], $mensagens->novidades($ana, $a, 0, 200));
        self::assertSame(0, $mensagens->ultimoIdVisivelPara($ana, $a));
    }

    #[TestDox('Mensagem de_ia conta como não lida de OUTRA pessoa, mesmo com o dono como autor')]
    public function testDeIaContaComoDeOutraPessoa(): void
    {
        [$a, , $ana, $bia] = $this->cenario();
        $agora = new \DateTimeImmutable();
        $individual = Conversa::individual($a, $ana, $bia, $agora);
        $this->em()->persist($individual);
        $this->em()->persist(new Participante($a, $individual, $ana, $agora));
        $this->em()->persist(new Participante($a, $individual, $bia, $agora));
        $this->em()->persist(new Mensagem($a, $individual, $ana, 'minha', $agora));
        $this->em()->persist(Mensagem::daIA($a, $individual, $ana, 'da-ia', $agora));
        $this->em()->flush();

        /** @var MensagemRepository $mensagens */
        $mensagens = $this->em()->getRepository(Mensagem::class);
        $id = (int) $individual->getId();
        self::assertSame([$id => 1], $mensagens->naoLidasPorConversa($ana, $a), 'a minha não conta; a de_ia conta');
        self::assertSame([(int) $ana->getId() => 1], $mensagens->naoLidasPorUsuario($a, [(int) $ana->getId()]));
        self::assertSame([$id => 2], $mensagens->naoLidasPorConversa($bia, $a), 'para a outra pessoa contam as duas');
    }

    #[TestDox('Escritório explícito: a IA do escritório A não aparece no B')]
    public function testIaNaoVazaEntreEscritorios(): void
    {
        [$a, $b, $ana] = $this->cenario();
        $ia = $this->iaDe($a, $ana);
        $this->em()->flush();

        /** @var ConversaRepository $conversas */
        $conversas = $this->em()->getRepository(Conversa::class);
        self::assertSame($ia, $conversas->daIaDe($a, $ana));
        self::assertNull($conversas->daIaDe($b, $ana));
    }

    #[TestDox('UseCase: abre uma vez, devolve a mesma depois, uma por pessoa e por escritório, só o dono participa')]
    public function testUseCaseIdempotenteEIsolado(): void
    {
        [$a, , $ana, $bia] = $this->cenario();
        /** @var ParticipanteRepository $participantes */
        $participantes = $this->em()->getRepository(Participante::class);
        /** @var ConversaRepository $conversas */
        $conversas = $this->em()->getRepository(Conversa::class);
        $useCase = new AbrirConversaComAIaUseCase(
            new AcessoACentral($this->em()->getRepository(UserTenant::class), $participantes, $this->em()->getRepository(User::class)),
            $conversas,
            $participantes,
            $this->em(),
            new NativeClock(),
        );

        $primeira = $useCase->executar($ana, $a);
        self::assertSame($primeira, $useCase->executar($ana, $a), 'idempotente');
        $daBia = $useCase->executar($bia, $a);
        self::assertNotSame($primeira, $daBia, 'uma por pessoa');

        $linhas = $participantes->findBy(['conversa' => $primeira]);
        self::assertCount(1, $linhas);
        self::assertSame($ana, $linhas[0]->getUsuario());
        self::assertNull($participantes->daConversa($primeira, $bia, $a), 'conversa IA de outro usuário é invisível');
    }

    #[TestDox('O índice único do par barra uma segunda conversa IA do mesmo usuário no escritório')]
    public function testUnicidadeNoBanco(): void
    {
        [$a, , $ana] = $this->cenario();
        $this->iaDe($a, $ana);
        $this->em()->flush();
        $this->em()->persist(Conversa::comAIa($a, $ana, new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }
}
