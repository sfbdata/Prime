<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
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
        self::assertSame([], $mensagens->novidades($ana, $escritorioA, 0, 200));
        self::assertSame([], $mensagens->recentesAbaixoDoCursor($ana, $escritorioA, PHP_INT_MAX >> 33, new \DateTimeImmutable('-1 day'), 200));
        self::assertCount(1, $mensagens->recentesAbaixoDoCursor($ana, $escritorioB, PHP_INT_MAX >> 33, new \DateTimeImmutable('-1 day'), 200), 'controle: no B a janela a vê');
        self::assertSame(0, $mensagens->ultimoIdVisivelPara($ana, $escritorioA));
        self::assertSame([], $participantes->daConversaInteira($grupo, $escritorioA));
    }
}
