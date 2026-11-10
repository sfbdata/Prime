<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\LotacaoComVigencia;
use App\Ponto\UseCase\ApagarLotacoesQueSairamUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaEscritorioSemProcessoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Apagar lotação na aba "Lotações" da edição do escritório (spec `docs/specs/ponto-desenho-0811.md`, E6: decisão do
 * Coordenador de 09/11/2026). A lotação com feriado ou com versão no histórico de lotações do Ponto não é apagada,
 * porque os meses passados mudariam; a tela avisa com a frase da recusa e o resto do envio continua valendo. A lotação
 * sem nada apaga como antes.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(ApagarLotacoesQueSairamUseCase::class)]
#[Group('ponto')]
final class ApagarLotacaoComHistoricoTest extends JusPrimeWebTestCase
{
    use CriaEscritorioSemProcessoTrait;

    private const RECUSA = 'Esta lotação tem feriados ou histórico no Ponto e não pode ser apagada.';

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $paula;
    private Lotacao $maceio;
    private Lotacao $brasilia;
    private Lotacao $recife;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tenant = $this->novoEscritorio();
        $this->paula = $this->novaPessoa($this->tenant, 'Paula Primária', $this->novoPerfil($this->tenant, true));
        $carla = $this->novaPessoa($this->tenant, 'Carla', $this->novoPerfil($this->tenant, false));
        $this->maceio = $this->novaLotacao('MACEIÓ/AL');
        $this->brasilia = $this->novaLotacao('BRASÍLIA/DF');
        $this->recife = $this->novaLotacao('RECIFE/PE');

        // Maceió tem um feriado. Brasília está só no histórico: era a lotação de Carla, que hoje ficou sem lotação.
        $this->em()->persist((new Feriado())->setNome('Emancipação de Alagoas')->setData(new \DateTime('2026-09-16'))
            ->setRecorrente(true)->setTenant($this->tenant)->setLotacao($this->maceio));
        $this->em()->persist(LotacaoComVigencia::inicial($carla, $this->tenant, $this->brasilia));
        $this->em()->persist(LotacaoComVigencia::aPartirDe($carla, $this->tenant, null, new \DateTimeImmutable('today')));
        $this->em()->flush();
    }

    #[TestDox('Apagar a lotação com feriado é recusado com a frase; a lotação e o feriado ficam')]
    public function testComFeriadoERecusada(): void
    {
        $this->salvarSem(['MACEIÓ/AL']);

        $this->assertRecusada();
        self::assertSame(['BRASÍLIA/DF', 'MACEIÓ/AL', 'RECIFE/PE'], $this->nomesNoBanco());
        self::assertSame([$this->maceio->getId()], $this->lotacoesDoFeriado(), 'o feriado continua com a lotação');
    }

    #[TestDox('Apagar a lotação que está no histórico de alguém é recusado com a frase; a lotação e o histórico ficam')]
    public function testComHistoricoERecusada(): void
    {
        $this->salvarSem(['BRASÍLIA/DF']);

        $this->assertRecusada();
        self::assertSame(['BRASÍLIA/DF', 'MACEIÓ/AL', 'RECIFE/PE'], $this->nomesNoBanco());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM lotacao_vigencia WHERE lotacao_id = ?', [$this->brasilia->getId()]));
    }

    #[TestDox('A lotação sem feriado nem histórico apaga como antes, sem a frase da recusa')]
    public function testSemNadaApaga(): void
    {
        $semNada = $this->novaLotacao('ARACAJU/SE');

        $this->salvarSem(['ARACAJU/SE']);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Lotações atualizadas com sucesso!');
        self::assertSelectorTextNotContains('body', self::RECUSA);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM lotacao WHERE id = ?', [$semNada->getId()]));
        self::assertSame(['BRASÍLIA/DF', 'MACEIÓ/AL', 'RECIFE/PE'], $this->nomesNoBanco());
    }

    #[TestDox('As outras alterações do mesmo envio valem: renomear, criar e apagar a que não tem nada')]
    public function testOResteDoEnvioVale(): void
    {
        $this->novaLotacao('ARACAJU/SE');

        $this->salvarSem(['MACEIÓ/AL', 'ARACAJU/SE'], ['RECIFE/PE' => 'RECIFE/PE (CENTRO)'], 'SALVADOR/BA');

        $this->assertRecusada();
        self::assertSame(['BRASÍLIA/DF', 'MACEIÓ/AL', 'RECIFE/PE (CENTRO)', 'SALVADOR/BA'], $this->nomesNoBanco());
    }

    // ----------------------------------------------------------------- apoio

    private function assertRecusada(): void
    {
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', self::RECUSA);
        $this->em()->clear();
        foreach ([$this->maceio, $this->brasilia] as $lotacao) {
            self::assertSame(
                $this->tenant->getId(),
                (int) $this->em()->getConnection()->fetchOne('SELECT tenant_id FROM lotacao WHERE id = ?', [$lotacao->getId()]),
                'a lotação com feriado ou histórico continua no escritório',
            );
        }
    }

    /**
     * Envia a aba como a tela: tira as linhas pelo nome, renomeia e acrescenta uma nova.
     *
     * @param list<string>          $tirar
     * @param array<string, string> $renomear
     */
    private function salvarSem(array $tirar, array $renomear = [], ?string $nova = null): void
    {
        $this->logarComTenant($this->client, $this->paula, $this->tenant);
        $url = sprintf('/tenant/%d/edit', $this->tenant->getId());
        // O primeiro request usa o gerenciador do teste: sem limpar, o escritório viria com a coleção da memória.
        $this->em()->clear();
        $valores = $this->client->request('GET', $url)->filter('form[name=tenant_lotacoes]')->form()->getPhpValues();
        self::assertCount(\count($this->nomesNoBanco()), $valores['tenant_lotacoes']['lotacoes'] ?? [], 'a tela traz uma linha por lotação');
        foreach ($valores['tenant_lotacoes']['lotacoes'] as $chave => $linha) {
            if (\in_array($linha['nome'], $tirar, true)) {
                unset($valores['tenant_lotacoes']['lotacoes'][$chave]);
            } elseif (isset($renomear[$linha['nome']])) {
                $valores['tenant_lotacoes']['lotacoes'][$chave]['nome'] = $renomear[$linha['nome']];
            }
        }
        if ($nova !== null) {
            $valores['tenant_lotacoes']['lotacoes'][99] = ['nome' => $nova];
        }
        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);
    }

    private function novaLotacao(string $nome): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($this->tenant);
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    /** @return list<string> */
    private function nomesNoBanco(): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT nome FROM lotacao WHERE tenant_id = ? ORDER BY nome', [$this->tenant->getId()]);
    }

    /** @return list<int> */
    private function lotacoesDoFeriado(): array
    {
        return array_map('intval', $this->em()->getConnection()->fetchFirstColumn(
            'SELECT lotacao_id FROM feriado WHERE tenant_id = ? AND lotacao_id IS NOT NULL',
            [$this->tenant->getId()],
        ));
    }
}
