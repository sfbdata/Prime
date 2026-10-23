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
}
