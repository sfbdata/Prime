<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAcompanhamentoDesligado;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Pasta\Service\AvisoDePastaAcompanhada;
use App\Pasta\Service\QuemAcompanhaAPasta;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O aviso de "Acompanhar alterações" pela mensagem nova no Registro da pasta (`POST /pasta/{id}/mensagem`),
 * com o banco de verdade: o recorte do repositório (vínculo e usuário ativos, quem desligou, outro
 * escritório), a permissão de ver, a regra "uma ação, um aviso" e o limite de um não lido por pasta.
 */
#[CoversClass(AvisoDePastaAcompanhada::class)]
#[CoversClass(QuemAcompanhaAPasta::class)]
#[CoversClass(PastaAcompanhamentoDesligadoRepository::class)]
#[CoversClass(EnviarMensagemPastaUseCase::class)]
#[Group('pasta')]
final class PastaAcompanhamentoAvisoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /** @return array<string, mixed> o JSON do envio */
    private function enviar(Pasta $pasta, string $conteudo, ?int $respostaA = null): array
    {
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', array_filter([
            '_token'     => 'TOKEN_pasta_mensagem_' . $pasta->getId(),
            'conteudo'   => $conteudo,
            'resposta_a' => $respostaA,
        ]));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{titulo: string, url: string, tenant_id: int, lida: bool}> */
    private function avisos(User $usuario, string $tipo = Notificacao::TIPO_PASTA_ACOMPANHADA): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, url, tenant_id, lida FROM notificacao WHERE usuario_id = :u AND tipo = :t ORDER BY id',
            ['u' => $usuario->getId(), 't' => $tipo],
        );

        return array_map(static fn (array $l): array => [
            'titulo'    => (string) $l['titulo'],
            'url'       => (string) $l['url'],
            'tenant_id' => (int) $l['tenant_id'],
            'lida'      => (bool) $l['lida'],
        ], $linhas);
    }

    private function idDaMensagem(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT MAX(id) FROM pasta_mensagem');
    }

    #[TestDox('Mensagem nova avisa quem acompanha (padrão ligado), no escritório da pasta, com link para a mensagem; nunca o autor, quem desligou, quem não vê, o inativo ou o colega de outro escritório')]
    public function testMensagemAvisaQuemAcompanha(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana       = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno     = $this->criarColaborador($tenant, 'Bruno Leitor', ['modules.expediente.view', 'resources.pasta.view']);
        $carla     = $this->criarColaborador($tenant, 'Carla Desligou');
        $semAcesso = $this->criarColaborador($tenant, 'Sem Acesso', []);
        $inativo   = $this->criarColaborador($tenant, 'Davi Inativo');
        $inativo->setIsActive(false);
        [, $outro] = $this->criarEscritorio();
        $deFora    = $this->criarColaborador($outro, 'Eva de Fora');
        $pasta     = $this->criarPasta($tenant);
        $this->em()->persist(new PastaAcompanhamentoDesligado($tenant, $carla, $pasta));
        $this->em()->flush();

        $this->entrar($ana, $tenant);
        $this->enviar($pasta, 'Cliente ligou');
        $msg = $this->idDaMensagem();

        $esperado = [['titulo' => 'Nova mensagem na pasta ' . $pasta->getNup(), 'url' => '/pasta/' . $pasta->getId() . '#pasta-msg-' . $msg, 'tenant_id' => (int) $tenant->getId(), 'lida' => false]];
        self::assertSame($esperado, $this->avisos($bruno), 'quem só vê a pasta acompanha por padrão');
        self::assertSame($esperado, $this->avisos($primario));
        self::assertSame([], $this->avisos($ana), 'o autor não se avisa');
        self::assertSame([], $this->avisos($carla), 'quem desligou não recebe');
        self::assertSame([], $this->avisos($semAcesso), 'quem não vê a pasta não recebe');
        self::assertSame([], $this->avisos($inativo), 'usuário inativo não recebe');
        self::assertSame([], $this->avisos($deFora), 'colega de outro escritório nunca recebe');
    }

    #[TestDox('Uma ação, um aviso: na resposta com menção, o respondido fica com o de resposta, o mencionado com o de menção e só o terceiro recebe o de acompanhamento')]
    public function testRespondidoEMencionadoNaoDuplicam(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Respondido');
        $carla = $this->criarColaborador($tenant, 'Carla Mencionada');
        $pasta = $this->criarPasta($tenant);

        $this->entrar($bruno, $tenant);
        $this->enviar($pasta, 'Preciso do RG do cliente');
        $raiz = $this->idDaMensagem();
        // O comentário do Bruno já avisou a todos: lido, para o próximo poder avisar de novo.
        $this->em()->getConnection()->executeStatement('UPDATE notificacao SET lida = true WHERE tipo = :t', ['t' => Notificacao::TIPO_PASTA_ACOMPANHADA]);

        $this->entrar($ana, $tenant);
        $this->enviar($pasta, 'Pedi o RG, @[Carla](user:' . $carla->getId() . ') confere?', $raiz);

        self::assertCount(1, $this->avisos($bruno, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO));
        self::assertCount(1, $this->avisos($carla, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
        self::assertSame([], array_filter($this->avisos($bruno), static fn (array $a): bool => !$a['lida']), 'o respondido não ganha o de acompanhamento');
        self::assertSame([], array_filter($this->avisos($carla), static fn (array $a): bool => !$a['lida']), 'a mencionada não ganha o de acompanhamento');
        self::assertSame([], array_filter($this->avisos($ana), static fn (array $a): bool => !$a['lida']), 'o autor não se avisa');
        self::assertCount(1, array_filter($this->avisos($primario), static fn (array $a): bool => !$a['lida']), 'o terceiro recebe');
    }

    #[TestDox('No máximo um aviso NÃO LIDO por pasta e pessoa: a segunda mensagem não cria outro; lido o primeiro, a próxima volta a avisar')]
    public function testNaoCriaSegundoAvisoEnquantoNaoLido(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);
        $outra = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);

        $this->enviar($pasta, 'Primeira');
        $this->enviar($pasta, 'Segunda');
        self::assertCount(1, $this->avisos($bruno), 'o primeiro ainda não foi lido');

        $this->enviar($outra, 'Em outra pasta');
        self::assertCount(2, $this->avisos($bruno), 'a deduplicação é por pasta');

        $this->em()->getConnection()->executeStatement('UPDATE notificacao SET lida = true WHERE usuario_id = :u', ['u' => $bruno->getId()]);
        $this->enviar($pasta, 'Terceira');
        self::assertCount(3, $this->avisos($bruno), 'lido o anterior, a próxima mensagem volta a avisar');
    }

    #[TestDox('Quem desliga pelo menu ⋮ deixa de receber; ao religar, volta a receber')]
    public function testDesligarPeloMenuParaDeAvisar(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);

        $this->entrar($bruno, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acompanhar', ['_token' => 'TOKEN_pasta_acompanhar_' . $pasta->getId()]);
        self::assertSame('{"acompanha":false}', (string) $this->client->getResponse()->getContent());

        $this->entrar($ana, $tenant);
        $this->enviar($pasta, 'Ninguém acompanha?');
        self::assertSame([], $this->avisos($bruno));

        $this->entrar($bruno, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acompanhar', ['_token' => 'TOKEN_pasta_acompanhar_' . $pasta->getId()]);
        $this->entrar($ana, $tenant);
        $this->enviar($pasta, 'Agora sim');
        self::assertCount(1, $this->avisos($bruno));
    }
}
