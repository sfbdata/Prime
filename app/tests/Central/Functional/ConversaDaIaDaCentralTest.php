<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\ConversaDaIaDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A porta do chat com a IA contra o banco, SEM TenantFilter (como o worker): o que ela confere ao ler a pergunta e o
 * que revalida ao gravar a resposta.
 */
#[CoversClass(ConversaDaIaDaCentral::class)]
final class ConversaDaIaDaCentralTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function porta(): ConversaDaIaDaCentral
    {
        return static::getContainer()->get(ConversaDaIaDaCentral::class);
    }

    /** @return array{0: Tenant, 1: User, 2: Conversa, 3: Participante} */
    private function cenario(): array
    {
        self::bootKernel();
        [$admin, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, [], 'ana');
        $agora = new \DateTimeImmutable();
        $conversa = Conversa::comAIa($tenant, $ana, $agora);
        $this->em()->persist($conversa);
        $participante = new Participante($tenant, $conversa, $ana, $agora);
        $this->em()->persist($participante);
        $this->em()->flush();

        return [$tenant, $ana, $conversa, $participante];
    }

    private function mensagem(Tenant $tenant, Conversa $conversa, User $autor, string $texto, string $tipo = 'usuario'): Mensagem
    {
        $agora = new \DateTimeImmutable();
        $mensagem = match ($tipo) {
            'ia' => Mensagem::daIA($tenant, $conversa, $autor, $texto, $agora),
            'sistema' => Mensagem::deSistema($tenant, $conversa, $autor, $texto, $agora),
            default => new Mensagem($tenant, $conversa, $autor, $texto, $agora),
        };
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
    }

    #[TestDox('o caminho feliz: texto, primeiro nome e histórico sem a própria pergunta, com o corte de 400 caracteres')]
    public function testCaminhoFeliz(): void
    {
        [$tenant, $ana, $conversa] = $this->cenario();
        $this->mensagem($tenant, $conversa, $ana, str_repeat('a', 500));
        $this->mensagem($tenant, $conversa, $ana, 'Resposta anterior', 'ia');
        $pergunta = $this->mensagem($tenant, $conversa, $ana, 'Pergunta de agora');

        $contexto = $this->porta()->contextoDaPergunta($tenant, (int) $conversa->getId(), (int) $pergunta->getId(), $ana);

        self::assertNotNull($contexto);
        self::assertSame('Pergunta de agora', $contexto['texto']);
        self::assertSame('Usuário', $contexto['nomeDaPessoa']);
        self::assertCount(2, $contexto['historico']);
        self::assertSame('usuario', $contexto['historico'][0]['de']);
        self::assertSame(400, mb_strlen($contexto['historico'][0]['texto']));
        self::assertSame(['de' => 'ia', 'texto' => 'Resposta anterior'], $contexto['historico'][1]);
    }

    #[TestDox('limpa_ate_id: o que a pessoa "excluiu" não volta no histórico')]
    public function testLimpaAte(): void
    {
        [$tenant, $ana, $conversa, $participante] = $this->cenario();
        $velha = $this->mensagem($tenant, $conversa, $ana, 'Velha');
        $this->mensagem($tenant, $conversa, $ana, 'Nova');
        $pergunta = $this->mensagem($tenant, $conversa, $ana, 'Agora');
        /** @var ParticipanteRepository $participantes */
        $participantes = $this->em()->getRepository(Participante::class);
        $participantes->avancarLimpeza($participante, (int) $velha->getId());
        $this->em()->clear();

        $contexto = $this->porta()->contextoDaPergunta($this->em()->find(Tenant::class, $tenant->getId()), (int) $conversa->getId(), (int) $pergunta->getId(), $this->em()->find(User::class, $ana->getId()));

        self::assertNotNull($contexto);
        self::assertSame(['Nova'], array_column($contexto['historico'], 'texto'));
    }

    #[TestDox('saiu_em preenchido: null na leitura e LogicException na gravação')]
    public function testSaiu(): void
    {
        [$tenant, $ana, $conversa, $participante] = $this->cenario();
        $pergunta = $this->mensagem($tenant, $conversa, $ana, 'Oi');
        $participante->sair(new \DateTimeImmutable());
        $this->em()->flush();

        self::assertNull($this->porta()->contextoDaPergunta($tenant, (int) $conversa->getId(), (int) $pergunta->getId(), $ana));
        $this->expectException(\LogicException::class);
        $this->porta()->gravarResposta($tenant, (int) $conversa->getId(), $ana, 'texto', new \DateTimeImmutable());
    }

    #[TestDox('pergunta de outro autor, fala da IA e mensagem de sistema não são perguntas: null')]
    public function testNaoEhPergunta(): void
    {
        [$tenant, $ana, $conversa] = $this->cenario();
        $bia = $this->criarUsuarioComPermissoes($tenant, [], 'bia');
        $deOutro = $this->mensagem($tenant, $conversa, $bia, 'Texto da Bia');
        $daIa = $this->mensagem($tenant, $conversa, $ana, 'Fala da IA', 'ia');
        $deSistema = $this->mensagem($tenant, $conversa, $ana, 'Sistema', 'sistema');

        foreach ([$deOutro, $daIa, $deSistema] as $mensagem) {
            self::assertNull($this->porta()->contextoDaPergunta($tenant, (int) $conversa->getId(), (int) $mensagem->getId(), $ana));
        }
    }
}
