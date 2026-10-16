<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\WhatsappDaDemandaController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/** Aba WhatsApp (F2): consentimento, fila por fase, envio manual e as mesmas barreiras de escopo. */
#[CoversClass(WhatsappDaDemandaController::class)]
final class WhatsappDaDemandaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    #[TestDox('conectar exige celular com DDD e consentimento; conectado, prepara as boas-vindas')]
    public function testConectar(): void
    {
        [$client, $codigo] = $this->demandaEnviada();
        $url = "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos";

        $this->postarJson($client, $url, ['nome' => 'Dr. Teste', 'telefone' => '9999', 'autorizou' => true]);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, $url, ['nome' => 'Dr. Teste', 'telefone' => '(82) 99999-0000', 'autorizou' => false]);
        self::assertSame('Confirme que o contato autorizou receber mensagens.', $this->json($client)['mensagem']);

        $wa = $this->postarJson($client, $url, ['nome' => 'Dr. Teste da Silva', 'telefone' => '(82) 99999-0000', 'autorizou' => true])['demanda']['whatsapp'];
        self::assertSame('(82) 99999-0000', $wa['contatos'][0]['tel']);
        self::assertCount(1, $wa['fila']);
        self::assertStringStartsWith("BlueJus · $codigo\nOlá, Dr.! A partir de agora", $wa['fila'][0]['txt']);
        self::assertStringContainsString('/trabalho-externo?te=' . $codigo, $wa['fila'][0]['txt']);
        self::assertStringStartsWith('https://wa.me/5582999990000?text=', $wa['fila'][0]['href']);
    }

    #[TestDox('cada mudança de fase prepara a mensagem para quem quer aquela fase; pausado não recebe')]
    public function testFilaPorFase(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEnviada();
        $wa = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos", ['nome' => 'Ana', 'telefone' => '61988887777', 'autorizou' => true])['demanda']['whatsapp'];
        $contato = $wa['contatos'][0]['id'];

        $this->logarComTenant($client, $master, $central);
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise")['demanda'];
        self::assertSame(['', 'Em análise'], array_column($d['whatsapp']['fila'], 'fase'));
        self::assertStringContainsString('Audiência em Brasília/DF: a sua demanda está em análise pela nossa equipe.', $d['whatsapp']['fila'][1]['txt']);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos/$contato/alternar");
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '100'])['demanda'];
        self::assertCount(2, $d['whatsapp']['fila'], 'pausado: a proposta não gerou aviso');

        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/avisar-fase-atual")['demanda'];
        self::assertCount(2, $d['whatsapp']['fila'], 'sem contato ativo, não há para quem avisar');
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos/$contato/alternar");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos/$contato/fases/proposta");
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/avisar-fase-atual")['demanda'];
        self::assertSame('Proposta enviada', end($d['whatsapp']['fila'])['fase'], '"avisar fase atual" vale mesmo com a fase desligada: é pedido explícito');
    }

    #[TestDox('enviar move para ENVIADAS; descartar só tira da fila')]
    public function testEnviarEDescartar(): void
    {
        [$client, $codigo] = $this->demandaEnviada();
        $wa = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos", ['nome' => 'Ana', 'telefone' => '61988887777', 'autorizou' => true])['demanda']['whatsapp'];
        $aviso = $wa['fila'][0]['id'];

        $wa = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/avisos/$aviso/enviado")['demanda']['whatsapp'];
        self::assertSame([], $wa['fila']);
        self::assertSame('Conexão', $wa['enviadas'][0]['fase']);

        $wa = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/avisar-fase-atual")['demanda']['whatsapp'];
        $wa = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/avisos/{$wa['fila'][0]['id']}/descartar")['demanda']['whatsapp'];
        self::assertSame([], $wa['fila']);
        self::assertCount(1, $wa['enviadas']);
    }

    #[TestDox('contato de outra demanda é 404, e outro escritório não chega à aba')]
    public function testEscopo(): void
    {
        [$client, $codigo, , , $solicitante, $escritorio] = $this->demandaEnviada();
        $outra = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $contato = $this->postarJson($client, "/trabalho-externo/api/demandas/$outra/whatsapp/contatos", ['nome' => 'Ana', 'telefone' => '61988887777', 'autorizou' => true])['demanda']['whatsapp']['contatos'][0]['id'];

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/whatsapp/contatos/$contato/remover");
        self::assertResponseStatusCodeSame(404);

        $b = $this->criarTenant('B');
        $this->logarComTenant($client, $this->criarUsuario($b), $b);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$outra/whatsapp/contatos/$contato/remover");
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string, 2: \App\Entity\Auth\User, 3: \App\Entity\Tenant\Tenant, 4: \App\Entity\Auth\User, 5: \App\Entity\Tenant\Tenant} */
    private function demandaEnviada(): array
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant('Solicitante');
        $central = $this->criarTenant('Central');
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($central);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        return [$client, $codigo, $master, $central, $solicitante, $escritorio];
    }
}
