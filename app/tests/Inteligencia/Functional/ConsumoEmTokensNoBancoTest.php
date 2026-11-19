<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\Saldo\ConsumoEmTokens;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As somas do saldo de I.A. por pessoa contra o banco (spec `ia-liberada-por-indicacao.md`, seção 3): só a pessoa, só os
 * escritórios dados, qualquer status, o chat inclusive interrompido e refeito, e nunca a fala do usuário.
 */
#[CoversClass(ConsumoEmTokens::class)]
#[CoversClass(AnaliseDeInteligenciaRepository::class)]
#[CoversClass(MensagemDeChatRepository::class)]
final class ConsumoEmTokensNoBancoTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function analise(Tenant $tenant, User $user, ?int $entrada, ?int $saida, string $status = 'concluida'): void
    {
        $analise = $this->criarAnalisePendente($tenant, $user, $this->criarPasta($tenant));
        $this->em()->getConnection()->executeStatement(
            'UPDATE inteligencia_analise SET status = ?, tokens_entrada = ?, tokens_saida = ? WHERE id = ?',
            [$status, $entrada, $saida, (int) $analise->getId()],
        );
    }

    private function conversa(Tenant $tenant, User $dono): ConversaDeChat
    {
        $conversa = new ConversaDeChat($tenant, $dono, ModeloDeIa::PADRAO, new \DateTimeImmutable());
        $this->em()->persist($conversa);
        $this->em()->flush();

        return $conversa;
    }

    private function resposta(ConversaDeChat $conversa, ?int $entrada, ?int $saida, bool $interrompida = false, bool $substituida = false): void
    {
        $mensagem = MensagemDeChat::doAssistente($conversa, 'resposta', ModeloDeIa::PADRAO->value, $entrada, $saida, $interrompida, new \DateTimeImmutable());
        $this->em()->persist($mensagem);
        $this->em()->flush();
        if ($substituida) {
            $this->em()->getConnection()->executeStatement('UPDATE chat_ia_mensagem SET substituida = true WHERE id = ?', [(int) $mensagem->getId()]);
        }
    }

    private function sut(): ConsumoEmTokens
    {
        return static::getContainer()->get(ConsumoEmTokens::class);
    }

    #[TestDox('as análises somam entrada + saída em qualquer status; ignoram outra pessoa e outro escritório')]
    public function testSomaDasAnalises(): void
    {
        self::bootKernel();
        [$ana, $escritorioA] = $this->criarAdmin();
        [$bia, $escritorioB] = $this->criarAdmin();

        $this->analise($escritorioA, $ana, 1000, 200);
        $this->analise($escritorioA, $ana, 300, 30, 'falhou');
        $this->analise($escritorioA, $ana, null, null, 'pendente');
        $this->analise($escritorioB, $bia, 7000, 7000);

        $repo = static::getContainer()->get(AnaliseDeInteligenciaRepository::class);

        self::assertSame(1530, $repo->somarTokensDoSolicitanteNosEscritorios((int) $ana->getId(), [(int) $escritorioA->getId()]));
        self::assertSame(0, $repo->somarTokensDoSolicitanteNosEscritorios((int) $ana->getId(), [(int) $escritorioB->getId()]), 'a pessoa não gastou no outro escritório');
        self::assertSame(0, $repo->somarTokensDoSolicitanteNosEscritorios((int) $ana->getId(), []));
        self::assertSame(14000, $repo->somarTokensDoSolicitanteNosEscritorios((int) $bia->getId(), [(int) $escritorioB->getId()]));
    }

    #[TestDox('o chat soma só a resposta do assistente da própria pessoa, inclusive interrompida e refeita')]
    public function testSomaDoChat(): void
    {
        self::bootKernel();
        [$ana, $escritorio] = $this->criarAdmin();
        [$bia, $outro] = $this->criarAdmin();
        $daAna = $this->conversa($escritorio, $ana);
        $daBia = $this->conversa($outro, $bia);

        $this->resposta($daAna, 500, 100);
        $this->resposta($daAna, 400, 50, interrompida: true);
        $this->resposta($daAna, 300, 20, substituida: true);
        $this->em()->persist(MensagemDeChat::doUsuario($daAna, 'pergunta', null, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->resposta($daBia, 9000, 9000);

        $repo = static::getContainer()->get(MensagemDeChatRepository::class);

        self::assertSame(1370, $repo->somarTokensDoAssistenteDaPessoaNosEscritorios((int) $ana->getId(), [(int) $escritorio->getId()]));
        self::assertSame(0, $repo->somarTokensDoAssistenteDaPessoaNosEscritorios((int) $ana->getId(), [(int) $outro->getId()]));
        self::assertSame(0, $repo->somarTokensDoAssistenteDaPessoaNosEscritorios((int) $ana->getId(), []));
    }

    #[TestDox('ConsumoEmTokens soma análises e chat só nos escritórios sem plano pago que recebe; sem escritório, zero')]
    public function testUsadoSomaAsDuasOrigens(): void
    {
        self::bootKernel();
        [$ana, $escritorio] = $this->criarAdmin();
        [, $pago] = $this->criarAdmin();
        $this->analise($escritorio, $ana, 1000, 0);
        $this->resposta($this->conversa($escritorio, $ana), 100, 10);
        $this->analise($pago, $ana, 5000, 5000);

        $sut = $this->sut();

        self::assertSame(1110, $sut->usado($ana, [(int) $escritorio->getId()]));
        self::assertSame(0, $sut->usado($ana, []));
        self::assertSame('tokens', $sut->unidade());
        self::assertSame(100_000, $sut->franquia());
        self::assertSame(100_000, $sut->porColega());
    }
}
