<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeCarta;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDaConferencia;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Email\IdentidadeDeEmail;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use App\Tests\Shared\Doubles\MailerQueGuarda;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A bancada dos testes unitários dos UseCases da etapa 2: os repositórios do domínio dublados (`createMock`, nunca o
 * EntityManager) e os serviços de verdade por cima deles (trilha, correio, apresentação, página, chave), com dublês
 * para o que não é do domínio (Mailer que guarda, logger em memória, disco em memória, relógio parado, Twig com moldes
 * mínimos e o gerador de URL do Symfony com as rotas da Assinatura).
 *
 * O "banco" é a lista `$naBase`: o repositório acha documento por id e escritório e signatário pelo hash do token
 * nela. A trava devolve verdadeiro (ou falso com `$documentoSumiu`) e roda `$aoTravar`, que simula o que outra ação
 * gravou entre a leitura e a trava. Os eventos gravados ficam em `$gravados`, na ordem.
 *
 * A classe que usa estende `TestCase` e chama `montarOficina()` no `setUp()`.
 */
trait OficinaDosUseCasesDaAssinatura
{
    private const SEGREDO = 'segredo-de-teste-com-32-bytes-ou-mais-0123';
    private const OUTRO_SEGREDO = 'outro-segredo-de-teste-com-32-bytes-0123456';
    private const AGORA = '2026-11-09 10:00:00';
    private const CPF_ANA = '52998224725';
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private DocumentoParaAssinarRepository&MockObject $documentos;
    private EventoDaTrilhaRepository&MockObject $eventosDaTrilha;
    private Tenant $tenant;
    private MockClock $relogio;
    private ChaveDaAssinatura $chave;
    private MailerQueGuarda $mailer;
    private LoggerEmMemoria $logger;
    private ArmazenamentoEmMemoria $disco;

    /** @var list<DocumentoParaAssinar> */
    private array $naBase = [];

    /** @var list<EventoDaTrilha> */
    private array $gravados = [];

    /** @var array<string, array{0: int, 1: ?\DateTimeImmutable}> a contagem da trilha por tipo de evento */
    private array $contagens = [];

    private ?\DateTimeImmutable $ultimoLembrete = null;
    private ?User $quemEnviou = null;
    private bool $documentoSumiu = false;

    /** @var (\Closure(DocumentoParaAssinar): void)|null */
    private ?\Closure $aoTravar = null;

    /** Quando preenchida, `emTransacaoComArquivoNovo` passa pela transação REAL (com os arquivos novos), não só pelo trabalho. */
    private ?TransacaoComArquivoNovo $transacaoReal = null;

