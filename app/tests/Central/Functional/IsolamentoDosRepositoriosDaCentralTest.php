<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Entity\Presenca;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Repository\PresencaRepository;
use App\Central\Service\ConversaDaIaDaCentral;
use App\Entity\Notificacao;
use App\Repository\NotificacaoRepository;
use App\Entity\Auth\UserTenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A guarda de escritório EXPLÍCITA das consultas da Central, provada com o `TenantFilter` DESLIGADO — no teste HTTP o
 * filtro (e o redirecionamento da plataforma) pegam antes e esconderiam a falta dela. A pessoa que está nos dois
 * escritórios é o caso que importa: ela é participante de verdade, só que do grupo do OUTRO escritório.
 */
#[CoversClass(ParticipanteRepository::class)]
#[CoversClass(MensagemRepository::class)]
#[CoversClass(ConversaDaIaDaCentral::class)]
#[CoversClass(PresencaRepository::class)]
#[CoversClass(NotificacaoRepository::class)]
#[CoversClass(ConfiguracaoDePresencaRepository::class)]
final class IsolamentoDosRepositoriosDaCentralTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    #[TestDox('Sem o TenantFilter, a participação, a lista, as não lidas e as novidades do escritório A não alcançam o grupo do B')]
    public function testGuardaExplicitaSemFiltro(): void
    {
        static::createClient();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($escritorioA, [], 'ana');
        $this->em()->persist(new UserTenant($ana, $escritorioB));
        $dora = $this->criarUsuarioComPermissoes($escritorioB, [], 'dora');

        $agora = new \DateTimeImmutable();
        $grupo = Conversa::grupo($escritorioB, $dora, 'Grupo do B', $agora);
        $this->em()->persist($grupo);
        $this->em()->persist(new Participante($escritorioB, $grupo, $dora, $agora));
        $this->em()->persist(new Participante($escritorioB, $grupo, $ana, $agora));
        $this->em()->persist(new Mensagem($escritorioB, $grupo, $dora, 'SEGREDO-DO-B', $agora));
        $this->em()->persist((new Notificacao())->setUsuario($ana)->setTenant($escritorioB)->setTipo('teste')->setTitulo('Aviso do B'));
        $this->em()->persist(new Presenca($escritorioB, $ana, $agora));
        $limiteDoB = new ConfiguracaoDePresenca($escritorioB);
        $limiteDoB->alterar(15, $dora, $agora);
        $this->em()->persist($limiteDoB);
        $this->em()->flush();

        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
        /** @var ParticipanteRepository $participantes */
        $participantes = $this->em()->getRepository(Participante::class);
        /** @var MensagemRepository $mensagens */
        $mensagens = $this->em()->getRepository(Mensagem::class);

        self::assertNotNull($participantes->daConversa((int) $grupo->getId(), $ana, $escritorioB), 'controle: no B ela participa');
        self::assertNull($participantes->daConversa((int) $grupo->getId(), $ana, $escritorioA));
        self::assertSame([], $participantes->doUsuario($ana, $escritorioA));
        self::assertSame([], $mensagens->naoLidasPorConversa($ana, $escritorioA));
        // Equipe (F2): as não lidas da Ana no grupo do B não contam no A; no B contam (controle).
        self::assertSame([], $mensagens->naoLidasPorUsuario($escritorioA, [(int) $ana->getId()]));
        self::assertSame([(int) $ana->getId() => 1], $mensagens->naoLidasPorUsuario($escritorioB, [(int) $ana->getId()]));
        /** @var NotificacaoRepository $notificacoes */
        $notificacoes = $this->em()->getRepository(Notificacao::class);
        self::assertSame([], $notificacoes->contarNaoLidasPorUsuarios($escritorioA, [(int) $ana->getId()]));
        self::assertSame([(int) $ana->getId() => 1], $notificacoes->contarNaoLidasPorUsuarios($escritorioB, [(int) $ana->getId()]));
        /** @var PresencaRepository $presencas */
        $presencas = $this->em()->getRepository(Presenca::class);
        self::assertSame([], $presencas->doEscritorio($escritorioA, [(int) $ana->getId()]));
        self::assertArrayHasKey((int) $ana->getId(), $presencas->doEscritorio($escritorioB, [(int) $ana->getId()]));
        self::assertSame([], $mensagens->novidades($ana, $escritorioA, 0, 200));
        self::assertSame([], $mensagens->recentesAbaixoDoCursor($ana, $escritorioA, PHP_INT_MAX >> 33, new \DateTimeImmutable('-1 day'), 200));
        self::assertCount(1, $mensagens->recentesAbaixoDoCursor($ana, $escritorioB, PHP_INT_MAX >> 33, new \DateTimeImmutable('-1 day'), 200), 'controle: no B a janela a vê');
        self::assertSame(0, $mensagens->ultimoIdVisivelPara($ana, $escritorioA));
        self::assertSame([], $participantes->daConversaInteira($grupo, $escritorioA));
        // Fase 3: o limite de ausência que o primário do B escolheu não vale no A (o A fica no padrão).
        /** @var ConfiguracaoDePresencaRepository $configuracoes */
        $configuracoes = $this->em()->getRepository(ConfiguracaoDePresenca::class);
        self::assertNull($configuracoes->doEscritorio($escritorioA));
        self::assertSame(Presenca::INATIVO_APOS_MINUTOS, $configuracoes->minutosAusente($escritorioA));
        self::assertSame(15, $configuracoes->minutosAusente($escritorioB), 'controle: no B vale a escolha');
    }

    #[TestDox('Sem o TenantFilter, a conversa IA (agora visível ao dono) continua presa ao escritório e à pessoa: outro escritório e outro usuário não a alcançam')]
    public function testConversaIaVisivelSoAoDonoENoEscritorio(): void
    {
        static::createClient();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($escritorioA, [], 'ana');
        $bia = $this->criarUsuarioComPermissoes($escritorioA, [], 'bia');
        $agora = new \DateTimeImmutable();
        $iaDaAnaNoB = Conversa::comAIa($escritorioB, $ana, $agora);
        $this->em()->persist($iaDaAnaNoB);
        $this->em()->persist(new Participante($escritorioB, $iaDaAnaNoB, $ana, $agora));
        $this->em()->persist(Mensagem::daIA($escritorioB, $iaDaAnaNoB, $ana, 'IA-DO-B', $agora));
        $this->em()->flush();

        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
        /** @var ParticipanteRepository $participantes */
        $participantes = $this->em()->getRepository(Participante::class);
        /** @var MensagemRepository $mensagens */
        $mensagens = $this->em()->getRepository(Mensagem::class);
        $id = (int) $iaDaAnaNoB->getId();

        self::assertNotNull($participantes->daConversa($id, $ana, $escritorioB), 'controle: no B a dona abre');
        self::assertNull($participantes->daConversa($id, $ana, $escritorioA), 'no escritório errado, 404');
        self::assertNull($participantes->daConversa($id, $bia, $escritorioB), 'outra pessoa, 404');
        self::assertSame([], $participantes->doUsuario($ana, $escritorioA));
        self::assertSame([], $participantes->idsDasConversasAtivas($ana, $escritorioA));
        self::assertSame([], $mensagens->naoLidasPorConversa($ana, $escritorioA));
        self::assertSame([$id => 1], $mensagens->naoLidasPorConversa($ana, $escritorioB), 'controle: no B conta');
        self::assertSame([], $mensagens->novidades($ana, $escritorioA, 0, 200));
        self::assertSame([], $mensagens->novidades($bia, $escritorioB, 0, 200));
        self::assertCount(1, $mensagens->novidades($ana, $escritorioB, 0, 200), 'controle: no B a dona recebe');
        self::assertSame(0, $mensagens->ultimoIdVisivelPara($ana, $escritorioA));
        self::assertSame([], $mensagens->naoLidasPorUsuario($escritorioA, [(int) $ana->getId()]));
        self::assertSame([], $mensagens->naoLidasPorUsuario($escritorioB, [(int) $ana->getId()], semIa: true), 'a Equipe não soma a IA');
        self::assertSame([(int) $ana->getId() => 1], $mensagens->naoLidasPorUsuario($escritorioB, [(int) $ana->getId()]));
    }

    #[TestDox('Sem o TenantFilter, a porta do chat com a IA (contexto da pergunta e gravar resposta) não alcança a conversa do outro escritório')]
    public function testPortaDoChatComAIaSemFiltro(): void
    {
        static::createClient();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($escritorioA, [], 'ana');
        $this->em()->persist(new UserTenant($ana, $escritorioB));

        $agora = new \DateTimeImmutable();
        $iaDoB = Conversa::comAIa($escritorioB, $ana, $agora);
        $this->em()->persist($iaDoB);
        $this->em()->persist(new Participante($escritorioB, $iaDoB, $ana, $agora));
        $perguntaDoB = new Mensagem($escritorioB, $iaDoB, $ana, 'SEGREDO-DO-B', $agora);
        $this->em()->persist($perguntaDoB);
        $this->em()->flush();

        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
        /** @var ConversaDaIaDaCentral $porta */
        $porta = static::getContainer()->get(ConversaDaIaDaCentral::class);

        $contexto = $porta->contextoDaPergunta($escritorioB, (int) $iaDoB->getId(), (int) $perguntaDoB->getId(), $ana);
        self::assertNotNull($contexto, 'controle: no B ela pergunta na própria conversa');
        self::assertSame('SEGREDO-DO-B', $contexto['texto']);
        // Os mesmos ids pedidos pelo escritório A: nada vaza.
        self::assertNull($porta->contextoDaPergunta($escritorioA, (int) $iaDoB->getId(), (int) $perguntaDoB->getId(), $ana));

        try {
            $porta->gravarResposta($escritorioA, (int) $iaDoB->getId(), $ana, 'não devia gravar', $agora);
            self::fail('devia recusar: a conversa é do outro escritório');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
        /** @var ParticipanteRepository $participantes */
        $participantes = $this->em()->getRepository(Participante::class);
        self::assertTrue($participantes->participaAtivamente($iaDoB, $ana, $escritorioB), 'controle: no B ela participa');
        self::assertFalse($participantes->participaAtivamente($iaDoB, $ana, $escritorioA), 'com o escritório errado, não');
        $porta->gravarResposta($escritorioB, (int) $iaDoB->getId(), $ana, 'ok', $agora);
        $this->em()->flush();
        self::assertCount(1, $this->em()->getRepository(Mensagem::class)->findBy(['conversa' => $iaDoB->getId(), 'deIa' => true]), 'controle: no B grava');
    }
}
