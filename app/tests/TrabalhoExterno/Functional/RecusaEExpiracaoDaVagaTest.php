<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\CandidaturasController;
use App\TrabalhoExterno\Controller\VagasController;
use App\TrabalhoExterno\Entity\Vaga;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Desenho 06 de 24/10 pela HTTP: a recusa da oferta (D11b, l.1169) e a vaga expirada com o corte de 30 dias
 * (D4, l.1063, 1124). A recusa é de quem recebeu a oferta, uma por vaga e pessoa, avisa o dono e os Master e
 * sobrevive ao "Ofertar novamente"; a vaga expirada há mais de 30 dias sai da lista de todos.
 */
#[CoversClass(CandidaturasController::class)]
#[CoversClass(VagasController::class)]
final class RecusaEExpiracaoDaVagaTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const VAGAS = '/trabalho-externo/api/vagas';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    #[TestDox('recusar: toast do desenho, "Oferta recusada por {quem}", botão "recusou", sem repetir; aviso aos Master e não a quem recusou; desfazer e aceitar mesmo assim; "Ofertar novamente" não zera')]
    public function testRecusarEDesfazer(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        $this->definirSede($sede);
        $codigo = $this->publicar($master, $sede, self::oferta('Correspondente'));
        [$doA, $doB] = [$this->comCurriculo($a, 'Ana'), $this->comCurriculo($b, 'Bia')];

        $this->logarComTenant($this->client, $doA, $a);
        self::assertSame('aceitar', $this->detalhe($codigo)['botaoExterno']);
        $r = $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa");
        self::assertResponseIsSuccessful();
        self::assertSame(['titulo' => 'Oferta recusada', 'texto' => 'Obrigado por responder. Você continua recebendo novas oportunidades.'], $r['toast']);
        self::assertSame('recusou', $r['vaga']['botaoExterno']);
        self::assertSame('recusou', $this->detalhe($codigo)['botaoExterno'], 'a recusa fica gravada');
        $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa");
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contar('trabalho_externo_vaga_recusa'), 'uma por vaga e pessoa');

        $this->logarComTenant($this->client, $master, $sede);
        $historico = array_column($this->detalhe($codigo)['historico'], 'txt');
        self::assertSame('Oferta recusada por ' . $doA->getFullName(), $historico[0]);
        self::assertCount(1, array_filter($historico, static fn (string $t): bool => str_starts_with($t, 'Oferta recusada')), 'recusar de novo não repete o histórico');
        self::assertSame(1, $this->avisos($master), 'o Master da Central recebe no sino');
        self::assertSame(0, $this->avisos($doA), 'quem recusou não se avisa');
        self::assertSame(0, $this->avisos($doB));

        $this->logarComTenant($this->client, $doA, $a);
        self::assertSame(['ok' => true], $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa/desfazer"));
        self::assertSame(0, $this->contar('trabalho_externo_vaga_recusa'));
        self::assertSame('aceitar', $this->detalhe($codigo)['botaoExterno']);
        $aceite = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertSame('Oferta aceita', $aceite['toast']['titulo'], '"aceitar mesmo assim" candidata depois de desfazer');
        $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa");
        self::assertResponseStatusCodeSame(409, 'quem já aceitou não recusa');

        $this->logarComTenant($this->client, $doB, $b);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa");
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/acoes/encerrar");
        $this->postarJson($this->client, self::VAGAS . "/$codigo/acoes/ofertar");
        self::assertResponseIsSuccessful();
        $this->logarComTenant($this->client, $doB, $b);
        self::assertSame('recusou', $this->detalhe($codigo)['botaoExterno'], 'P7: "Ofertar novamente" não zera a recusa');
    }

    #[TestDox('recusar na vaga de escritório: os Solicitantes do escritório dono e os Master recebem; quem recusou não')]
    public function testAvisoAoDono(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        $this->definirSede($sede);
        $dono = $this->criarUsuario($a, nome: 'Dono');
        $codigo = $this->divulgarEAprovar($dono, $a, $master, $sede);
        $doB = $this->comCurriculo($b, 'Bia');

        $this->logarComTenant($this->client, $doB, $b);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa");
        self::assertResponseIsSuccessful();
        self::assertSame([1, 1, 0], [$this->avisos($dono), $this->avisos($master), $this->avisos($doB)]);
        self::assertSame('Oferta recusada por ' . $doB->getFullName(), $this->em()->getConnection()->fetchOne("SELECT mensagem FROM notificacao WHERE usuario_id = ? AND tenant_id = ? AND tipo = 'trabalho_externo_oferta_recusada'", [$dono->getId(), $a->getId()]), 'no escritório da vaga');
    }

    #[TestDox('recusar e desfazer: interno (Master, Equipe e ?como=, que a escrita não lê) e o dono levam 403; vaga de outro escritório ainda pendente e código inexistente, 404; nada gravado')]
    public function testPermissoes(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        $this->definirSede($sede);
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');
        $dono = $this->criarUsuario($a, nome: 'Dono');
        $doB = $this->comCurriculo($b, 'Bia');
        $codigo = $this->divulgarEAprovar($dono, $a, $master, $sede);

        foreach ([[$master, $sede, ''], [$equipe, $sede, ''], [$master, $sede, '?como=solicitante'], [$dono, $a, '']] as [$quem, $onde, $como]) {
            $this->logarComTenant($this->client, $quem, $onde);
            $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa$como");
            self::assertResponseStatusCodeSame(403, $quem->getFullName() . $como);
        }
        $this->logarComTenant($this->client, $master, $sede);
        foreach (['', '?como=solicitante'] as $como) {
            $this->postarJson($this->client, self::VAGAS . "/$codigo/recusa/desfazer$como");
            self::assertResponseStatusCodeSame(403, 'a escrita não lê ?como=: segue Master, que não desfaz' . $como);
        }

        $this->logarComTenant($this->client, $dono, $a);
        $pendente = $this->postarJson($this->client, self::VAGAS, self::oferta('Pendente do A'))['codigo'];
        $this->logarComTenant($this->client, $doB, $b);
        $this->postarJson($this->client, self::VAGAS . "/$pendente/recusa");
        self::assertResponseStatusCodeSame(404, 'a vaga de outro escritório ainda não publicada não existe para ele');
        $this->postarJson($this->client, self::VAGAS . "/$pendente/recusa/desfazer");
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($this->client, self::VAGAS . '/VG-999999/recusa');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->contar('trabalho_externo_vaga_recusa'));
    }

    #[TestDox('corte de 30 dias: o externo vê a aberta e a expirada há até 30 dias (véu, "Expirou em", selo Expirada, sem AO VIVO); o interno vê a pendente; a expirada há mais de 30 dias sai da lista de todos')]
    public function testListagemComOCorte(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        $this->definirSede($sede);
        $dono = $this->criarUsuario($a, nome: 'Dono');
        $doB = $this->criarUsuario($b, nome: 'Bia');

        $aberta = $this->publicar($master, $sede, self::comum('Aberta'));
        $recente = $this->publicar($master, $sede, self::comum('Expirou há 5 dias'));
        $velha = $this->publicar($master, $sede, self::comum('Expirou há 31 dias'));
        $encerradaVelha = $this->publicar($master, $sede, self::comum('Encerrada há 40 dias'));
        $this->postarJson($this->client, self::VAGAS . "/$encerradaVelha/acoes/encerrar");
        $this->logarComTenant($this->client, $dono, $a);
        $pendente = $this->postarJson($this->client, self::VAGAS, self::comum('Pendente'))['codigo'];

        $sql = 'UPDATE trabalho_externo_vaga SET inscricoes_ate = ? WHERE id = ?';
        $conexao = $this->em()->getConnection();
        $cincoDias = new \DateTimeImmutable('-5 days');
        $conexao->executeStatement($sql, [$cincoDias->format('Y-m-d'), Vaga::idDoCodigo($recente)]);
        $conexao->executeStatement($sql, [(new \DateTimeImmutable('-31 days'))->format('Y-m-d'), Vaga::idDoCodigo($velha)]);
        $conexao->executeStatement($sql, [(new \DateTimeImmutable('-60 days'))->format('Y-m-d'), Vaga::idDoCodigo($pendente)]);
        $conexao->executeStatement('UPDATE trabalho_externo_vaga SET encerrada_em = ? WHERE id = ?', [(new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'), Vaga::idDoCodigo($encerradaVelha)]);

        $this->logarComTenant($this->client, $doB, $b);
        $externo = $this->porCodigo();
        self::assertSame([$recente, $aberta], array_values(array_intersect(array_keys($externo), [$aberta, $recente, $velha, $encerradaVelha, $pendente])));
        self::assertSame([false, '', false], [$externo[$aberta]['expOn'], $externo[$aberta]['expTxt'], $externo[$aberta]['expRec']]);
        $cartao = $externo[$recente];
        self::assertSame([true, 'Expirou em ' . $cincoDias->format('d/m/Y'), true], [$cartao['expOn'], $cartao['expTxt'], $cartao['expRec']]);
        self::assertSame(['Expirada', '#6b8494', false, false], [$cartao['situacaoRotulo'], $cartao['situacaoCor'], $cartao['vivo'], $cartao['nova']]);

        $this->logarComTenant($this->client, $master, $sede);
        $interno = $this->porCodigo();
        foreach ([$aberta, $recente, $pendente] as $c) {
            self::assertArrayHasKey($c, $interno, $c);
        }
        self::assertArrayNotHasKey($velha, $interno, 'P3: o corte vale para o Master');
        self::assertArrayNotHasKey($encerradaVelha, $interno, 'a encerrada há mais de 30 dias sai inclusive do filtro Encerradas');
        self::assertTrue($interno[$recente]['expOn']);
        self::assertFalse($interno[$recente]['expRec'], 'expRec é só do externo');
        self::assertFalse($interno[$pendente]['expOn'], 'a pendente com o prazo vencido não expira');
        self::assertSame(5, (int) $conexao->fetchOne('SELECT COUNT(*) FROM trabalho_externo_vaga'), 'o dado continua no banco');

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::VAGAS . "/$recente");
        self::assertResponseIsSuccessful('o interno abre a expirada normalmente');
    }

    /** @return array<string, array<string, mixed>> os cartões da lista de quem está logado, por código */
    private function porCodigo(): array
    {
        $this->client->request('GET', self::VAGAS);
        self::assertResponseIsSuccessful();
        $vagas = $this->json($this->client)['vagas']['vagas'];

        return array_combine(array_column($vagas, 'codigo'), $vagas);
    }

    /** @param array<string, mixed> $vaga */
    private function publicar(User $master, Tenant $sede, array $vaga): string
    {
        $this->logarComTenant($this->client, $master, $sede);
        $codigo = $this->postarJson($this->client, self::VAGAS, $vaga)['codigo'];
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    /** A oferta do escritório, aprovada pelo Master com o tempo padrão de 60 minutos. */
    private function divulgarEAprovar(User $dono, Tenant $escritorio, User $master, Tenant $sede): string
    {
        $this->logarComTenant($this->client, $dono, $escritorio);
        $codigo = $this->postarJson($this->client, self::VAGAS, self::oferta('Correspondente do A'))['codigo'];
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/acoes/aprovar");
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    private function comCurriculo(Tenant $tenant, string $nome): User
    {
        $usuario = $this->criarUsuario($tenant, nome: $nome);
        $this->logarComTenant($this->client, $usuario, $tenant);
        $this->postarJson($this->client, '/trabalho-externo/api/curriculos/meu', [
            'nome' => $nome, 'email' => 'cv@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv',
            'oabNum' => '12345', 'oabUf' => 'DF', 'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF',
            'areas' => ['Cível'], 'resumo' => 'Atuação cível.', 'lgpd' => true,
        ]);
        self::assertResponseIsSuccessful();

        return $usuario;
    }

    private function avisos(User $usuario): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tipo = 'trabalho_externo_oferta_recusada'", [$usuario->getId()]);
    }

    private function contar(string $tabela): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
    }

    /** @return array<string, mixed> */
    private function detalhe(string $codigo): array
    {
        $this->client->request('GET', self::VAGAS . "/$codigo");
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['vaga'];
    }

    /** @return array<string, mixed> */
    private static function oferta(string $titulo): array
    {
        return self::comum($titulo) + ['oferta' => true, 'duracaoDaOferta' => 60, 'qtd' => 2];
    }

    /** @return array<string, mixed> */
    private static function comum(string $titulo): array
    {
        return ['titulo' => $titulo, 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'periodoDias' => 0, 'ofertante' => ['nome' => 'Escritório que oferece']];
    }
}