    private function montarOficina(): void
    {
        $this->tenant = (new Tenant())->setName("Silva & D'Ávila Advogados");
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 9);
        $this->relogio = new MockClock(new \DateTimeImmutable(self::AGORA));
        $this->chave = new ChaveDaAssinatura(self::SEGREDO);
        $this->mailer = new MailerQueGuarda();
        $this->logger = new LoggerEmMemoria();
        $this->disco = new ArmazenamentoEmMemoria();
        $this->dublarDocumentos();
        $this->dublarEventos();
    }

    private function dublarDocumentos(): void
    {
        $this->documentos = $this->createMock(DocumentoParaAssinarRepository::class);
        $this->documentos->method('emTransacao')->willReturnCallback(static fn (callable $trabalho): mixed => $trabalho());
        $this->documentos->method('emTransacaoComArquivoNovo')->willReturnCallback(
            fn (callable $trabalho, \Closure $arquivosNovos, string $contexto): mixed => $this->transacaoReal === null ? $trabalho() : $this->transacaoReal->executar($trabalho, $arquivosNovos, $contexto),
        );
        $this->documentos->method('doEscritorioMesmoNaLixeira')->willReturnCallback(fn (int $id, Tenant $tenant): ?DocumentoParaAssinar => $this->achar(
            static fn (DocumentoParaAssinar $d): bool => $d->getId() === $id && $d->getTenant() === $tenant,
        ));
        $this->documentos->method('signatarioPeloTokenHash')->willReturnCallback(function (string $hash): ?Signatario {
            foreach ($this->naBase as $documento) {
                foreach ($documento->getSignatarios() as $s) {
                    if ($s->getTokenHash() === $hash) {
                        return $s;
                    }
                }
            }

            return null;
        });
        $this->documentos->method('travarERecarregar')->willReturnCallback(function (DocumentoParaAssinar $d, Tenant $tenant): bool {
            self::assertSame($d->getTenant(), $tenant, 'a trava usa o escritório do próprio documento');
            if ($this->documentoSumiu) {
                return false;
            }
            if ($this->aoTravar !== null) {
                ($this->aoTravar)($d);
            }

            return true;
        });
    }

    private function dublarEventos(): void
    {
        $this->eventosDaTrilha = $this->createMock(EventoDaTrilhaRepository::class);
        $this->eventosDaTrilha->method('ultimoHashTravando')->willReturn(null);
        $this->eventosDaTrilha->method('salvar')->willReturnCallback(function (EventoDaTrilha $evento): void {
            $this->gravados[] = $evento;
        });
        $this->eventosDaTrilha->method('contarDoSignatarioDesde')->willReturnCallback(
            fn (Signatario $s, TipoDeEvento $tipo): array => $this->contagens[$tipo->value] ?? [0, null],
        );
        $this->eventosDaTrilha->method('ultimoDoDocumento')->willReturnCallback(fn (): ?\DateTimeImmutable => $this->ultimoLembrete);
        $this->eventosDaTrilha->method('quemEnviou')->willReturnCallback(fn (): ?User => $this->quemEnviou);
        $this->eventosDaTrilha->method('doDocumento')->willReturnCallback(fn (): array => array_reverse($this->gravados));
    }

    /** @param \Closure(DocumentoParaAssinar): bool $criterio */
    private function achar(\Closure $criterio): ?DocumentoParaAssinar
    {
        foreach ($this->naBase as $documento) {
            if ($criterio($documento)) {
                return $documento;
            }
        }

        return null;
    }

    private function trilha(): TrilhaDoDocumento
    {
        return new TrilhaDoDocumento($this->eventosDaTrilha, $this->relogio);
    }

    private function correio(): CorreioDaAssinatura
    {
        return new CorreioDaAssinatura(
            $this->mailer,
            self::twig(),
            new IdentidadeDeEmail('naoresponda@bluejus.test', ''),
            new IdentityTranslator(),
            $this->documentos,
            $this->trilha(),
            $this->logger,
        );
    }

    private function apresentacao(): ApresentacaoDoDocumento
    {
        // Etapa 5 (5A): sem versão do PDF guardada, o `jaAssinado` do detalhe sai nulo.
        return new ApresentacaoDoDocumento(
            self::urls(),
            new IdentityTranslator(),
            new Packages(new Package(new EmptyVersionStrategy())),
            $this->chave,
            $this->createStub(VersaoDoPdfRepository::class),
            new ApresentacaoDaConferencia(new IdentityTranslator()),
        );
    }

    private function pagina(): PaginaDoSignatario
    {
        return new PaginaDoSignatario(self::urls(), new IdentityTranslator(), $this->apresentacao());
    }

    private function link(): SignatarioDoLink
    {
        return new SignatarioDoLink($this->documentos);
    }

    /** O gerador de URL do Symfony com as rotas que a Assinatura monta. */
    private static function urls(): UrlGenerator
    {
        $rotas = new RouteCollection();
        $rotas->add('assinatura_eletronica_publica', new Route('/assinar/{token}'));
        $rotas->add('assinatura_eletronica_publica_assinar', new Route('/assinar/{token}'));
        foreach (['codigo', 'recusa', 'arquivo', 'logo'] as $nome) {
            $rotas->add('assinatura_eletronica_publica_' . $nome, new Route('/assinar/{token}/' . $nome));
        }
        $rotas->add('assinatura_eletronica_publica_rubrica', new Route('/assinar/{token}/rubrica/{ordem}'));
        $rotas->add('assinatura_eletronica_arquivo', new Route('/assinatura/documentos/{id}/arquivo'));
        $rotas->add('assinatura_eletronica_logo', new Route('/assinatura/documentos/{id}/logo'));
        $rotas->add('assinatura_eletronica_rubrica', new Route('/assinatura/documentos/{id}/rubrica/{ordem}'));
        $rotas->add('meu_escritorio_logo', new Route('/meu-escritorio/{tenantId}/logo'));

        return new UrlGenerator($rotas, new RequestContext('', 'GET', 'app.bluejus.test', 'https'));
    }

    /** Um molde mínimo por carta, com o que o teste confere: o link e o código. */
    private static function twig(): Environment
    {
        $moldes = [];
        foreach (TipoDeCarta::cases() as $tipo) {
            $moldes[$tipo->template()] = $tipo->value . ' link={{ link }} codigo={{ codigo }} motivo={{ motivo }}';
        }

        return new Environment(new ArrayLoader($moldes));
    }

    /**
     * Um rascunho do escritório com Ana (com CPF) e Bia, as duas Simples e com e-mail. Ids fixos: documento 1,
     * signatários 11 e 12.
     */
    private function rascunho(bool $emOrdem = false, ?User $criadoPor = null): DocumentoParaAssinar
    {
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($this->tenant, $criadoPor ?? self::usuario('criadora@escritorio.test', 'Carla Criadora'), $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato de honorários',
            'mensagem' => 'Leia com atenção.',
            'emOrdem' => $emOrdem,
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com'],
            ],
        ]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 11 + $i);
        }
        $this->naBase[] = $documento;

        return $documento;
    }

    /** O rascunho enviado às 9h, com o sha256 do token de cada signatário guardado, como o envio grava. */
    private function documentoEnviado(bool $emOrdem = false): DocumentoParaAssinar
    {
        $documento = $this->rascunho($emOrdem);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:00:00'));
        foreach ($documento->getSignatarios() as $signatario) {
            $signatario->definirTokenHash(ChaveDaAssinatura::hashDoToken($this->chave->token($signatario)));
        }

        return $documento;
    }

    /** Simula a troca do segredo depois do envio: o servidor passa a remontar outro token para o mesmo signatário. */
    private function trocarOSegredo(): void
    {
        $this->chave = new ChaveDaAssinatura(self::OUTRO_SEGREDO);
    }

    private static function usuario(string $email, string $nome): User
    {
        return (new User())->setEmail($email)->setFullName($nome);
    }

    private static function evidencia(): EvidenciaDaRequisicao
    {
        return new EvidenciaDaRequisicao('203.0.113.7', 51234, 'Navegador de teste');
    }

    /** @return list<string> os tipos gravados na trilha, na ordem */
    private function tiposGravados(): array
    {
        return array_map(static fn (EventoDaTrilha $e): string => $e->getTipo()->value, $this->gravados);
    }

    private function ultimoGravado(TipoDeEvento $tipo): EventoDaTrilha
    {
        $achados = array_values(array_filter($this->gravados, static fn (EventoDaTrilha $e): bool => $e->getTipo() === $tipo));

        return $achados[\count($achados) - 1] ?? self::fail('nenhum evento ' . $tipo->value);
    }

    private function unicoEmail(): Email
    {
        self::assertCount(1, $this->mailer->enviados);

        return $this->mailer->enviados[0];
    }

    /** O log inteiro (mensagens e contextos) não contém nada do token nem do link. */
    private function assertLogSemToken(string ...$tokens): void
    {
        $log = json_encode($this->logger->registros, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('/assinar/', $log);
        foreach ($tokens as $token) {
            self::assertStringNotContainsString($token, $log);
        }
    }
}
