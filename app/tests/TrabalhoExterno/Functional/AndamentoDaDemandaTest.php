<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\TrabalhoExternoController;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * D10 do desenho 06 de 24/10: cada mudança de situação e registro da demanda avisa as partes (quem pediu
 * e o convidado vigente), nunca quem agiu nem a Central; a tela aberta lê os avisos novos pela
 * `/api/andamentos` (o toque e o toast "Andamento · {ID}"), só os da própria pessoa.
 */
#[CoversClass(TrabalhoExternoController::class)]
#[CoversClass(AvisosDoTrabalhoExterno::class)]
final class AndamentoDaDemandaTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const API = '/trabalho-externo/api';

    #[TestDox('do envio à execução: quem pediu recebe cada andamento; colega, Master e outro escritório não; a tela lê os novos depois do cursor')]
    public function testSolicitanteRecebeEATelaLe(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        [$escritorio, $central, $outro] = [$this->criarTenant('Solicitante'), $this->criarTenant('Central'), $this->criarTenant('Outro')];
        [$solicitante, $colega] = [$this->criarUsuario($escritorio), $this->criarUsuario($escritorio)];
        $master = $this->criarMaster($central);
        $deFora = $this->criarUsuario($outro);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        self::assertSame([], $this->avisosDe($solicitante), 'quem enviou não se avisa');
        $cursor = $this->andamentos($client)['cursor'];

        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, self::API . "/demandas/$codigo/acoes/analise");
        self::assertResponseIsSuccessful();

        $avisos = $this->avisosDe($solicitante);
        self::assertSame([[
            'titulo' => "$codigo · Audiência",
            'mensagem' => 'Andamento: Em análise',
            'url' => "/trabalho-externo?te=$codigo",
            'tenant_id' => (int) $escritorio->getId(),
        ]], $avisos);
        self::assertSame([[], [], []], [$this->avisosDe($colega), $this->avisosDe($master), $this->avisosDe($deFora)], 'só quem pediu; a Central e outro escritório nunca');

        $this->postarJson($client, self::API . "/demandas/$codigo/propostas", ['valor' => '300']);
        self::assertSame(['Andamento: Em análise', 'Andamento: Aguardando aceite', 'Proposta v1 enviada: R$ 300,00.'], array_column($this->avisosDe($solicitante), 'mensagem'), 'um aviso por andamento, sem segurar o pendente igual');

        $this->logarComTenant($client, $solicitante, $escritorio);
        $novos = $this->andamentos($client, $cursor);
        self::assertSame([$codigo, $codigo, $codigo], array_column($novos['andamentos'], 'codigo'));
        self::assertSame("$codigo · Audiência", $novos['andamentos'][0]['titulo']);
        self::assertSame('Andamento: Em análise', $novos['andamentos'][0]['texto']);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $novos['andamentos'][0]['hora']);
        self::assertSame([], $this->andamentos($client, $novos['cursor'])['andamentos'], 'o cursor avançou');

        $this->postarJson($client, self::API . "/demandas/$codigo/acoes/aceitar");
        self::assertSame([], $this->avisosDe($master), 'o aceite do solicitante não vai para a Central por aqui');
        self::assertCount(3, $this->avisosDe($solicitante), 'quem aceitou não se avisa');

        $this->logarComTenant($client, $deFora, $outro);
        self::assertSame([], $this->andamentos($client, 0)['andamentos'], 'outro escritório não lê os avisos de ninguém');
    }

    #[TestDox('com convidado vigente: o andamento do Master vai a quem pediu e ao convidado; o do convidado só a quem pediu; revogado não recebe mais')]
    public function testConvidado(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        [$escritorio, $central] = [$this->criarTenant('Solicitante'), $this->criarTenant('Central')];
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($central);
        $codigo = $this->emExecucao($client, $solicitante, $escritorio, $master, $central);

        $this->logarComTenant($client, $master, $central);
        $token = $this->convidar($client, $codigo, 'parte@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'parte@test.com');
        $antes = count($this->avisosDe($solicitante));

        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, self::API . "/demandas/$codigo/andamentos", ['texto' => 'Protocolo feito']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Protocolo feito'], array_column($this->avisosDe($convidado), 'mensagem'));
        self::assertSame((int) $escritorio->getId(), $this->avisosDe($convidado)[0]['tenant_id'], 'convidado sem escritório: no da demanda, só dele');
        self::assertCount($antes + 1, $this->avisosDe($solicitante));

        $client->getCookieJar()->clear();
        $client->loginUser($convidado);
        $this->marcarTermosAceitos($client);
        self::assertSame(['Protocolo feito'], array_column($this->andamentos($client, 0)['andamentos'], 'texto'), 'a tela do convidado lê os dele');
        $this->postarJson($client, self::API . "/demandas/$codigo/andamentos", ['texto' => 'Cheguei ao fórum']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Protocolo feito'], array_column($this->avisosDe($convidado), 'mensagem'), 'quem agiu não se avisa');
        self::assertSame('Cheguei ao fórum', end($this->avisosDe($solicitante))['mensagem'] ?? null);
        self::assertSame([], $this->avisosDe($master));

        $this->logarComTenant($client, $master, $central);
        $conviteId = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM trabalho_externo_convite WHERE convidado_id = ?', [$convidado->getId()]);
        $this->postarJson($client, self::API . "/demandas/$codigo/convites/$conviteId/revogar");
        self::assertResponseIsSuccessful();
        $this->postarJson($client, self::API . "/demandas/$codigo/andamentos", ['texto' => 'Depois da revogação']);
        self::assertSame(['Protocolo feito'], array_column($this->avisosDe($convidado), 'mensagem'), 'revogado não recebe mais');
    }

    /** @return list<array{titulo: string, mensagem: ?string, url: ?string, tenant_id: int}> */
    private function avisosDe(User $usuario): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, mensagem, url, tenant_id FROM notificacao WHERE usuario_id = ? AND tipo = ? ORDER BY id',
            [$usuario->getId(), Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO],
        );

        return array_map(static fn (array $l): array => ['titulo' => $l['titulo'], 'mensagem' => $l['mensagem'], 'url' => $l['url'], 'tenant_id' => (int) $l['tenant_id']], $linhas);
    }

    /** @return array{cursor: int, andamentos: list<array<string, string>>} */
    private function andamentos(KernelBrowser $client, ?int $desde = null): array
    {
        $client->request('GET', self::API . '/andamentos' . ($desde !== null ? '?desde=' . $desde : ''));
        self::assertResponseIsSuccessful();

        return $this->json($client);
    }

    private function emExecucao(KernelBrowser $client, User $solicitante, \App\Entity\Tenant\Tenant $escritorio, User $master, \App\Entity\Tenant\Tenant $central): string
    {
        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, self::API . "/demandas/$codigo/acoes/analise");
        $this->postarJson($client, self::API . "/demandas/$codigo/propostas", ['valor' => '300']);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, self::API . "/demandas/$codigo/acoes/aceitar");
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, self::API . "/demandas/$codigo/acoes/iniciar");
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    /** Convida pelo endpoint real e devolve o token tirado do link do e-mail (como no ConviteDoTrabalhoExternoTest). */
    private function convidar(KernelBrowser $client, string $codigo, string $email): string
    {
        $this->postarJson($client, self::API . "/demandas/$codigo/convites", ['nome' => 'Profissional Convidado', 'email' => $email, 'funcao' => 'Advogado correspondente']);
        self::assertResponseIsSuccessful();
        $html = '';
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents() as $evento) {
            if (!$evento->isQueued() && $evento->getMessage() instanceof Email) {
                $html = (string) $evento->getMessage()->getHtmlBody();
            }
        }
        self::assertSame(1, preg_match('#/trabalho-externo/convite/([a-f0-9]{64})#', $html, $m));

        return $m[1];
    }

    private function contaDoConvidado(KernelBrowser $client, string $token, string $email): User
    {
        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', "/trabalho-externo/convite/$token");
        $client->request('POST', "/trabalho-externo/convite/$token/criar-conta", ['full_name' => 'Convidado', 'senha' => 'senha-forte-1', 'genero' => 'masculino', 'aceite_termos' => '1', '_token' => $crawler->filter('form[action$="/criar-conta"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
